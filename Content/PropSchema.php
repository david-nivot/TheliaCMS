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

namespace TheliaCMS\Content;

use TheliaCMS\Locale\TranslatableText;

/**
 * The props of a section, and what to do with the values it is handed.
 *
 * Normalising is strict where it costs nothing to be — a wrong type, a value
 * out of its range, an unknown choice are refused rather than coerced — and
 * forgiving where the author cannot know better: an unknown prop is dropped, a
 * missing one gets its default. What comes out is exactly what the template is
 * handed, so the template never checks a value.
 */
final readonly class PropSchema
{
    /**
     * @param list<Prop> $props
     */
    public function __construct(
        public array $props,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     *
     * @throws InvalidDocumentException listing every problem found
     */
    public function normalize(array $raw): array
    {
        $values = [];
        $problems = [];

        foreach ($this->props as $prop) {
            try {
                $values[$prop->name] = $this->value($prop, \array_key_exists($prop->name, $raw) ? $raw[$prop->name] : null);
            } catch (InvalidDocumentException $exception) {
                foreach ($exception->texts as $problem) {
                    $problems[] = $problem;
                }
            }
        }

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->props as $prop) {
            $properties[$prop->name] = $prop->toJsonSchema();

            if ($prop->required) {
                $required[] = $prop->name;
            }
        }

        return [
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @throws InvalidDocumentException
     */
    private function value(Prop $prop, mixed $value): mixed
    {
        if (null === $value || '' === $value) {
            if ($prop->required) {
                throw InvalidDocumentException::because([TranslatableText::of('"%s" is required.', $prop->name)]);
            }

            return \in_array($prop->type, [PropType::Image, PropType::Reference], true) ? null : $prop->default;
        }

        return match ($prop->type) {
            PropType::Text => $this->text($prop, $value),
            PropType::Html => $this->html($prop, $value),
            PropType::Integer => $this->integer($prop, $value),
            PropType::Boolean => $this->boolean($prop, $value),
            PropType::Choice => $this->choice($prop, $value),
            PropType::Image, PropType::Reference => $this->image($prop, $value),
            PropType::Url => $this->url($prop, $value),
            PropType::Items => $this->items($prop, $value),
            PropType::Json => $this->json($prop, $value),
        };
    }

    private function json(Prop $prop, mixed $value): mixed
    {
        // A generator constrained by a structured output writes such a value
        // as a JSON string: read it back.
        if (\is_string($value) && 1 === preg_match('/^\s*[\[{]/', $value)) {
            try {
                $value = json_decode($value, true, 32, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw InvalidDocumentException::because([TranslatableText::of('"%s" is not valid JSON.', $prop->name)]);
            }
        }

        if (!\is_array($value) && !\is_scalar($value)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be a list, an object or a scalar.', $prop->name)]);
        }

        return $value;
    }

    private function text(Prop $prop, mixed $value): string
    {
        if (!\is_scalar($value)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be a string.', $prop->name)]);
        }

        $text = trim((string) $value);

        if (null !== $prop->max && mb_strlen($text) > $prop->max) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" is longer than %d characters.', $prop->name, $prop->max)]);
        }

        if ('' === $text && $prop->required) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" is required.', $prop->name)]);
        }

        return $text;
    }

    private function html(Prop $prop, mixed $value): string
    {
        if (!\is_string($value)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be a string of markup.', $prop->name)]);
        }

        return $value;
    }

    private function integer(Prop $prop, mixed $value): int
    {
        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^-?\d+$/', $value))) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be a whole number.', $prop->name)]);
        }

        $integer = (int) $value;

        if ((null !== $prop->min && $integer < $prop->min) || (null !== $prop->max && $integer > $prop->max)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be between %d and %d.', $prop->name, $prop->min ?? \PHP_INT_MIN, $prop->max ?? \PHP_INT_MAX)]);
        }

        return $integer;
    }

    private function boolean(Prop $prop, mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            return \in_array($value, [1, '1', 'true'], true);
        }

        throw InvalidDocumentException::because([TranslatableText::of('"%s" must be true or false.', $prop->name)]);
    }

    private function choice(Prop $prop, mixed $value): string
    {
        if (!\is_scalar($value) || !\array_key_exists((string) $value, $prop->choices)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be one of %s.', $prop->name, implode(', ', array_keys($prop->choices)))]);
        }

        return (string) $value;
    }

    /**
     * An image id, or the id of a referenced item: the same shape, a positive
     * integer or nothing. An image may also be the absolute https address of
     * a picture kept elsewhere, which the editor types by hand.
     */
    private function image(Prop $prop, mixed $value): int|string|null
    {
        $what = PropType::Image === $prop->type
            ? TranslatableText::of('the id of an image of the media library, or an https address')
            : TranslatableText::of('the id of the item it refers to');

        if (PropType::Image === $prop->type && \is_string($value) && self::isExternalImage($value)) {
            return trim($value);
        }

        // The address the media library serves a picture from, as an editor
        // copies it from a page: the picture it names is its id.
        if (PropType::Image === $prop->type && \is_string($value) && null !== ($libraryId = self::libraryImageId($value))) {
            $value = $libraryId;
        }

        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^\d+$/', $value))) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be %s.', $prop->name, $what)]);
        }

        $id = (int) $value;

        // Zero is "no picture yet": what a sample holds, and what a section
        // shows as a placeholder until an image is chosen.
        if (0 === $id) {
            if ($prop->required) {
                throw InvalidDocumentException::because([TranslatableText::of('"%s" is required.', $prop->name)]);
            }

            return null;
        }

        if ($id < 0) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be %s.', $prop->name, $what)]);
        }

        return $id;
    }

    /**
     * The id of the picture a path of the media library names, or null when
     * the value is no such path. The route of the library is
     * `/image-library/{id}/{region}/{size}/{rotation}/{quality}.{format}`
     * (IIIF), in the formats it renders.
     */
    public static function libraryImageId(string $value): ?int
    {
        if (1 !== preg_match('#^/image-library/(\d+)/[^/\s?\#]+/[^/\s?\#]+/[^/\s?\#]+/[^/\s?\#.]+\.(?:jpe?g|png|gif|jp2|webp)$#i', trim($value), $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * An absolute https address, as an editor pastes it: nothing else is read
     * from outside the site, and a path is never an image of elsewhere.
     */
    public static function isExternalImage(string $value): bool
    {
        $value = trim($value);

        return mb_strlen($value) <= 2048
            && 1 === preg_match('#^https://[^\s/?\#]+[^\s]*$#i', $value)
            && false !== filter_var($value, \FILTER_VALIDATE_URL);
    }

    private function url(Prop $prop, mixed $value): string
    {
        if (!\is_string($value)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be an address.', $prop->name)]);
        }

        $url = trim($value);

        // A path of the site, an absolute web address, a mail or phone link.
        // Anything else — `javascript:` first of all — is not an address a page
        // may send a visitor to.
        if (1 !== preg_match('#^(/(?!/)|\#|https?://|mailto:|tel:)#i', $url)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be a path of the site or an absolute http(s), mailto or tel address.', $prop->name)]);
        }

        if (null !== $prop->max && \strlen($url) > $prop->max) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" is longer than %d characters.', $prop->name, $prop->max)]);
        }

        return $url;
    }

    /**
     * @return list<array<string, mixed>>|list<bool|float|int|string|null>
     */
    private function items(Prop $prop, mixed $value): array
    {
        // A generator constrained by a structured output writes a list as a
        // JSON string: read it back.
        if (\is_string($value)) {
            try {
                $value = json_decode($value, true, 32, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw InvalidDocumentException::because([TranslatableText::of('"%s" is not valid JSON.', $prop->name)]);
            }
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must be a list.', $prop->name)]);
        }

        if ($prop->scalarItems) {
            // The form sends a blank row for a field left empty: it holds nothing.
            $value = array_values(array_filter($value, static fn (mixed $row): bool => !self::isBlank(\is_array($row) && \array_key_exists('value', $row) ? $row['value'] : $row)));
        }

        $count = \count($value);

        if ((null !== $prop->minItems && $count < $prop->minItems) || (null !== $prop->maxItems && $count > $prop->maxItems)) {
            throw InvalidDocumentException::because([TranslatableText::of('"%s" must hold between %d and %d items.', $prop->name, $prop->minItems ?? 0, $prop->maxItems ?? 12)]);
        }

        $schema = new self($prop->items);
        $items = [];
        $problems = [];

        foreach ($value as $index => $item) {
            if ($prop->scalarItems) {
                try {
                    $items[] = $schema->normalize(['value' => \is_array($item) && \array_key_exists('value', $item) ? $item['value'] : $item])['value'];
                } catch (InvalidDocumentException $exception) {
                    foreach ($exception->texts as $problem) {
                        $problems[] = TranslatableText::of('"%s" item %d: %s', $prop->name, $index + 1, $problem);
                    }
                }

                continue;
            }

            if (!\is_array($item)) {
                $problems[] = TranslatableText::of('"%s" item %d is not an object.', $prop->name, $index + 1);

                continue;
            }

            try {
                $items[] = $schema->normalize(self::rowKeys($item));
            } catch (InvalidDocumentException $exception) {
                foreach ($exception->texts as $problem) {
                    $problems[] = TranslatableText::of('"%s" item %d: %s', $prop->name, $index + 1, $problem);
                }
            }
        }

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return $items;
    }

    /**
     * A row with its keys as the schema names them: a theme component reads a
     * row as it spells it (`imageAlt`), as a document written for it, an
     * example of the theme or a generator does. The key of the schema wins
     * when a row holds both.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array<array-key, mixed>
     */
    public static function rowKeys(array $row): array
    {
        foreach ($row as $key => $value) {
            $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key) ?? (string) $key);

            if ($name !== $key && !\array_key_exists($name, $row)) {
                $row[$name] = $value;
            }
        }

        return $row;
    }

    private static function isBlank(mixed $value): bool
    {
        return null === $value || (\is_string($value) && '' === trim($value));
    }
}
