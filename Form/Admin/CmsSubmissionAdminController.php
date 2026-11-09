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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use TheliaCMS\Form\SubmissionExport;
use TheliaCMS\Form\SubmissionStatus;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use Twig\Environment;

/**
 * The answers a form has received: reading them, finding the ones belonging to
 * one person, giving them a status, handing them over, and deleting them.
 *
 * Those last two are not conveniences. Someone asking to see or to erase what a
 * site holds about them has to be answered, and a site where that means writing
 * SQL is a site that answers late or not at all.
 */
#[Route('/admin/cms/forms/{id}/submissions', name: 'admin.cms.forms.', requirements: ['id' => '\d+'])]
final readonly class CmsSubmissionAdminController
{
    private const string TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/forms/submissions.html.twig';
    private const string SUMMARY_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/forms/summary.html.twig';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private CmsFormRepository $forms,
        private SubmissionRepository $submissions,
        private CmsActivityLog $activityLog,
        private EditLanguage $languages,
        private SubmissionChoices $choices,
        private SubmissionSummary $summary,
    ) {
    }

    #[Route('', name: 'submissions', methods: ['GET'])]
    public function list(Request $request, int $id): Response
    {
        $record = $this->formOrFail($id);
        $lang = $this->languages->resolve($request);
        $filters = SubmissionFilters::fromRequest($request);
        $search = $this->submissions->search($record, $filters);
        $page = max(1, $request->query->getInt('page', 1));

        $total = $this->submissions->count($search);

        return new Response($this->twig->render(self::TEMPLATE, [
            'cms_form' => $record->setLocale($lang->getLocale()),
            'submissions' => $this->submissions->page($search, $page),
            'filters' => $filters,
            'email' => $filters->email,
            'questions' => $this->choices->questions($record, $lang->getLocale(), $filters),
            'status_counts' => $this->submissions->countsByStatus($search),
            'statuses' => SubmissionStatus::cases(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / SubmissionRepository::PER_PAGE)),
            'total' => $total,
            'edit_language_id' => $lang->getId(),
            'may_delete' => $this->support->isGranted(CmsResources::FORM_ANSWERS, AccessManager::DELETE),
            'may_change' => $this->support->isGranted(CmsResources::FORM_ANSWERS, AccessManager::UPDATE),
            'may_export' => $this->support->isGranted(CmsResources::FORM_ANSWERS_EXPORT, AccessManager::VIEW),
            'yes' => $this->support->translate('Yes'),
            'no' => $this->support->translate('No'),
        ]));
    }

    /**
     * The answers of the list counted: over time, by status, by the value of
     * each question with choices. Reading them is all it takes.
     */
    #[Route('/summary', name: 'submissions_summary', methods: ['GET'])]
    public function summary(Request $request, int $id): Response
    {
        $record = $this->formOrFail($id);
        $lang = $this->languages->resolve($request);
        $filters = SubmissionFilters::fromRequest($request);
        $search = $this->submissions->search($record, $filters);
        $summary = $this->summary->of($search, $this->support->screenLocale());

        return new Response($this->twig->render(self::SUMMARY_TEMPLATE, [
            'cms_form' => $record->setLocale($lang->getLocale()),
            'summary' => $summary,
            'filters' => $filters,
            'email' => $filters->email,
            'questions' => $this->choices->questions($record, $lang->getLocale(), $filters),
            'status_counts' => $summary['statuses'],
            'total' => $summary['total'],
            'edit_language_id' => $lang->getId(),
        ]));
    }

    #[Route('/export.{format}', name: 'submissions_export', requirements: ['format' => 'csv|json'], methods: ['GET'])]
    public function export(Request $request, int $id, string $format): Response
    {
        $this->support->denyUnless(CmsResources::FORM_ANSWERS_EXPORT, AccessManager::VIEW, 'You are not allowed to export the answers.');

        $record = $this->formOrFail($id);
        $search = $this->submissions->search($record, SubmissionFilters::fromRequest($request));
        $count = $this->submissions->count($search);

        $this->activityLog->record(
            'EXPORT',
            (int) $record->getId(),
            \sprintf('CMS form "%s": %d submission(s) exported as %s', $record->getCode(), $count, $format),
            CmsResources::FORM_ANSWERS,
        );

        // Written as it is read, a batch at a time: a form with years of
        // answers is exported without holding them all in memory.
        $rows = fn (): \Generator => $this->submissions->each($search);
        $headings = $this->csvHeadings();
        $yes = $this->support->translate('Yes');
        $no = $this->support->translate('No');
        $separator = SubmissionExport::separatorFor($this->support->screenLocale());

        $response = new StreamedResponse(static function () use ($format, $rows, $headings, $yes, $no, $separator): void {
            $output = fopen('php://output', 'w');

            if (false === $output) {
                return;
            }

            'json' === $format
                ? SubmissionExport::writeJson($output, $rows())
                : SubmissionExport::writeCsv($output, $rows, $headings, $yes, $no, $separator);

            fclose($output);
        });
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
            CmsResources::FORM_ANSWERS,
        );

        return $this->backToList($request, $id);
    }

    #[Route('/{submissionId}/status', name: 'submissions_status', requirements: ['submissionId' => '\d+'], methods: ['POST'])]
    public function status(Request $request, int $id, int $submissionId): Response
    {
        $this->support->denyUnless(CmsResources::FORM_ANSWERS, AccessManager::UPDATE, 'You are not allowed to change the answers.');

        $record = $this->formOrFail($id);
        $submission = $this->submissions->find($record, $submissionId);
        $status = SubmissionStatus::tryFrom($request->request->getString('to'));

        if (!$submission instanceof CmsFormSubmission || null === $status) {
            throw new NotFoundHttpException();
        }

        if (1 === $this->submissions->setStatus([$submission], $status)) {
            $this->activityLog->record(
                'UPDATE',
                (int) $record->getId(),
                \sprintf('CMS form "%s": submission #%d marked as %s', $record->getCode(), $submissionId, $status->value),
                CmsResources::FORM_ANSWERS,
            );
        }

        return $this->backToList($request, $id);
    }

    /**
     * A status for every answer selected, or their deletion. One line in the
     * activity log per gesture, with the count and never an address.
     */
    #[Route('/bulk', name: 'submissions_bulk', methods: ['POST'])]
    public function bulk(Request $request, int $id): Response
    {
        $action = SubmissionBulkAction::tryFrom($request->request->getString('action'));

        if (null === $action) {
            $this->support->flash($request, 'warning', $this->support->translate('Choose what to do with the selected answers.'));

            return $this->backToList($request, $id);
        }

        $this->support->denyUnless(
            CmsResources::FORM_ANSWERS,
            $action->access(),
            SubmissionBulkAction::Delete === $action ? 'You are not allowed to delete the answers.' : 'You are not allowed to change the answers.',
        );

        $record = $this->formOrFail($id);
        $selected = $this->submissions->selected($record, array_map(intval(...), $request->request->all('submissions')));

        if ([] === $selected) {
            $this->support->flash($request, 'warning', $this->support->translate('Select at least one answer.'));

            return $this->backToList($request, $id);
        }

        $status = $action->status();

        if (null === $status) {
            foreach ($selected as $submission) {
                $submission->delete();
            }

            $count = \count($selected);
            $log = \sprintf('CMS form "%s": %d submission(s) deleted', $record->getCode(), $count);
            $message = $this->support->translate('%count% answer(s) deleted.', ['%count%' => $count]);
        } else {
            $count = $this->submissions->setStatus($selected, $status);
            $log = \sprintf('CMS form "%s": %d submission(s) marked as %s', $record->getCode(), $count, $status->value);
            $message = $this->support->translate('%count% answer(s) marked as “%status%”.', [
                '%count%' => $count,
                '%status%' => $this->support->translate($status->label()),
            ]);
        }

        if ($count > 0) {
            $this->activityLog->record(null === $status ? 'DELETE' : 'UPDATE', (int) $record->getId(), $log, CmsResources::FORM_ANSWERS);
        }

        $this->support->flash($request, 0 === $count ? 'info' : 'success', $message);

        return $this->backToList($request, $id);
    }

    /**
     * Back to the list the gesture was made on: its filters and its page travel
     * in the form, like the language of the screen.
     */
    private function backToList(Request $request, int $id): RedirectResponse
    {
        $page = $request->request->getInt('page');

        return new RedirectResponse($this->urls->generate('admin.cms.forms.submissions', [
            'id' => $id,
            ...SubmissionFilters::fromRequest($request)->parameters(),
            ...($page > 1 ? ['page' => $page] : []),
            EditLanguage::PARAMETER => $this->languages->resolve($request)->getId(),
        ]));
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
