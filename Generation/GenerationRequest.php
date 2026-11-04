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
use TheliaCMS\Content\Section;
use TheliaCMS\Page\Field\FieldDefinitions;

/**
 * One call to the generator: a target, a language, and either a brief for a
 * new document, a change asked on the document the target holds, one
 * section of it to write again, or the document of another language to
 * write in this one.
 */
final readonly class GenerationRequest
{
    public const string KIND_BRIEF = 'brief';
    public const string KIND_REVISION = 'revision';
    public const string KIND_SECTION = 'section';
    public const string KIND_TRANSLATION = 'translation';
    /** The text of one rich-text field, written from a brief beside it. */
    public const string KIND_TEXT = 'text';

    private function __construct(
        public GenerationTarget $target,
        public string $locale,
        public string $kind,
        public Brief $brief,
        public ?Document $current = null,
        public ?string $sectionId = null,
        public ?string $sourceLocale = null,
        // Over the effort of the settings, for one call: what an evaluation
        // compares, with the site left as it is.
        public ?string $effort = null,
        // Whether whoever asks may make a component of the site: the
        // generator is then offered to make one when the catalogue lacks
        // it, if the site allows it (GeneratorSettings::componentMaking()).
        public bool $mayMakeComponents = false,
    ) {
    }

    public function withEffort(string $effort): self
    {
        return new self($this->target, $this->locale, $this->kind, $this->brief, $this->current, $this->sectionId, $this->sourceLocale, $effort, $this->mayMakeComponents);
    }

    public function withComponentMaking(bool $mayMakeComponents): self
    {
        return new self($this->target, $this->locale, $this->kind, $this->brief, $this->current, $this->sectionId, $this->sourceLocale, $this->effort, $mayMakeComponents);
    }

    public static function fromBrief(GenerationTarget $target, string $locale, Brief $brief): self
    {
        return new self($target, $locale, self::KIND_BRIEF, $brief);
    }

    public static function text(GenerationTarget $target, string $locale, Brief $brief): self
    {
        return new self($target, $locale, self::KIND_TEXT, $brief);
    }

    /**
     * A change on the whole document: "make the hero shorter", "add a FAQ".
     */
    public static function revision(GenerationTarget $target, string $locale, Document $current, Brief $change): self
    {
        return new self($target, $locale, self::KIND_REVISION, $change, $current);
    }

    /**
     * One section written again, the others kept as they are.
     *
     * @throws \InvalidArgumentException when the document has no such section
     */
    public static function section(GenerationTarget $target, string $locale, Document $current, string $sectionId, Brief $change): self
    {
        if (null === $current->section($sectionId)) {
            throw new \InvalidArgumentException(\sprintf('The document has no section "%s".', $sectionId));
        }

        return new self($target, $locale, self::KIND_SECTION, $change, $current, $sectionId);
    }

    /**
     * The document the target holds in another language, written in this
     * one: same sections, same components, same images and references.
     *
     * @throws \InvalidArgumentException when the source is the language asked for, or holds nothing
     */
    public static function translation(GenerationTarget $target, string $locale, Document $source, string $sourceLocale): self
    {
        if ($sourceLocale === $locale) {
            throw new \InvalidArgumentException('A document is translated from another language than its own.');
        }

        if ($source->isEmpty()) {
            throw new \InvalidArgumentException(\sprintf('The document in %s holds nothing to translate.', $sourceLocale));
        }

        return new self($target, $locale, self::KIND_TRANSLATION, new Brief(\sprintf('Translated from %s.', $sourceLocale)), $source, null, $sourceLocale);
    }

    /**
     * The components the document worked on already uses. A revision or a
     * translation answers with them kept, so the generator may name them even
     * when the site no longer offers them for a new section.
     *
     * @return list<string>
     */
    public function keptComponents(): array
    {
        if (null === $this->current) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (Section $section): string => $section->component, $this->current->sections)));
    }

    /**
     * Whether the answer may propose the title and the description search
     * engines show: for a page written from a brief or a page translated, not
     * for an item of the catalogue, whose meta fields belong to the core.
     */
    public function seoAsked(): bool
    {
        return null === $this->target->item && \in_array($this->kind, [self::KIND_BRIEF, self::KIND_TRANSLATION], true);
    }

    /**
     * The fields of the page the answer writes along with the document: all
     * of them for a page written or changed as a whole, the translated ones
     * for a translation — the others are the same in every language — and
     * none for one section.
     */
    public function fieldsAsked(): FieldDefinitions
    {
        return match ($this->kind) {
            self::KIND_BRIEF, self::KIND_REVISION => $this->target->fields(),
            self::KIND_TRANSLATION => $this->target->fields()->filter(translated: true),
            default => FieldDefinitions::none(),
        };
    }
}
