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

use TheliaCMS\Form\FieldType;

/**
 * The answers of a list counted rather than read one by one: how many came in
 * over time, in which status they are, and how the questions with choices were
 * answered. It counts the list the filters keep, so a period or an answer asked
 * for narrows the summary the way it narrows the list.
 */
final readonly class SubmissionSummary
{
    public const string BY_DAY = 'day';
    public const string BY_WEEK = 'week';
    public const string BY_MONTH = 'month';

    /** Up to two months are counted day by day, up to half a year week by week, beyond that month by month. */
    private const int MOST_DAYS = 62;
    private const int MOST_DAYS_BY_WEEK = 183;

    public function __construct(
        private SubmissionRepository $submissions,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     statuses: array<string, int>,
     *     by: string,
     *     periods: list<array{start: \DateTimeImmutable, label: string, count: int}>,
     *     most: int,
     *     questions: list<array{name: string, label: string, answered: int, values: list<array{value: string, count: int}>}>
     * }
     */
    public function of(SubmissionSearch $search, string $locale): array
    {
        $days = $this->submissions->countsByDay($search);
        [$by, $periods] = $this->periods($days, $search->filters, $locale);

        return [
            'total' => array_sum($days),
            'statuses' => $this->submissions->countsByStatus($search),
            'by' => $by,
            'periods' => $periods,
            'most' => [] === $periods ? 0 : max(array_column($periods, 'count')),
            'questions' => $this->questions($search),
        ];
    }

    /**
     * Every period from the first to the last, the empty ones included: a week
     * without an answer is something to see, not a gap to guess. The bounds are
     * the days of the filter when there is one, else the first and the last
     * answer.
     *
     * @param array<string, int> $days
     *
     * @return array{string, list<array{start: \DateTimeImmutable, label: string, count: int}>}
     */
    private function periods(array $days, SubmissionFilters $filters, string $locale): array
    {
        if ([] === $days) {
            return [self::BY_DAY, []];
        }

        $first = $filters->from ?? new \DateTimeImmutable((string) array_key_first($days));
        $last = $filters->to ?? new \DateTimeImmutable((string) array_key_last($days));
        $span = (int) $first->diff($last)->days;
        $by = match (true) {
            $span < self::MOST_DAYS => self::BY_DAY,
            $span < self::MOST_DAYS_BY_WEEK => self::BY_WEEK,
            default => self::BY_MONTH,
        };

        $counts = [];

        foreach ($days as $day => $count) {
            $start = self::startOf(new \DateTimeImmutable((string) $day), $by)->format('Y-m-d');
            $counts[$start] = ($counts[$start] ?? 0) + $count;
        }

        $periods = [];
        $step = match ($by) {
            self::BY_DAY => '+1 day',
            self::BY_WEEK => '+1 week',
            default => '+1 month',
        };

        for ($start = self::startOf($first, $by); $start <= $last; $start = $start->modify($step)) {
            $periods[] = [
                'start' => $start,
                'label' => self::label($start, $by, $locale),
                'count' => $counts[$start->format('Y-m-d')] ?? 0,
            ];
        }

        return [$by, $periods];
    }

    /** The first day of the period holding a day: itself, its Monday, the first of its month. */
    private static function startOf(\DateTimeImmutable $day, string $by): \DateTimeImmutable
    {
        $day = $day->setTime(0, 0);

        return match ($by) {
            self::BY_WEEK => $day->modify('-'.((int) $day->format('N') - 1).' days'),
            self::BY_MONTH => $day->modify('first day of this month'),
            default => $day,
        };
    }

    /** A day, the Monday of a week, or a month written out, in the language of the screen. */
    private static function label(\DateTimeImmutable $start, string $by, string $locale): string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return $start->format(self::BY_MONTH === $by ? 'm/Y' : 'd/m/Y');
        }

        $formatter = self::BY_MONTH === $by
            ? new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, 'LLLL yyyy')
            : new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE);

        return (string) $formatter->format($start);
    }

    /**
     * How each question with choices was answered, the values most given first;
     * a question is named as in the first answer met, like a column of the
     * export.
     *
     * @return list<array{name: string, label: string, answered: int, values: list<array{value: string, count: int}>}>
     */
    private function questions(SubmissionSearch $search): array
    {
        $questions = [];

        foreach ($this->submissions->answersOf($search) as $answers) {
            foreach ($answers as $answer) {
                $type = FieldType::tryFrom((string) $answer['type']);
                $value = $answer['value'];

                if (null === $type || !($type->hasChoices() || $type->isDropDown()) || !\is_string($value) || '' === $value) {
                    continue;
                }

                $name = (string) $answer['name'];
                $questions[$name] ??= ['name' => $name, 'label' => (string) $answer['label'], 'answered' => 0, 'values' => []];
                ++$questions[$name]['answered'];
                $questions[$name]['values'][$value] = ($questions[$name]['values'][$value] ?? 0) + 1;
            }
        }

        foreach ($questions as $name => $question) {
            $values = $question['values'];
            // A value written as a number is an integer key of the array.
            uksort($values, static fn (int|string $a, int|string $b): int => [$values[$b], (string) $a] <=> [$values[$a], (string) $b]);
            $questions[$name]['values'] = array_map(
                static fn (string $value, int $count): array => ['value' => $value, 'count' => $count],
                array_map(strval(...), array_keys($values)),
                array_values($values),
            );
        }

        return array_values($questions);
    }
}
