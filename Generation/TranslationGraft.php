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

namespace TheliaCMS\Generation;

use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;

/**
 * Writes the translation of a document onto its source.
 *
 * What a translation changes is words. The sections, their order, their ids
 * and their components are the source's, and an answer that differs from them
 * is sent back. Of the props, only the texts (`text`, `html`) and the values
 * kept as the component documents them (`json`, which may hold text) are read
 * from the answer, in the rows of a list too; an image, a reference, an
 * address, a number, a switch or a choice is copied from the source, whatever
 * the model wrote. The appearance is the source's.
 */
final readonly class TranslationGraft
{
    public function __construct(
        private SectionCatalog $catalog,
    ) {
    }

    /**
     * @param Document $source the document being translated
     * @param Document $answer the translated document, already validated
     *
     * @throws InvalidDocumentException listing every difference with the source
     */
    public function graft(Document $source, Document $answer): Document
    {
        $problems = $this->differences($source, $answer);

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        $sections = [];

        foreach ($source->sections as $index => $original) {
            $written = $answer->sections[$index];
            $definition = $this->catalog->find($original->component);

            // The answer was validated, so its component is known; a source
            // whose component went since is kept as it is.
            if (null === $definition) {
                $sections[] = $original;

                continue;
            }

            try {
                $held = $definition->normalizeProps($original->props);
            } catch (InvalidDocumentException) {
                $held = $original->props;
            }

            $props = $this->props($definition->schema()->props, $held, $written->props, \sprintf('Section %d ("%s")', $index + 1, $original->id), $problems);
            $sections[] = new Section($original->id, $original->component, $props, $original->appearance);
        }

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return new Document($sections);
    }

    /**
     * @return list<string>
     */
    private function differences(Document $source, Document $answer): array
    {
        $expected = implode(', ', array_map(static fn (Section $section): string => \sprintf('"%s" (%s)', $section->id, $section->component), $source->sections));

        if (\count($source->sections) !== \count($answer->sections)) {
            return [\sprintf('The translation holds %d sections and the source %d. Keep the same sections, in the same order, with the same ids and components: %s.', \count($answer->sections), \count($source->sections), $expected)];
        }

        $problems = [];

        foreach ($source->sections as $index => $original) {
            $written = $answer->sections[$index];

            if ($written->id !== $original->id || $written->component !== $original->component) {
                $problems[] = \sprintf('Section %d must be "%s" (%s), as in the source, and is "%s" (%s).', $index + 1, $original->id, $original->component, $written->id, $written->component);
            }
        }

        return $problems;
    }

    /**
     * @param list<Prop>           $definitions
     * @param array<string, mixed> $source
     * @param array<string, mixed> $answer
     * @param list<string>         $problems
     *
     * @return array<string, mixed>
     */
    private function props(array $definitions, array $source, array $answer, string $where, array &$problems): array
    {
        $props = $answer;

        foreach ($definitions as $prop) {
            $name = $prop->name;

            if (self::isWords($prop)) {
                if (!\array_key_exists($name, $answer) && \array_key_exists($name, $source)) {
                    $props[$name] = $source[$name];
                }

                continue;
            }

            if (PropType::Items !== $prop->type) {
                if (\array_key_exists($name, $source)) {
                    $props[$name] = $source[$name];
                } else {
                    unset($props[$name]);
                }

                continue;
            }

            $held = \is_array($source[$name] ?? null) ? $source[$name] : [];
            $written = \is_array($answer[$name] ?? null) ? $answer[$name] : [];

            if (\count($held) !== \count($written)) {
                $problems[] = \sprintf('%s: "%s" holds %d items in the source and %d in the translation. Keep every item, in the same order.', $where, $name, \count($held), \count($written));
                $props[$name] = $held;

                continue;
            }

            $props[$name] = [];

            foreach ($held as $index => $row) {
                $other = $written[$index] ?? null;

                if ($prop->scalarItems) {
                    $props[$name][] = self::isWords($prop->items[0]) ? $other : $row;

                    continue;
                }

                $props[$name][] = $this->props(
                    $prop->items,
                    \is_array($row) ? $row : [],
                    \is_array($other) ? $other : [],
                    \sprintf('%s, "%s" item %d', $where, $name, $index + 1),
                    $problems,
                );
            }
        }

        return $props;
    }

    /**
     * The props a translation writes: the words, and the values that may hold some.
     */
    private static function isWords(Prop $prop): bool
    {
        return \in_array($prop->type, [PropType::Text, PropType::Html, PropType::Json], true);
    }
}
