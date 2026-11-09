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

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Form\SubmissionData;
use TheliaCMS\Form\SubmissionStatus;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;
use TheliaCMS\Model\CmsFormSubmissionQuery;

/**
 * Reads the submissions of a form, narrowed by the filters of the list, and
 * changes their status.
 */
final readonly class SubmissionRepository
{
    public const int PER_PAGE = 25;

    /**
     * @return list<array{id: int, locale: string, email: ?string, status: SubmissionStatus, created_at: string, answers: list<array<string, mixed>>}>
     */
    public function page(CmsForm $form, SubmissionFilters $filters, int $page = 1): array
    {
        $query = $this->search($form, $filters)
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->offset(max(0, $page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE);

        return array_map($this->asArray(...), iterator_to_array($query->find(), false));
    }

    public function count(CmsForm $form, SubmissionFilters $filters): int
    {
        return $this->search($form, $filters)->count();
    }

    /**
     * How many answers each status holds, for the address searched: the tabs
     * say what each one would show.
     *
     * @return array<string, int> by value of the status, every status present
     */
    public function countsByStatus(CmsForm $form, string $email): array
    {
        $counts = array_fill_keys(array_map(static fn (SubmissionStatus $status): string => $status->value, SubmissionStatus::cases()), 0);

        foreach (SubmissionStatus::cases() as $status) {
            $counts[$status->value] = $this->search($form, new SubmissionFilters($email, $status))->count();
        }

        return $counts;
    }

    /**
     * Every submission matching the search, for the export — not one page of
     * them: the point of the export is to hand over everything about a person
     * in one go.
     *
     * @return list<array{id: int, locale: string, email: ?string, status: SubmissionStatus, created_at: string, answers: list<array<string, mixed>>}>
     */
    public function all(CmsForm $form, SubmissionFilters $filters): array
    {
        $query = $this->search($form, $filters)
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC);

        return array_map($this->asArray(...), iterator_to_array($query->find(), false));
    }

    public function find(CmsForm $form, int $id): ?CmsFormSubmission
    {
        $submission = CmsFormSubmissionQuery::create()
            ->filterByFormId($form->getId())
            ->findPk($id);

        return $submission instanceof CmsFormSubmission ? $submission : null;
    }

    /**
     * The submissions of the form among the given ones.
     *
     * @param list<int> $ids
     *
     * @return list<CmsFormSubmission>
     */
    public function selected(CmsForm $form, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return iterator_to_array(CmsFormSubmissionQuery::create()
            ->filterByFormId($form->getId())
            ->filterById(array_values(array_unique($ids)), Criteria::IN)
            ->find(), false);
    }

    /**
     * @param list<CmsFormSubmission> $submissions
     *
     * @return int how many changed: those already so are left alone
     */
    public function setStatus(array $submissions, SubmissionStatus $status): int
    {
        $changed = 0;

        foreach ($submissions as $submission) {
            if ($status->value === $submission->getStatus()) {
                continue;
            }

            $submission->setStatus($status->value)->save();
            ++$changed;
        }

        return $changed;
    }

    private function search(CmsForm $form, SubmissionFilters $filters): CmsFormSubmissionQuery
    {
        $query = CmsFormSubmissionQuery::create()
            ->filterByFormId($form->getId())
            ->filterByStatus(array_map(static fn (SubmissionStatus $status): string => $status->value, $filters->statuses()), Criteria::IN);

        $email = $filters->email;

        if ('' !== $email) {
            // Partial, because whoever answers a request to be forgotten is
            // reading an address off an email and may only have part of it.
            $query->filterByEmail('%'.$email.'%', Criteria::LIKE);
        }

        return $query;
    }

    /**
     * @return array{id: int, locale: string, email: ?string, status: SubmissionStatus, created_at: string, answers: list<array<string, mixed>>}
     */
    private function asArray(CmsFormSubmission $submission): array
    {
        return [
            'id' => (int) $submission->getId(),
            'locale' => (string) $submission->getLocale(),
            'email' => $submission->getEmail(),
            // A value written by hand in the table reads as a new answer.
            'status' => SubmissionStatus::tryFrom((string) $submission->getStatus()) ?? SubmissionStatus::New,
            'created_at' => $submission->getCreatedAt('Y-m-d H:i:s') ?? '',
            'answers' => SubmissionData::decode($submission->getData()),
        ];
    }
}
