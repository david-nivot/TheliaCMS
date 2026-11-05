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

namespace TheliaCMS\Form;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

/**
 * Checks what a visitor sent against the fields the form declares.
 *
 * The browser checks nothing that matters here: a form is posted by whatever
 * sends the request, so `required`, `type="email"` and the options of a
 * drop-down are decoration until the server has said the same thing. A field
 * the form does not declare is dropped rather than reported — it did not come
 * from the form.
 */
final readonly class SubmissionValidator
{
    private const int MAX_TEXT = 255;

    private const int MAX_LONG_TEXT = 5000;

    private const int MIN_PHONE_DIGITS = 6;

    private const int MAX_PHONE_DIGITS = 20;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<Field>          $fields
     * @param array<string, mixed> $input  what was posted
     */
    public function validate(array $fields, array $input): ValidatedSubmission
    {
        $answers = [];
        $errors = [];
        $entered = [];

        foreach ($fields as $field) {
            $raw = $input[$field->name] ?? null;
            $raw = \is_array($raw) || \is_object($raw) ? null : $raw;

            $entered[$field->name] = $field->type->isTickBox() ? $this->ticked($raw) : trim((string) $raw);

            $error = $this->check($field, $raw);

            if (null !== $error) {
                $errors[$field->name] = $error;

                continue;
            }

            $answers[] = new Answer($field->name, $field->type, $field->label, $this->valueOf($field, $raw));
        }

        return new ValidatedSubmission($answers, $errors, $entered);
    }

    private function check(Field $field, mixed $raw): ?string
    {
        if ($field->type->isTickBox()) {
            if ($field->required && !$this->ticked($raw)) {
                return FieldType::Consent === $field->type
                    ? $this->trans('Tick this box to send your message.')
                    : $this->trans('This box has to be ticked.');
            }

            return null;
        }

        $value = trim((string) $raw);

        if ('' === $value) {
            return $field->required ? $this->trans('This field is required.') : null;
        }

        return match ($field->type) {
            FieldType::Email => $this->checkEmail($value),
            FieldType::Phone => $this->checkPhone($value),
            FieldType::Date => $this->checkDate($value),
            FieldType::Select, FieldType::Radio, FieldType::Country => $this->checkChoice($field, $value),
            FieldType::Textarea => mb_strlen($value) > self::MAX_LONG_TEXT
                ? $this->trans('This answer is too long.')
                : null,
            default => mb_strlen($value) > self::MAX_TEXT
                ? $this->trans('This answer is too long.')
                : null,
        };
    }

    private function checkEmail(string $value): ?string
    {
        if (mb_strlen($value) > self::MAX_TEXT || false === filter_var($value, \FILTER_VALIDATE_EMAIL)) {
            return $this->trans('This is not an email address.');
        }

        return null;
    }

    /**
     * Deliberately loose: a phone number is written with spaces, dots, dashes,
     * brackets and a leading plus depending on the country and on the habit of
     * whoever types it. What is checked is that it holds a plausible number of
     * digits and nothing else.
     */
    private function checkPhone(string $value): ?string
    {
        if (1 !== preg_match('/^\+?[0-9 .\-()\/]+$/', $value)) {
            return $this->trans('This is not a phone number.');
        }

        $digits = \strlen((string) preg_replace('/\D/', '', $value));

        if ($digits < self::MIN_PHONE_DIGITS || $digits > self::MAX_PHONE_DIGITS) {
            return $this->trans('This is not a phone number.');
        }

        return null;
    }

    private function checkDate(string $value): ?string
    {
        // The date input posts ISO 8601, and so does any client worth
        // accepting; a locale-dependent format would be ambiguous anyway.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $date || $date->format('Y-m-d') !== $value) {
            return $this->trans('This is not a date.');
        }

        return null;
    }

    private function checkChoice(Field $field, string $value): ?string
    {
        return \in_array($value, $field->choices, true)
            ? null
            : $this->trans('Pick one of the answers offered.');
    }

    private function valueOf(Field $field, mixed $raw): string|bool
    {
        if ($field->type->isTickBox()) {
            return $this->ticked($raw);
        }

        $value = trim((string) $raw);

        return FieldType::Textarea === $field->type
            ? mb_substr($value, 0, self::MAX_LONG_TEXT)
            : mb_substr($value, 0, self::MAX_TEXT);
    }

    /**
     * An unticked box posts nothing at all, so its absence is the answer.
     */
    private function ticked(mixed $raw): bool
    {
        if (null === $raw) {
            return false;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? '' !== trim((string) $raw);
    }

    private function trans(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
