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
use TheliaCMS\Form\FieldType;
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

    /** How many answers are decoded at a time to match the answers asked for. */
    private const int SCAN_BATCH = 500;

    /**
     * The answers of the form the filters keep. The answers asked for are
     * matched here, once: their values are JSON, which SQL cannot read on every
     * database the module runs on.
     */
    public function search(CmsForm $form, SubmissionFilters $filters): SubmissionSearch
    {
        if ([] === $filters->answers) {
            return new SubmissionSearch($form, $filters);
        }

        $ids = [];

        foreach ($this->decoded($this->query(new SubmissionSearch($form, $filters), null)) as $id => $answers) {
            if ($filters->matches($answers)) {
                $ids[] = $id;
            }
        }

        return new SubmissionSearch($form, $filters, $ids);
    }

    /**
     * @return list<array{id: int, locale: string, email: ?string, status: SubmissionStatus, created_at: string, answers: list<array<string, mixed>>}>
     */
    public function page(SubmissionSearch $search, int $page = 1): array
    {
        $query = $this->query($search, $search->filters->statuses())
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->offset(max(0, $page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE);

        return array_map($this->asArray(...), iterator_to_array($query->find(), false));
    }

    public function count(SubmissionSearch $search): int
    {
        return $this->query($search, $search->filters->statuses())->count();
    }

    /**
     * How many answers each status holds, for every other filter of the list:
     * the tabs say what each one would show.
     *
     * @return array<string, int> by value of the status, every status present
     */
    public function countsByStatus(SubmissionSearch $search): array
    {
        $counts = [];

        foreach (SubmissionStatus::cases() as $status) {
            $counts[$status->value] = $this->query($search, [$status])->count();
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
    public function all(SubmissionSearch $search): array
    {
        $query = $this->query($search, $search->filters->statuses())
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC);

        return array_map($this->asArray(...), iterator_to_array($query->find(), false));
    }

    /**
     * The answers given to the questions with choices, every submission of the
     * form included whatever the list shows: what can be asked for does not
     * change with the period or the tab.
     *
     * @return array<string, array{label: string, type: string, values: list<string>}> by name of the question, in the order first met
     */
    public function choiceAnswers(CmsForm $form): array
    {
        $questions = [];

        foreach ($this->decoded(CmsFormSubmissionQuery::create()->filterByFormId($form->getId())) as $answers) {
            foreach ($answers as $answer) {
                $type = FieldType::tryFrom($answer['type']);
                $value = $answer['value'];

                if (null === $type || !($type->hasChoices() || $type->isDropDown()) || !\is_string($value) || '' === $value) {
                    continue;
                }

                $questions[$answer['name']] ??= ['label' => $answer['label'], 'type' => $type->value, 'values' => []];
                $questions[$answer['name']]['values'][$value] = $value;
            }
        }

        foreach ($questions as $name => $question) {
            $values = array_values($question['values']);
            sort($values, \SORT_NATURAL | \SORT_FLAG_CASE);
            $questions[$name]['values'] = $values;
        }

        return $questions;
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

    /**
     * @param list<SubmissionStatus>|null $statuses null for every status
     */
    private function query(SubmissionSearch $search, ?array $statuses): CmsFormSubmissionQuery
    {
        $filters = $search->filters;
        $query = CmsFormSubmissionQuery::create()->filterByFormId($search->form->getId());

        if (null !== $statuses) {
            $query->filterByStatus(array_map(static fn (SubmissionStatus $status): string => $status->value, $statuses), Criteria::IN);
        }

        if ('' !== $filters->email) {
            // Partial, because whoever answers a request to be forgotten is
            // reading an address off an email and may only have part of it.
            $query->filterByEmail('%'.$filters->email.'%', Criteria::LIKE);
        }

        // Both days included: up to the midnight that follows the last one.
        if (null !== $filters->from) {
            $query->filterByCreatedAt($filters->from->format('Y-m-d 00:00:00'), Criteria::GREATER_EQUAL);
        }

        if (null !== $filters->to) {
            $query->filterByCreatedAt($filters->to->modify('+1 day')->format('Y-m-d 00:00:00'), Criteria::LESS_THAN);
        }

        if (null !== $search->ids) {
            // No answer matched: an empty IN is not valid SQL everywhere.
            $query->filterById([] === $search->ids ? [0] : $search->ids, Criteria::IN);
        }

        return $query;
    }

    /**
     * The decoded answers of the submissions of a query, by id, read a batch at
     * a time on the id: the JSON only, no model kept in memory.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    private function decoded(CmsFormSubmissionQuery $query): \Generator
    {
        $after = 0;

        do {
            $rows = (clone $query)
                ->filterById($after, Criteria::GREATER_THAN)
                ->orderById()
                ->limit(self::SCAN_BATCH)
                ->select(['Id', 'Data'])
                ->find()
                ->getData();

            foreach ($rows as $row) {
                $after = (int) $row['Id'];

                yield $after => SubmissionData::decode($row['Data']);
            }
        } while (self::SCAN_BATCH === \count($rows));
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
