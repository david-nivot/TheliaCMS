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
 * The content of a page or of a block in one language: an ordered list of
 * sections, each a component of the catalogue with its props.
 *
 * This is what is stored, what is rendered to HTML and what a later edit
 * changes. The HTML next to it in the database is only a rendering of it. The
 * format carries a version so that a document written today is still read by
 * a module released later.
 */
final readonly class Document
{
    public const int VERSION = 1;

    /**
     * @param list<Section> $sections
     */
    public function __construct(
        public array $sections,
    ) {
    }

    /**
     * A document made of a single piece of free markup: what a seeded page, a
     * test fixture or a hand-written page holds.
     */
    public static function richText(string $html, string $sectionId = 'content'): self
    {
        return new self([new Section($sectionId, RichTextSection::NAME, ['html' => $html])]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->sections;
    }

    public function section(string $id): ?Section
    {
        foreach ($this->sections as $section) {
            if ($section->id === $id) {
                return $section;
            }
        }

        return null;
    }

    public function withSection(Section $replacement): self
    {
        $sections = [];

        foreach ($this->sections as $section) {
            $sections[] = $section->id === $replacement->id ? $replacement : $section;
        }

        return new self($sections);
    }

    /**
     * The section moved by one place, up (-1) or down (1). At the top or at
     * the bottom already, the document is returned as it is.
     */
    public function withSectionMoved(string $id, int $direction): self
    {
        $index = $this->indexOf($id);

        if (null === $index) {
            return $this;
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        return isset($this->sections[$target]) ? $this->withSectionAt($id, $target) : $this;
    }

    /**
     * The section taken out and put back at a rank counted from 0, the
     * others closing up around it: what dropping it there gives. A rank past
     * the end puts it last; a section the document does not hold, or one
     * already there, leaves the document as it is.
     */
    public function withSectionAt(string $id, int $position): self
    {
        $index = $this->indexOf($id);
        $position = max(0, min($position, \count($this->sections) - 1));

        if (null === $index || $index === $position) {
            return $this;
        }

        $sections = $this->sections;
        [$moved] = array_splice($sections, $index, 1);
        array_splice($sections, $position, 0, [$moved]);

        return new self($sections);
    }

    private function indexOf(string $id): ?int
    {
        foreach ($this->sections as $index => $section) {
            if ($section->id === $id) {
                return $index;
            }
        }

        return null;
    }

    public function withSectionAppended(Section $section): self
    {
        return new self([...$this->sections, $section]);
    }

    /**
     * The section put right after the one carrying `$id`, the others kept
     * in their order. A document that does not hold `$id` is returned as it
     * is.
     */
    public function withSectionAfter(string $id, Section $section): self
    {
        $index = $this->indexOf($id);

        if (null === $index) {
            return $this;
        }

        $sections = $this->sections;
        array_splice($sections, $index + 1, 0, [$section]);

        return new self($sections);
    }

    /**
     * An id no section of the document holds yet, read from the component:
     * `Layouts:CrossSelling:Base` gives `crossselling`, then `crossselling-2`.
     * The family in front and the `Base` variant behind say nothing of what
     * the section is.
     */
    public function freeSectionId(string $component): string
    {
        $segments = explode(':', $component);

        if (\count($segments) > 1) {
            array_shift($segments);
        }

        if (\count($segments) > 1 && 'base' === strtolower((string) end($segments))) {
            array_pop($segments);
        }

        $base = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', implode('-', $segments))), '-');
        $base = '' === $base ? 'section' : $base;
        $id = $base;

        for ($suffix = 2; null !== $this->section($id); ++$suffix) {
            $id = $base.'-'.$suffix;
        }

        return $id;
    }

    /**
     * Each section takes the appearance of the section of `$source` that
     * carries its id; a section `$source` does not hold keeps its own.
     */
    public function withAppearancesOf(self $source): self
    {
        return new self(array_map(
            static fn (Section $section): Section => null === ($original = $source->section($section->id))
                ? $section
                : $section->withAppearance($original->appearance),
            $this->sections,
        ));
    }

    public function withoutSection(string $id): self
    {
        return new self(array_values(array_filter($this->sections, static fn (Section $section): bool => $section->id !== $id)));
    }

    /**
     * @return array{version: int, sections: list<array{id: string, component: string, props: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'sections' => array_map(static fn (Section $section): array => $section->toArray(), $this->sections),
        ];
    }

    public function toJson(): string
    {
        // Slashes and non-ASCII characters are kept as they are: the stored
        // document is searched for image addresses (`/image-library/{id}/`)
        // and block references, and both are written the way the HTML spells
        // them.
        return json_encode($this->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }

    /**
     * @throws InvalidDocumentException when the JSON is not a document
     */
    public static function fromJson(?string $json): ?self
    {
        if (null === $json || '' === trim($json)) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw InvalidDocumentException::because(['The document is not valid JSON: '.$exception->getMessage()]);
        }

        if (!\is_array($decoded)) {
            throw InvalidDocumentException::because(['The document is not a JSON object.']);
        }

        return self::fromArray($decoded);
    }

    /**
     * Reads the shape only: whether each component exists and whether its
     * props make sense is the job of the validator, which knows the catalogue.
     *
     * @param array<mixed> $document
     *
     * @throws InvalidDocumentException when the array is not shaped like a document
     */
    public static function fromArray(array $document): self
    {
        $version = $document['version'] ?? null;

        if (!\is_int($version)) {
            throw InvalidDocumentException::because(['The document carries no format version.']);
        }

        if ($version > self::VERSION) {
            throw InvalidDocumentException::because([\sprintf('The document is in format version %d, and this module reads up to version %d.', $version, self::VERSION)]);
        }

        $sections = $document['sections'] ?? null;

        if (!\is_array($sections) || !array_is_list($sections)) {
            throw InvalidDocumentException::because(['The document holds no list of sections.']);
        }

        $problems = [];
        $read = [];

        foreach ($sections as $index => $section) {
            if (!\is_array($section)) {
                $problems[] = \sprintf('Section %d is not an object.', $index + 1);

                continue;
            }

            $id = $section['id'] ?? null;
            $component = $section['component'] ?? null;
            $props = $section['props'] ?? [];

            if (!\is_string($id) || '' === $id) {
                $problems[] = \sprintf('Section %d has no id.', $index + 1);
            }

            if (!\is_string($component) || '' === $component) {
                $problems[] = \sprintf('Section %d names no component.', $index + 1);
            }

            if (!\is_array($props) || array_is_list($props) && [] !== $props) {
                $problems[] = \sprintf('Section %d has props that are not an object.', $index + 1);
            }

            $appearance = new SectionAppearance();
            $values = $section['appearance'] ?? null;

            if (null !== $values && (!\is_array($values) || array_is_list($values) && [] !== $values)) {
                $problems[] = \sprintf('Section %d has an appearance that is not an object.', $index + 1);
            } elseif (null !== $values) {
                try {
                    $appearance = SectionAppearance::fromArray($values);
                } catch (InvalidDocumentException $exception) {
                    foreach ($exception->problems as $problem) {
                        $problems[] = \sprintf('Section %d: %s', $index + 1, $problem);
                    }
                }
            }

            if (\is_string($id) && '' !== $id && \is_string($component) && '' !== $component && \is_array($props)) {
                $read[] = new Section($id, $component, $props, $appearance);
            }
        }

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return new self($read);
    }
}
