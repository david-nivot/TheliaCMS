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
 * The fields of a page type, in the order an editor sees them.
 *
 * @implements \IteratorAggregate<int, FieldDefinition>
 */
final readonly class FieldDefinitions implements \IteratorAggregate, \Countable
{
    /** More would be a form nobody fills in, and a prompt that says little else. */
    public const int MAX_FIELDS = 30;

    /**
     * @param list<FieldDefinition> $fields
     */
    private function __construct(
        public array $fields,
    ) {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param array<mixed> $fields
     *
     * @throws InvalidFieldsException when a field is not one, or two share a code
     */
    public static function fromArray(array $fields): self
    {
        $problems = [];
        $read = [];

        foreach (array_values($fields) as $field) {
            if (!\is_array($field)) {
                $problems[] = 'A field is not an object.';

                continue;
            }

            try {
                $definition = FieldDefinition::fromArray($field);
            } catch (InvalidFieldsException $exception) {
                array_push($problems, ...$exception->texts);

                continue;
            }

            if (isset($read[$definition->code])) {
                $problems[] = TranslatableText::of('Two fields share the code "%s".', $definition->code);

                continue;
            }

            $read[$definition->code] = $definition;
        }

        if (\count($read) > self::MAX_FIELDS) {
            $problems[] = TranslatableText::of('A page type has %d fields at most.', self::MAX_FIELDS);
        }

        if ([] !== $problems) {
            throw InvalidFieldsException::because($problems);
        }

        return new self(array_values($read));
    }

    /**
     * The definition a column holds. A column that does not read as one —
     * written by hand, or by a later module — defines no field rather than
     * breaking every page of the type.
     */
    public static function fromJson(?string $json): self
    {
        if (null === $json || '' === trim($json)) {
            return self::none();
        }

        try {
            $decoded = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);

            return \is_array($decoded) ? self::fromArray($decoded) : self::none();
        } catch (\JsonException|InvalidFieldsException) {
            return self::none();
        }
    }

    /**
     * Null when there is no field, so an empty column stays empty.
     */
    public function toJson(): ?string
    {
        if ([] === $this->fields) {
            return null;
        }

        return json_encode($this->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->fields);
    }

    public function find(string $code): ?FieldDefinition
    {
        foreach ($this->fields as $field) {
            if ($field->code === $code) {
                return $field;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }

    /**
     * The fields written once for every language, or the ones written again
     * for each.
     */
    public function filter(bool $translated): self
    {
        return new self(array_values(array_filter($this->fields, static fn (FieldDefinition $field): bool => $field->translated === $translated)));
    }

    /**
     * The values the site stores, by code, for the codes given: a code the
     * type does not define is left out, an empty value is null. Every value
     * wrong is listed at once, named by the label of its field.
     *
     * @param array<mixed> $values
     *
     * @return array<string, int|float|string|bool|null>
     *
     * @throws InvalidFieldsException
     */
    public function normalize(array $values, string $locale): array
    {
        $normalized = [];
        $problems = [];

        foreach ($values as $code => $value) {
            $field = $this->find((string) $code);

            if (null === $field) {
                continue;
            }

            try {
                $normalized[$field->code] = $field->normalize($value);
            } catch (\InvalidArgumentException $exception) {
                $problems[] = TranslatableText::of('%s: %s', $field->label($locale), $exception instanceof InvalidFieldValueException ? $exception->text : $exception->getMessage());
            }
        }

        if ([] !== $problems) {
            throw InvalidFieldsException::because($problems);
        }

        return $normalized;
    }

    /**
     * The required fields these values leave empty.
     *
     * @param array<string, mixed> $values
     *
     * @return list<FieldDefinition>
     */
    public function missingIn(array $values): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FieldDefinition $field): bool => $field->required && null === ($values[$field->code] ?? null),
        ));
    }

    /**
     * @return \ArrayIterator<int, FieldDefinition>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->fields);
    }

    public function count(): int
    {
        return \count($this->fields);
    }
}
