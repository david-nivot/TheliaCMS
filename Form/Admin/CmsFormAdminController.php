<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TheliaCMS\Form\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Form\FieldChoices;
use TheliaCMS\Form\FieldOptions;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Form\RetentionPolicy;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormField;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * Form back office: the list of forms, and one screen per form holding its
 * fields and the form of a single field.
 *
 * Same shape as the menu screens — resolve the input, hand every write to the
 * writer, redirect.
 */
#[Route('/admin/cms/forms', name: 'admin.cms.forms.')]
final readonly class CmsFormAdminController
{
    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/forms/list.html.twig';
    private const string EDIT_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/forms/edit.html.twig';

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private CmsFormRepository $repository,
        private CmsFormWriter $writer,
        private CmsPageAdminRepository $pages,
        private EditLanguage $languages,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $lang = $this->languages->resolve($request);

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'forms' => $this->repository->forms($lang->getLocale()),
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
            'may_write' => $this->mayWrite(AccessManager::CREATE),
        ]));
    }

    #[Route('/new', name: 'create', methods: ['GET', 'POST'], priority: 1)]
    public function create(Request $request): Response
    {
        $this->denyUnless(AccessManager::CREATE);

        $lang = $this->languages->resolve($request);
        $form = $this->settingsForm(null, $lang);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $record = new CmsForm();

            if ($this->saveSettings($form, $record, $lang)) {
                return $this->backToEdit((int) $record->getId(), $lang);
            }
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'cms_form' => null,
            'settings_form' => $form->createView(),
            'rows' => [],
            'field_form' => null,
            'edited_field' => null,
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
            'may_write' => true,
            'submission_count' => 0,
        ]));
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $record = $this->formOrFail($id);
        $lang = $this->languages->resolve($request);
        $form = $this->settingsForm($record, $lang);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveSettings($form, $record, $lang)) {
            return $this->backToEdit($id, $lang);
        }

        return $this->screen($record, $lang, $form);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::DELETE);

        $this->writer->delete($this->formOrFail($id));

        return $this->support->redirectInLanguage('admin.cms.forms.list', $this->languages->resolve($request)->getId());
    }

    /**
     * Adds a field, or saves the one being edited. Both live on the form screen,
     * so both come back to it.
     */
    #[Route('/{id}/fields/{fieldId}', name: 'field_save', requirements: ['id' => '\d+', 'fieldId' => '\d+|new'], methods: ['GET', 'POST'])]
    public function saveField(Request $request, int $id, string $fieldId): Response
    {
        $record = $this->formOrFail($id);
        $lang = $this->languages->resolve($request);
        $field = 'new' === $fieldId ? null : $this->fieldOrFail($record, (int) $fieldId);

        $form = $this->fieldForm($record, $lang, $field);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyUnless(null === $field ? AccessManager::CREATE : AccessManager::UPDATE);

            try {
                $this->writer->saveField($record, $field, $lang->getLocale(), $this->fieldData($form));

                return $this->backToEdit($id, $lang);
            } catch (\DomainException $exception) {
                $form->get('name')->addError(new FormError($this->support->translate($exception->getMessage())));
            }
        }

        return $this->screen($record, $lang, null, $form, $field);
    }

    #[Route('/{id}/fields/{fieldId}/delete', name: 'field_delete', requirements: ['id' => '\d+', 'fieldId' => '\d+'], methods: ['POST'])]
    public function deleteField(Request $request, int $id, int $fieldId): Response
    {
        $this->denyUnless(AccessManager::DELETE);

        $record = $this->formOrFail($id);
        $this->writer->deleteField($record, $this->fieldOrFail($record, $fieldId));

        return $this->backToEdit($id, $this->languages->resolve($request));
    }

    #[Route('/{id}/fields/{fieldId}/move/{direction}', name: 'field_move', requirements: ['id' => '\d+', 'fieldId' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function moveField(Request $request, int $id, int $fieldId, string $direction): Response
    {
        $this->denyUnless(AccessManager::UPDATE);

        $record = $this->formOrFail($id);
        $this->writer->moveField($record, $this->fieldOrFail($record, $fieldId), 'up' === $direction ? -1 : 1);

        return $this->backToEdit($id, $this->languages->resolve($request));
    }

    private function screen(
        CmsForm $record,
        Lang $lang,
        ?FormInterface $settingsForm = null,
        ?FormInterface $fieldForm = null,
        ?CmsFormField $editedField = null,
    ): Response {
        $locale = $lang->getLocale();
        $rows = $this->repository->fieldRows($record, $locale);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'cms_form' => $record,
            'settings_form' => ($settingsForm ?? $this->settingsForm($record, $lang))->createView(),
            'rows' => $rows,
            'field_form' => ($fieldForm ?? $this->fieldForm($record, $lang, null))->createView(),
            'edited_field' => $editedField,
            'edit_locale' => $locale,
            'edit_language_id' => $lang->getId(),
            'may_write' => $this->mayWrite(AccessManager::UPDATE),
            'submission_count' => $this->repository->submissionCount($record),
        ]));
    }

    private function settingsForm(?CmsForm $record, Lang $lang): FormInterface
    {
        $locale = $lang->getLocale();
        $record?->setLocale($locale);

        return $this->forms->create(CmsFormType::class, [
            'code' => $record?->getCode(),
            'active' => null === $record || 1 === (int) $record->getActive(),
            'recipients' => $record?->getRecipients(),
            'storeSubmissions' => null === $record || 1 === (int) $record->getStoreSubmissions(),
            'retentionDays' => (int) ($record?->getRetentionDays() ?? RetentionPolicy::DEFAULT_DAYS),
            'sendReceipt' => 1 === (int) $record?->getSendReceipt(),
            'privacyPolicyPageId' => $record?->getPrivacyPolicyPageId(),
            'leadEvent' => null === $record || 1 === (int) $record->getLeadEvent(),
            'title' => $record?->getTitle(),
            'description' => $record?->getDescription(),
            'submitLabel' => $record?->getSubmitLabel(),
            'successMessage' => $record?->getSuccessMessage(),
            'legalNotice' => $record?->getLegalNotice(),
            'receiptSubject' => $record?->getReceiptSubject(),
            'receiptBody' => $record?->getReceiptBody(),
        ], [
            'page_choices' => $this->pages->parentChoices($locale),
            // Explicit: this form is also rendered on the screen editing a
            // single field, whose URL it must not post to.
            'action' => null === $record
                ? $this->urls->generate('admin.cms.forms.create', [EditLanguage::PARAMETER => $lang->getId()])
                : $this->urls->generate('admin.cms.forms.edit', ['id' => $record->getId(), EditLanguage::PARAMETER => $lang->getId()]),
        ]);
    }

    private function fieldForm(CmsForm $record, Lang $lang, ?CmsFormField $field): FormInterface
    {
        $locale = $lang->getLocale();
        $field?->setLocale($locale);
        $options = FieldOptions::decode($field?->getOptions());

        return $this->forms->create(CmsFormFieldType::class, [
            'type' => FieldType::fromStorage($field?->getType())->value,
            'name' => $field?->getName(),
            'label' => $field?->getLabel(),
            'required' => 1 === (int) $field?->getRequired(),
            'placeholder' => $field?->getPlaceholder(),
            'help' => $field?->getHelp(),
            'choices' => $field?->getChoices(),
            'rows' => $options->rows,
        ], [
            'action' => $this->urls->generate('admin.cms.forms.field_save', [
                'id' => $record->getId(),
                'fieldId' => $field?->getId() ?? 'new',
                EditLanguage::PARAMETER => $lang->getId(),
            ]),
        ]);
    }

    private function fieldData(FormInterface $form): FieldData
    {
        $data = $form->getData();
        $type = FieldType::fromStorage($data['type']);

        return new FieldData(
            type: $type,
            name: trim((string) $data['name']),
            label: trim((string) $data['label']),
            required: (bool) $data['required'],
            placeholder: trim((string) $data['placeholder']),
            help: trim((string) $data['help']),
            choices: FieldChoices::toText(FieldChoices::parse($data['choices'])),
            rows: (int) $data['rows'],
        );
    }

    private function saveSettings(FormInterface $form, CmsForm $record, Lang $lang): bool
    {
        $this->denyUnless($record->isNew() ? AccessManager::CREATE : AccessManager::UPDATE);

        $data = $form->getData();

        try {
            $this->writer->save($record, $lang->getLocale(), new FormSettings(
                code: (string) $data['code'],
                active: (bool) $data['active'],
                recipients: trim((string) $data['recipients']),
                storeSubmissions: (bool) $data['storeSubmissions'],
                retentionDays: (int) $data['retentionDays'],
                sendReceipt: (bool) $data['sendReceipt'],
                privacyPolicyPageId: null === $data['privacyPolicyPageId'] ? null : (int) $data['privacyPolicyPageId'],
                leadEvent: (bool) $data['leadEvent'],
                title: trim((string) $data['title']),
                description: trim((string) $data['description']),
                submitLabel: trim((string) $data['submitLabel']),
                successMessage: trim((string) $data['successMessage']),
                legalNotice: trim((string) $data['legalNotice']),
                receiptSubject: trim((string) $data['receiptSubject']),
                receiptBody: trim((string) $data['receiptBody']),
            ));
        } catch (\DomainException $exception) {
            $form->get('code')->addError(new FormError($this->support->translate($exception->getMessage())));

            return false;
        }

        return true;
    }

    private function formOrFail(int $id): CmsForm
    {
        $record = $this->repository->find($id);

        if (!$record instanceof CmsForm) {
            throw new NotFoundHttpException();
        }

        return $record;
    }

    private function fieldOrFail(CmsForm $record, int $fieldId): CmsFormField
    {
        $field = $this->repository->findField($record, $fieldId);

        if (!$field instanceof CmsFormField) {
            throw new NotFoundHttpException();
        }

        return $field;
    }

    private function mayWrite(string $access): bool
    {
        return $this->support->isGranted(CmsResources::FORM, $access);
    }

    private function denyUnless(string $access): void
    {
        $this->support->denyUnless(CmsResources::FORM, $access, 'You are not allowed to change forms.');
    }

    private function backToEdit(int $id, Lang $lang): RedirectResponse
    {
        return $this->support->redirectInLanguage('admin.cms.forms.edit', $lang->getId(), ['id' => $id]);
    }
}
