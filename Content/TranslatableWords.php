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

/**
 * What a translation depends on in its source: the words of each section.
 *
 * A translation writes the texts, the markup and the values kept as the
 * component documents them (`TranslationGraft`), in the rows of a list too.
 * The fingerprint of a document is the SHA-256 of those words for each
 * section, by section id: an image, a colour, a link, a number or a move of
 * a section changes no word and so no hash, a section added or removed does.
 * A component the catalogue no longer holds has no schema to tell the words
 * from the rest: all its props count.
 */
final readonly class TranslatableWords
{
    public function __construct(
        private SectionCatalog $catalog,
    ) {
    }

    /**
     * @return array<string, string> the hash of the words of each section, by section id
     */
    public function fingerprint(Document $document): array
    {
        $fingerprint = [];

        foreach ($document->sections as $section) {
            $definition = $this->catalog->find($section->component);
            $words = null === $definition ? $section->props : $this->words($definition->schema()->props, $section->props);

            $fingerprint[$section->id] = hash('sha256', json_encode(self::canonical($words), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        }

        return $fingerprint;
    }

    /**
     * The hash of the words of a header, by column: what a header is followed
     * on, as a section is. Blanks and absent are the same, and so is the order
     * the columns are given in.
     *
     * @param array<string, string|null> $header
     */
    public function headerFingerprint(array $header): string
    {
        $words = [];

        foreach ($header as $column => $text) {
            $words[(string) $column] = trim((string) $text);
        }

        ksort($words);

        return hash('sha256', json_encode($words, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $recorded the fingerprint of the source when the translation was written
     * @param array<string, string> $current  the fingerprint of the source now
     *
     * @return array{changed: list<string>, removed: list<string>} the sections whose words changed or that were added, and the ones that are gone
     */
    public function differences(array $recorded, array $current): array
    {
        $changed = [];

        foreach ($current as $id => $hash) {
            if (($recorded[$id] ?? null) !== $hash) {
                $changed[] = (string) $id;
            }
        }

        return ['changed' => $changed, 'removed' => array_map(strval(...), array_keys(array_diff_key($recorded, $current)))];
    }

    /**
     * The props a translation writes: the words, and the values that may hold some.
     */
    public static function holdsWords(Prop $prop): bool
    {
        return \in_array($prop->type, [PropType::Text, PropType::Html, PropType::Json], true);
    }

    /**
     * @param list<Prop>           $definitions
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private function words(array $definitions, array $props): array
    {
        $words = [];

        foreach ($definitions as $prop) {
            $value = $props[$prop->name] ?? null;

            if (self::holdsWords($prop)) {
                $words[$prop->name] = $value;

                continue;
            }

            if (PropType::Items !== $prop->type) {
                continue;
            }

            $rows = [];

            foreach (\is_array($value) ? $value : [] as $row) {
                if ($prop->scalarItems) {
                    $rows[] = self::holdsWords($prop->items[0]) ? $row : null;

                    continue;
                }

                $rows[] = $this->words($prop->items, \is_array($row) ? $row : []);
            }

            $words[$prop->name] = $rows;
        }

        return $words;
    }

    /**
     * The same value whatever the order its keys were written in.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
