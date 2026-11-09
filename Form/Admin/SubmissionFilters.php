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

use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Form\SubmissionStatus;

/**
 * What the list of the answers is narrowed to, read from its address: the
 * screen, the export and the actions on a selection read the same one, so what
 * is exported or changed is what was shown.
 *
 * With no status asked, the list shows the answers still in use: new and
 * handled. The archived ones are one tab away, and "all" shows every answer,
 * which is what answering a request to see one's data needs.
 *
 * The period is the day the answer arrived, both days included. An answer asked
 * for is the value given to a question with choices, as it was written when the
 * visitor picked it: a choice renamed since then no longer finds its old
 * answers.
 */
final readonly class SubmissionFilters
{
    public const string EVERY_STATUS = 'all';
    public const string EMAIL = 'email';
    public const string STATUS = 'status';
    public const string FROM = 'from';
    public const string TO = 'to';
    public const string ANSWER = 'answer';

    /** How a day is written in the address: the value of an `<input type="date">`. */
    public const string DAY_FORMAT = 'Y-m-d';

    /**
     * @param \DateTimeImmutable|null $from    the first day kept, at midnight
     * @param \DateTimeImmutable|null $to      the last day kept, at midnight
     * @param array<string, string>   $answers the value asked for, by name of the question
     */
    public function __construct(
        public string $email = '',
        public ?SubmissionStatus $status = null,
        public bool $everyStatus = false,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public array $answers = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $asked = $request->isMethod('POST') ? $request->request : $request->query;
        $status = $asked->getString(self::STATUS);
        $from = self::readDay($asked->getString(self::FROM));
        $to = self::readDay($asked->getString(self::TO));

        if (null !== $from && null !== $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            email: trim($asked->getString(self::EMAIL)),
            status: SubmissionStatus::tryFrom($status),
            everyStatus: self::EVERY_STATUS === $status,
            from: $from,
            to: $to,
            answers: self::readAnswers($asked->all()[self::ANSWER] ?? []),
        );
    }

    /**
     * The statuses the list holds.
     *
     * @return list<SubmissionStatus>
     */
    public function statuses(): array
    {
        if ($this->everyStatus) {
            return SubmissionStatus::cases();
        }

        return null === $this->status ? [SubmissionStatus::New, SubmissionStatus::Handled] : [$this->status];
    }

    /** The value of the status in the address, empty for the default list. */
    public function statusParameter(): string
    {
        return $this->everyStatus ? self::EVERY_STATUS : ($this->status->value ?? '');
    }

    /** Whether the list is narrowed by more than its status tab. */
    public function isNarrowed(): bool
    {
        return '' !== $this->email || null !== $this->from || null !== $this->to || [] !== $this->answers;
    }

    /**
     * Whether an answer given matches the answers asked for: every question
     * asked, with the very value asked. A tick box or an answer missing matches
     * nothing.
     *
     * @param list<array<string, mixed>> $answers the answers of one submission, decoded
     */
    public function matches(array $answers): bool
    {
        foreach ($this->answers as $name => $value) {
            $found = false;

            foreach ($answers as $answer) {
                if (($answer['name'] ?? null) === $name && ($answer['value'] ?? null) === $value) {
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * The address of the list, without what is left at its default.
     *
     * @return array<string, string|array<string, string>>
     */
    public function parameters(): array
    {
        return $this->onStatus($this->statusParameter());
    }

    /**
     * The same list on another status tab.
     *
     * @return array<string, string|array<string, string>>
     */
    public function onStatus(string $status): array
    {
        $parameters = array_filter([
            self::EMAIL => $this->email,
            self::STATUS => $status,
            self::FROM => $this->from?->format(self::DAY_FORMAT) ?? '',
            self::TO => $this->to?->format(self::DAY_FORMAT) ?? '',
        ], static fn (string $value): bool => '' !== $value);

        if ([] !== $this->answers) {
            $parameters[self::ANSWER] = $this->answers;
        }

        return $parameters;
    }

    /**
     * The same list without one of its filters: the address, the period, or the
     * answer asked to one question.
     *
     * @return array<string, string|array<string, string>>
     */
    public function without(string $filter, string $question = ''): array
    {
        return (new self(
            email: self::EMAIL === $filter ? '' : $this->email,
            status: $this->status,
            everyStatus: $this->everyStatus,
            from: self::FROM === $filter ? null : $this->from,
            to: self::FROM === $filter ? null : $this->to,
            answers: self::ANSWER === $filter ? array_diff_key($this->answers, [$question => true]) : $this->answers,
        ))->parameters();
    }

    /**
     * The list on its status tab, every other filter dropped.
     *
     * @return array<string, string>
     */
    public function cleared(): array
    {
        return array_filter([self::STATUS => $this->statusParameter()], static fn (string $value): bool => '' !== $value);
    }

    /**
     * The address of the list as the fields of a form, the answers asked for
     * written as `answer[name]`: a gesture on the list carries it back.
     *
     * @return list<array{name: string, value: string}>
     */
    public function hiddenFields(): array
    {
        $fields = [];

        foreach ($this->parameters() as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $question => $answer) {
                    $fields[] = ['name' => $name.'['.$question.']', 'value' => $answer];
                }

                continue;
            }

            $fields[] = ['name' => $name, 'value' => $value];
        }

        return $fields;
    }

    /**
     * A day of the calendar, written as an `<input type="date">` sends it;
     * anything else, the 31st of February included, is no day.
     */
    private static function readDay(string $raw): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!'.self::DAY_FORMAT, $raw);

        return false !== $day && $day->format(self::DAY_FORMAT) === $raw ? $day : null;
    }

    /**
     * The answers asked for, by name of the question; "any" (empty) asks for
     * nothing.
     *
     * @return array<string, string>
     */
    private static function readAnswers(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $answers = [];

        foreach ($raw as $name => $value) {
            if (\is_string($name) && '' !== $name && \is_string($value) && '' !== $value) {
                $answers[$name] = $value;
            }
        }

        ksort($answers);

        return $answers;
    }
}
