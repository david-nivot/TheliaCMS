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

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Form\SubmissionExport;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * The answers a form has received: reading them, finding the ones belonging to
 * one person, handing them over, and deleting them.
 *
 * Those last two are not conveniences. Someone asking to see or to erase what a
 * site holds about them has to be answered, and a site where that means writing
 * SQL is a site that answers late or not at all.
 */
#[Route('/admin/cms/forms/{id}/submissions', name: 'admin.cms.forms.', requirements: ['id' => '\d+'])]
final readonly class CmsSubmissionAdminController
{
    private const string TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/forms/submissions.html.twig';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private CmsFormRepository $forms,
        private SubmissionRepository $submissions,
        private CmsActivityLog $activityLog,
        private EditLanguage $languages,
    ) {
    }

    #[Route('', name: 'submissions', methods: ['GET'])]
    public function list(Request $request, int $id): Response
    {
        $record = $this->formOrFail($id);
        $lang = $this->languages->resolve($request);
        $email = trim((string) $request->query->get('email', ''));
        $page = max(1, $request->query->getInt('page', 1));

        $total = $this->submissions->count($record, $email);

        return new Response($this->twig->render(self::TEMPLATE, [
            'cms_form' => $record->setLocale($lang->getLocale()),
            'submissions' => $this->submissions->page($record, $email, $page),
            'email' => $email,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / SubmissionRepository::PER_PAGE)),
            'total' => $total,
            'edit_language_id' => $lang->getId(),
            'may_delete' => $this->support->isGranted(CmsResources::FORM_ANSWERS, AccessManager::DELETE),
            'may_export' => $this->support->isGranted(CmsResources::FORM_ANSWERS_EXPORT, AccessManager::VIEW),
            'yes' => $this->support->translate('Yes'),
            'no' => $this->support->translate('No'),
        ]));
    }

    #[Route('/export.{format}', name: 'submissions_export', requirements: ['format' => 'csv|json'], methods: ['GET'])]
    public function export(Request $request, int $id, string $format): Response
    {
        $this->support->denyUnless(CmsResources::FORM_ANSWERS_EXPORT, AccessManager::VIEW, 'You are not allowed to export the answers.');

        $record = $this->formOrFail($id);
        $email = trim((string) $request->query->get('email', ''));
        $rows = $this->submissions->all($record, $email);

        $this->activityLog->record(
            'EXPORT',
            (int) $record->getId(),
            \sprintf('CMS form "%s": %d submission(s) exported as %s', $record->getCode(), \count($rows), $format),
            CmsResources::FORM,
        );

        $body = 'json' === $format
            ? SubmissionExport::toJson($rows)
            : SubmissionExport::toCsv($rows, $this->csvHeadings(), $this->support->translate('Yes'), $this->support->translate('No'));

        $response = new Response($body);
        $response->headers->set('Content-Type', 'json' === $format ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', \sprintf(
            'attachment; filename="%s-submissions.%s"',
            $record->getCode(),
            $format,
        ));
        // Personal data: never in a shared cache, never in the back button.
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    #[Route('/{submissionId}/delete', name: 'submissions_delete', requirements: ['submissionId' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id, int $submissionId): Response
    {
        $this->support->denyUnless(CmsResources::FORM_ANSWERS, AccessManager::DELETE, 'You are not allowed to delete the answers.');

        $record = $this->formOrFail($id);
        $submission = $this->submissions->find($record, $submissionId);

        if (!$submission instanceof CmsFormSubmission) {
            throw new NotFoundHttpException();
        }

        $submission->delete();

        // The address is deliberately not written to the log: erasing an answer
        // and keeping who sent it in another table erases nothing.
        $this->activityLog->record(
            'DELETE',
            (int) $record->getId(),
            \sprintf('CMS form "%s": submission #%d deleted', $record->getCode(), $submissionId),
            CmsResources::FORM,
        );

        return new RedirectResponse($this->urls->generate('admin.cms.forms.submissions', array_filter([
            'id' => $id,
            'email' => trim((string) $request->request->get('email', '')),
            EditLanguage::PARAMETER => $this->languages->resolve($request)->getId(),
        ])));
    }

    /**
     * @return array<string, string>
     */
    private function csvHeadings(): array
    {
        return [
            'id' => $this->support->translate('Reference'),
            'created_at' => $this->support->translate('Received on'),
            'email' => $this->support->translate('Email address'),
            'locale' => $this->support->translate('Language'),
        ];
    }

    private function formOrFail(int $id): CmsForm
    {
        $record = $this->forms->find($id);

        if (!$record instanceof CmsForm) {
            throw new NotFoundHttpException();
        }

        return $record;
    }
}
