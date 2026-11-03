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

namespace TheliaCMS\Page\Field;

use TheliaCMS\Locale\TranslatableText;

/**
 * One field the pages of a type carry: "servings", a whole number; "cooking
 * time", a duration. Its code is what a template and a list read it by, its
 * label what an editor and a visitor see.
 *
 * Built from the back office, an import file or the column of the type, so
 * every way in goes through fromArray(), which says everything wrong at once.
 */
final readonly class FieldDefinition
{
    public const string CODE_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    public const int CODE_MAX_LENGTH = 40;

    public const int TEXT_MAX_LENGTH = 255;

    public const int LONG_TEXT_MAX_LENGTH = 5000;

    public const int UNIT_MAX_LENGTH = 20;

    /**
     * @param array<string, string> $labels  by locale
     * @param list<string>          $choices the words a choice field takes, shown as they are in every language
     */
    public function __construct(
        public string $code,
        public FieldKind $kind,
        public array $labels,
        public ?string $unit = null,
        public array $choices = [],
        public bool $translated = false,
        public bool $required = false,
        public int|float|null $min = null,
        public int|float|null $max = null,
    ) {
    }

    /**
     * @param array<mixed> $field
     *
     * @throws InvalidFieldsException
     */
    public static function fromArray(array $field): self
    {
        $problems = [];
        $code = \is_string($field['code'] ?? null) ? trim($field['code']) : '';

        if (!self::isValidCode($code)) {
            $problems[] = TranslatableText::of('"%s" is not a field code: lowercase letters, digits and underscores, starting with a letter, %d characters at most.', $code, self::CODE_MAX_LENGTH);
        }

        $kind = FieldKind::tryFrom(\is_string($field['kind'] ?? null) ? $field['kind'] : '');

        if (null === $kind) {
            $problems[] = TranslatableText::of('Field "%s": "%s" is not a kind of field.', $code, \is_scalar($field['kind'] ?? null) ? (string) $field['kind'] : '');
        }

        $labels = [];

        foreach (\is_array($field['labels'] ?? null) ? $field['labels'] : [] as $locale => $label) {
            if (\is_string($locale) && \is_string($label) && '' !== trim($label)) {
                $labels[$locale] = mb_substr(trim($label), 0, 100);
            }
        }

        $unit = \is_string($field['unit'] ?? null) && '' !== trim($field['unit']) ? mb_substr(trim($field['unit']), 0, self::UNIT_MAX_LENGTH) : null;

        $choices = [];

        foreach (\is_array($field['choices'] ?? null) ? $field['choices'] : [] as $choice) {
            if (\is_scalar($choice) && '' !== trim((string) $choice)) {
                $choices[] = mb_substr(trim((string) $choice), 0, 100);
            }
        }

        $choices = array_values(array_unique($choices));

        if (FieldKind::Choice === $kind && [] === $choices) {
            $problems[] = TranslatableText::of('Field "%s": a choice field lists the words it takes.', $code);
        }

        $translated = (bool) ($field['translated'] ?? false);

        if ($translated && null !== $kind && !$kind->mayBeTranslated()) {
            $problems[] = TranslatableText::of('Field "%s": only a text is written again for each language.', $code);
        }

        $min = self::bound($field['min'] ?? null);
        $max = self::bound($field['max'] ?? null);

        if (null !== $min && null !== $max && $min > $max) {
            $problems[] = TranslatableText::of('Field "%s": the minimum is above the maximum.', $code);
        }

        if ([] !== $problems) {
            throw InvalidFieldsException::because($problems);
        }

        return new self(
            code: $code,
            kind: $kind,
            labels: $labels,
            unit: FieldKind::Integer === $kind || FieldKind::Decimal === $kind ? $unit : null,
            choices: FieldKind::Choice === $kind ? $choices : [],
            translated: $translated,
            required: (bool) ($field['required'] ?? false),
            min: FieldKind::Integer === $kind || FieldKind::Duration === $kind ? self::whole($min) : $min,
            max: FieldKind::Integer === $kind || FieldKind::Duration === $kind ? self::whole($max) : $max,
        );
    }

    /**
     * `new` is what the address of the form adding a field says.
     */
    public static function isValidCode(string $code): bool
    {
        return \strlen($code) <= self::CODE_MAX_LENGTH && 1 === preg_match(self::CODE_PATTERN, $code) && 'new' !== $code;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'code' => $this->code,
            'kind' => $this->kind->value,
            'labels' => $this->labels,
            'unit' => $this->unit,
            'choices' => $this->choices,
            'translated' => $this->translated,
            'required' => $this->required,
            'min' => $this->min,
            'max' => $this->max,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value && false !== $value);
    }

    /**
     * The label in this language, else in the first language it has one, else
     * the code: a field is never shown without a name.
     */
    public function label(string $locale): string
    {
        return $this->labels[$locale] ?? array_values($this->labels)[0] ?? $this->code;
    }

    /**
     * The value as the site stores it: an integer, a float, a boolean or a
     * string, null for no value. What a form posts and what the generator
     * writes both come through here.
     *
     * @throws \InvalidArgumentException with a sentence saying what is wrong
     */
    public function normalize(mixed $value): int|float|string|bool|null
    {
        if (null === $value || (\is_string($value) && '' === trim($value))) {
            return null;
        }

        return match ($this->kind) {
            FieldKind::Integer, FieldKind::Duration => $this->bounded($this->wholeNumber($value)),
            FieldKind::Decimal => $this->bounded($this->number($value)),
            FieldKind::Text => $this->text($value, self::TEXT_MAX_LENGTH, singleLine: true),
            FieldKind::LongText => $this->text($value, self::LONG_TEXT_MAX_LENGTH, singleLine: false),
            FieldKind::Boolean => $this->boolean($value),
            FieldKind::Choice => $this->choice($value),
            FieldKind::Date => $this->date($value),
        };
    }

    private function wholeNumber(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (\is_string($value) && 1 === preg_match('/^\s*-?\d{1,15}\s*$/D', $value)) {
            return (int) trim($value);
        }

        throw InvalidFieldValueException::because(FieldKind::Duration === $this->kind ? 'The value is not a number of minutes.' : 'The value is not a whole number.');
    }

    private function number(mixed $value): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        // A comma is how half the languages of the shop write a decimal.
        if (\is_string($value) && 1 === preg_match('/^\s*-?\d{1,15}([.,]\d{1,6})?\s*$/D', $value)) {
            return (float) str_replace(',', '.', trim($value));
        }

        throw InvalidFieldValueException::because('The value is not a number.');
    }

    private function bounded(int|float $value): int|float
    {
        if (null !== $this->min && $value < $this->min) {
            throw InvalidFieldValueException::because('The value is below %s.', $this->min);
        }

        if (null !== $this->max && $value > $this->max) {
            throw InvalidFieldValueException::because('The value is above %s.', $this->max);
        }

        if (FieldKind::Duration === $this->kind && $value < 0) {
            throw InvalidFieldValueException::because('A duration is not negative.');
        }

        return $value;
    }

    /**
     * Plain text: a template prints it escaped, so markup would show as
     * markup. Tags are taken out rather than refused, the way a pasted
     * sentence usually carries some.
     */
    private function text(mixed $value, int $defaultMax, bool $singleLine): string
    {
        if (!\is_scalar($value)) {
            throw InvalidFieldValueException::because('The value is not a text.');
        }

        $text = html_entity_decode(strip_tags((string) $value), \ENT_QUOTES | \ENT_HTML5);
        $text = $singleLine
            ? trim((string) preg_replace('/\s+/u', ' ', $text))
            : trim((string) preg_replace("/[ \t]+\n/u", "\n", str_replace(["\r\n", "\r"], "\n", $text)));
        $max = null === $this->max ? $defaultMax : min((int) $this->max, $defaultMax);

        if (mb_strlen($text) > $max) {
            throw InvalidFieldValueException::because('The text is longer than %d characters.', $max);
        }

        return $text;
    }

    private function boolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return match (\is_scalar($value) ? strtolower(trim((string) $value)) : null) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw InvalidFieldValueException::because('The value is not yes or no.'),
        };
    }

    private function choice(mixed $value): string
    {
        $word = \is_scalar($value) ? trim((string) $value) : '';

        if (!\in_array($word, $this->choices, true)) {
            throw InvalidFieldValueException::because('"%s" is not one of: %s.', $word, implode(', ', $this->choices));
        }

        return $word;
    }

    private function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = \is_string($value) ? trim($value) : '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if (false === $date || $date->format('Y-m-d') !== $text) {
            throw InvalidFieldValueException::because('The value is not a date written YYYY-MM-DD.');
        }

        return $text;
    }

    private static function bound(mixed $value): int|float|null
    {
        if (\is_int($value) || \is_float($value)) {
            return $value;
        }

        if (\is_string($value) && is_numeric(str_replace(',', '.', trim($value)))) {
            return 0 + str_replace(',', '.', trim($value));
        }

        return null;
    }

    private static function whole(int|float|null $value): ?int
    {
        return null === $value ? null : (int) $value;
    }
}
