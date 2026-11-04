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
use TheliaCMS\Content\SectionDefinitionInterface;

/**
 * What only the editor sets in a section (the size and the kept part of a
 * picture), on its way to the model and back.
 *
 * Going out, a document shown to the model carries none of it: the props
 * marked `editorOnly` are left out, and a choice set to a value the model is not
 * offered (`width = custom`) reads as the default of the prop. The stored
 * document is not touched.
 *
 * Coming back, an answer that replaces a section brings none of it, so the
 * values of the section replaced are put back, provided it is the same
 * component showing the same images: another picture makes the old framing
 * meaningless, and the section then takes the defaults. A choice kept for the
 * editor is put back when the model left the value it was shown, a different
 * value being a change it asked for.
 *
 * The rows of a list are treated the same way for the editor-only props they
 * hold (the kept part of each picture of the columns): left out of the rows
 * the model reads, put back row by row, a row being matched to the row of the
 * same position when the component and the pictures of the row are unchanged.
 */
final readonly class EditorValues
{
    public function __construct(
        private SectionCatalog $catalog,
    ) {
    }

    /**
     * The document as the model reads it.
     */
    public function forModel(Document $document): Document
    {
        return new Document(array_map(fn (Section $section): Section => $this->hidden($section), $document->sections));
    }

    /**
     * The answer, each of its sections given back what the editor set in the
     * section it replaces.
     */
    public function restore(Document $replaced, Document $answer): Document
    {
        return new Document(array_map(
            fn (Section $written): Section => $this->restoreSection($replaced->section($written->id), $written),
            $answer->sections,
        ));
    }

    public function restoreSection(?Section $replaced, Section $written): Section
    {
        $definition = $this->catalog->find($written->component);

        if (null === $definition) {
            return $written;
        }

        $props = $written->props;
        $held = null !== $replaced && $replaced->component === $written->component ? $this->normalized($definition, $replaced->props) : [];
        $kept = null !== $replaced && $replaced->component === $written->component && $this->sameImages($definition->schema()->props, $held, $written->props);

        foreach ($definition->schema()->props as $prop) {
            if ($prop->editorOnly) {
                $props = self::put($props, $prop, $kept && \array_key_exists($prop->name, $held) ? $held[$prop->name] : $prop->default);

                continue;
            }

            if (self::hasEditorOnlyRows($prop)) {
                $props[$prop->name] = $this->restoredRows($prop, $kept && \is_array($held[$prop->name] ?? null) ? $held[$prop->name] : [], $props[$prop->name] ?? []);

                continue;
            }

            if ([] === $prop->editorOnlyChoices) {
                continue;
            }

            $editorValue = $kept ? ($held[$prop->name] ?? null) : null;
            $answered = $props[$prop->name] ?? $prop->default;

            // The model was shown the default in place of the editor's value: the
            // same answer is no change, another one is a request.
            if (\in_array($editorValue, $prop->editorOnlyChoices, true) && $answered === $prop->default) {
                $props[$prop->name] = $editorValue;
            } elseif (\in_array($answered, $prop->editorOnlyChoices, true)) {
                $props[$prop->name] = $prop->default;
            }
        }

        return $written->withProps($props);
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private function normalized(SectionDefinitionInterface $definition, array $props): array
    {
        try {
            return $definition->normalizeProps($props);
        } catch (InvalidDocumentException) {
            return $props;
        }
    }

    private function hidden(Section $section): Section
    {
        $definition = $this->catalog->find($section->component);

        if (null === $definition) {
            return $section;
        }

        $props = $section->props;

        foreach ($definition->schema()->props as $prop) {
            if ($prop->editorOnly) {
                unset($props[$prop->name]);
            } elseif (self::hasEditorOnlyRows($prop) && \is_array($props[$prop->name] ?? null)) {
                $props[$prop->name] = array_map(
                    static fn (mixed $row): mixed => \is_array($row) ? array_diff_key($row, array_flip(array_map(static fn (Prop $item): string => $item->name, array_filter($prop->items, static fn (Prop $item): bool => $item->editorOnly)))) : $row,
                    $props[$prop->name],
                );
            } elseif ([] !== $prop->editorOnlyChoices && \in_array($props[$prop->name] ?? null, $prop->editorOnlyChoices, true)) {
                $props[$prop->name] = $prop->default;
            }
        }

        return $section->withProps($props);
    }

    /**
     * Whether a list holds rows of fields with props only the editor sets.
     */
    private static function hasEditorOnlyRows(Prop $prop): bool
    {
        return PropType::Items === $prop->type && !$prop->scalarItems && [] !== array_filter($prop->items, static fn (Prop $item): bool => $item->editorOnly);
    }

    /**
     * The rows of an answer, each given back what the editor set in the row of
     * the same position of the list replaced, when its pictures are the same;
     * the defaults otherwise, and for a row the replaced list does not have.
     * What an answer holds of the editor's is never kept.
     *
     * @param list<mixed> $heldRows the rows replaced, as the schema reads them
     *
     * @return mixed the rows of the answer, as they were when they are no list of rows
     */
    private function restoredRows(Prop $prop, array $heldRows, mixed $rows): mixed
    {
        if (!\is_array($rows)) {
            return $rows;
        }

        $restored = [];

        foreach (array_values($rows) as $position => $row) {
            if (!\is_array($row)) {
                $restored[] = $row;

                continue;
            }

            $held = $heldRows[$position] ?? null;
            $held = \is_array($held) && $this->sameImages($prop->items, $held, $row) ? $held : null;

            foreach ($prop->items as $item) {
                if ($item->editorOnly) {
                    $row[$item->name] = null !== $held && \array_key_exists($item->name, $held) ? $held[$item->name] : $item->default;
                }
            }

            $restored[] = $row;
        }

        return $restored;
    }

    /**
     * @param list<Prop>           $definitions
     * @param array<string, mixed> $replaced
     * @param array<string, mixed> $written
     */
    private function sameImages(array $definitions, array $replaced, array $written): bool
    {
        foreach ($definitions as $prop) {
            if (PropType::Image === $prop->type && self::comparable($replaced[$prop->name] ?? null) !== self::comparable($written[$prop->name] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * An image is an id or an address, which an answer may spell as a string
     * and the stored section as a number, and none as null or 0.
     */
    private static function comparable(mixed $image): string
    {
        return null === $image || 0 === $image || '0' === $image || '' === $image ? '' : (string) $image;
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    private static function put(array $props, Prop $prop, mixed $value): array
    {
        $props[$prop->name] = $value;

        return $props;
    }
}
