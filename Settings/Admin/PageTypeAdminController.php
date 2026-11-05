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

namespace TheliaCMS\Settings\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Page\Field\FieldDefinition;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\Page\PageTemplateResolver;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeDetails;
use TheliaCMS\Page\PageTypeInUseException;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * The page types of the site, the template each one is displayed with, and
 * each one as a template of pages: its name, its brief, the fields of its
 * pages and its model (docs/adr/0009).
 *
 * Under /admin/cms/settings, so CmsAdminGuard holds it to the settings
 * permission like the rest of the section.
 */
#[Route('/admin/cms/settings/page-types', name: 'admin.cms.settings.page_types.')]
final readonly class PageTypeAdminController
{
    private const string TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/settings/page-types.html.twig';

    private const string EDIT_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/settings/page-type-edit.html.twig';

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private TranslatorInterface $translator,
        private PageTypeRepository $pageTypes,
        private PageTypeWriter $writer,
        private PageTemplateResolver $templates,
        private EditLanguage $languages,
        private CmsPageWriter $pages,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET', 'POST'])]
    public function list(Request $request): Response
    {
        $form = $this->forms->create(PageTypeCreateType::class, null, [
            'action' => $this->urls->generate('admin.cms.settings.page_types.list'),
        ]);

        $form->handleRequest($request);

        // The right is checked on any submission, before the form is read: a
        // profile that may only look gets a refusal, not a validation error.
        if ($form->isSubmitted()) {
            $this->denyUnless(AccessManager::CREATE);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $added = $this->add($request, $form);

            if (null !== $added) {
                return $added;
            }
        }

        $rows = [];
        $pageCounts = $this->pageTypes->pageCountsByType();
        $models = $this->pageTypes->modelIds();
        $locale = $this->languages->resolve($request)->getLocale();

        foreach ($this->pageTypes->allDetails() as $code => $details) {
            $rows[] = [
                'code' => $code,
                'title' => $details->title($locale),
                'fields' => \count($details->fields()),
                'has_brief' => null !== $details->brief(),
                'has_model' => isset($models[$code]),
                'template' => $this->templates->resolve($code),
                'pages' => $pageCounts[$code] ?? 0,
                'deletable' => PageTypeCode::isDeletable($code),
            ];
        }

        return new Response($this->twig->render(self::TEMPLATE, [
            'types' => $rows,
            'form' => $form->createView(),
            'may_create' => $this->support->isGranted(CmsResources::SETTINGS, AccessManager::CREATE),
            'may_delete' => $this->support->isGranted(CmsResources::SETTINGS, AccessManager::DELETE),
        ]));
    }

    #[Route('/{code}/delete', name: 'delete', requirements: ['code' => PageTypeCode::ROUTE_REQUIREMENT], methods: ['POST'])]
    public function delete(Request $request, string $code): Response
    {
        $this->denyUnless(AccessManager::DELETE);

        if (!PageTypeCode::isDeletable($code) || !$this->pageTypes->exists($code)) {
            throw new NotFoundHttpException();
        }

        try {
            $this->writer->delete($code);
        } catch (PageTypeInUseException $exception) {
            $this->support->flash($request, 'danger', $this->support->translate(
                'The type "%code%" is still used by %count% page(s), the bin included. Give them another type first.',
                ['%code%' => $code, '%count%' => $exception->pages],
            ));

            return $this->backToList();
        }

        $this->support->flash($request, 'success', $this->support->translate('The page template has been deleted.'));

        return $this->backToList();
    }

    /**
     * The type as a template: its name, description and brief on the left,
     * its fields and its model beside.
     */
    #[Route('/{code}', name: 'edit', requirements: ['code' => PageTypeCode::ROUTE_REQUIREMENT], methods: ['GET', 'POST'])]
    public function edit(Request $request, string $code): Response
    {
        $details = $this->typeOrFail($code);
        $lang = $this->languages->resolve($request);
        $form = $this->settingsForm($details, $lang);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyUnless(AccessManager::UPDATE);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $text = trim((string) ($data['briefText'] ?? ''));

            $this->writer->describe(
                $code,
                $lang->getLocale(),
                $data['title'] ?? null,
                $data['description'] ?? null,
                '' === $text ? null : new Brief($text, self::blankToNull($data['briefTone'] ?? null), self::blankToNull($data['briefAudience'] ?? null)),
                instructions: $data['instructions'] ?? null,
            );
            $this->support->flash($request, 'success', $this->support->translate('The page template is saved.'));

            return $this->backToEdit($code, $lang);
        }

        return $this->screen($details, $lang, $form, $this->fieldForm($details, $lang, null), null);
    }

    /**
     * Adds a field, or saves the one being edited. Both live on the screen of
     * the type, so both come back to it.
     */
    #[Route('/{code}/fields/{field}', name: 'field_save', requirements: ['code' => PageTypeCode::ROUTE_REQUIREMENT, 'field' => '[a-z][a-z0-9_]*'], methods: ['GET', 'POST'])]
    public function saveField(Request $request, string $code, string $field): Response
    {
        $details = $this->typeOrFail($code);
        $lang = $this->languages->resolve($request);
        $existing = 'new' === $field ? null : ($details->fields()->find($field) ?? throw new NotFoundHttpException());

        $form = $this->fieldForm($details, $lang, $existing);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyUnless(AccessManager::UPDATE);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->writer->saveField($code, $existing?->code, $lang->getLocale(), PageTypeFieldType::definition((array) $form->getData(), $existing?->code));

                return $this->backToEdit($code, $lang);
            } catch (InvalidFieldsException $exception) {
                foreach ($exception->translated($this->translator) as $problem) {
                    $form->addError(new FormError($problem));
                }
            }
        }

        return $this->screen($details, $lang, $this->settingsForm($details, $lang), $form, $existing);
    }

    #[Route('/{code}/fields/{field}/delete', name: 'field_delete', requirements: ['code' => PageTypeCode::ROUTE_REQUIREMENT, 'field' => '[a-z][a-z0-9_]*'], methods: ['POST'])]
    public function deleteField(Request $request, string $code, string $field): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $details = $this->typeOrFail($code);

        if (null === $details->fields()->find($field)) {
            throw new NotFoundHttpException();
        }

        $this->writer->deleteField($code, $field);

        return $this->backToEdit($code, $this->languages->resolve($request));
    }

    #[Route('/{code}/fields/{field}/move/{direction}', name: 'field_move', requirements: ['code' => PageTypeCode::ROUTE_REQUIREMENT, 'field' => '[a-z][a-z0-9_]*', 'direction' => 'up|down'], methods: ['POST'])]
    public function moveField(Request $request, string $code, string $field, string $direction): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $this->typeOrFail($code);

        $this->writer->moveField($code, $field, 'up' === $direction ? -1 : 1);

        return $this->backToEdit($code, $this->languages->resolve($request));
    }

    /**
     * Creates the model of the type and opens its content, where the demo
     * is written like any page: from a brief, or by hand.
     */
    #[Route('/{code}/model', name: 'model', requirements: ['code' => PageTypeCode::ROUTE_REQUIREMENT], methods: ['POST'])]
    public function createModel(Request $request, string $code): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $details = $this->typeOrFail($code);
        $lang = $this->languages->resolve($request);

        $model = $this->pageTypes->modelOf($code) ?? $this->pages->createModel(
            $code,
            $lang->getLocale(),
            $this->support->translate('%type% (model)', ['%type%' => $details->title($lang->getLocale())]),
        );

        return new RedirectResponse($this->urls->generate('admin.cms.pages.content.edit', [
            'id' => $model->getId(),
            EditLanguage::PARAMETER => $lang->getId(),
        ]));
    }

    private function screen(PageTypeDetails $details, Lang $lang, FormInterface $settingsForm, FormInterface $fieldForm, ?FieldDefinition $editedField): Response
    {
        $locale = $lang->getLocale();
        $fields = $details->fields()->fields;
        $rows = [];

        foreach ($fields as $index => $field) {
            $rows[] = [
                'field' => $field,
                'label' => $field->label($locale),
                'has_label' => isset($field->labels[$locale]),
                'can_move_up' => $index > 0,
                'can_move_down' => $index < \count($fields) - 1,
            ];
        }

        $model = $this->pageTypes->modelOf($details->code);

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'type' => $details,
            'type_title' => $details->title($locale),
            'rows' => $rows,
            'settings_form' => $settingsForm->createView(),
            'field_form' => $fieldForm->createView(),
            'edited_field' => $editedField,
            'model' => $model,
            'pages' => $this->pageTypes->countPagesOfType($details->code),
            'template' => $this->templates->resolve($details->code),
            'edit_locale' => $locale,
            'edit_language_id' => $lang->getId(),
            'may_update' => $this->support->isGranted(CmsResources::SETTINGS, AccessManager::UPDATE),
        ]));
    }

    private function settingsForm(PageTypeDetails $details, Lang $lang): FormInterface
    {
        $locale = $lang->getLocale();

        return $this->forms->create(PageTypeEditType::class, [
            'title' => $details->titles[$locale] ?? null,
            'description' => $details->descriptions[$locale] ?? null,
            'briefText' => $details->briefText,
            'briefTone' => $details->briefTone,
            'briefAudience' => $details->briefAudience,
            'instructions' => $details->instructions,
        ], [
            'action' => $this->urls->generate('admin.cms.settings.page_types.edit', ['code' => $details->code, EditLanguage::PARAMETER => $lang->getId()]),
        ]);
    }

    private function fieldForm(PageTypeDetails $details, Lang $lang, ?FieldDefinition $field): FormInterface
    {
        return $this->forms->create(PageTypeFieldType::class, null === $field ? ['kind' => 'text'] : [
            'kind' => $field->kind->value,
            'label' => $field->labels[$lang->getLocale()] ?? null,
            'code' => $field->code,
            'unit' => $field->unit,
            'choices' => implode("\n", $field->choices),
            'min' => null === $field->min ? null : (string) $field->min,
            'max' => null === $field->max ? null : (string) $field->max,
            'translated' => $field->translated,
            'required' => $field->required,
        ], [
            'existing' => null !== $field,
            'action' => $this->urls->generate('admin.cms.settings.page_types.field_save', [
                'code' => $details->code,
                'field' => $field->code ?? 'new',
                EditLanguage::PARAMETER => $lang->getId(),
            ]),
        ]);
    }

    private function typeOrFail(string $code): PageTypeDetails
    {
        if (!$this->pageTypes->exists($code)) {
            throw new NotFoundHttpException();
        }

        return $this->pageTypes->details($code);
    }

    private function backToEdit(string $code, Lang $lang): RedirectResponse
    {
        return $this->support->redirectInLanguage('admin.cms.settings.page_types.edit', $lang->getId(), ['code' => $code]);
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    private function add(Request $request, FormInterface $form): ?RedirectResponse
    {
        $code = (string) $form->get('code')->getData();

        if ($this->pageTypes->exists($code)) {
            $form->get('code')->addError(new FormError($this->support->translate('This type exists already.')));

            return null;
        }

        $this->writer->add($code);
        $this->support->flash($request, 'success', $this->support->translate('The page template has been added.'));

        return $this->backToList();
    }

    private function backToList(): RedirectResponse
    {
        return $this->support->redirect('admin.cms.settings.page_types.list');
    }

    private function denyUnless(string $access): void
    {
        $this->support->denyUnless(CmsResources::SETTINGS, $access, 'You are not allowed to change these settings.');
    }
}
