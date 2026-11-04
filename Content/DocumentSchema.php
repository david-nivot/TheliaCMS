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
 * The JSON schema of a document, built from the catalogue.
 *
 * Two readings of it. The full one says everything the props declare: the
 * shape of every section, its ranges and lengths; it is what the validator
 * enforces and what documents the catalogue. The strict one is what a
 * generator is constrained with, and it is deliberately small: the grammar
 * a structured output is compiled into cannot hold twenty sections with
 * their props (the API caps optional properties, union types and the size of
 * the grammar), so the generator is held to the envelope — a list of
 * sections, each naming a component of the catalogue and carrying its props
 * as a JSON object written as a string — and the validator reads the props
 * back and checks them against the full schema, sending the document back
 * for correction when they are wrong.
 */
final readonly class DocumentSchema
{
    /** How long the title and the description a search engine shows may be: past it, a generated one is ignored. */
    public const int META_TITLE_LENGTH = 60;

    public const int META_DESCRIPTION_LENGTH = 155;

    public function __construct(
        private SectionCatalog $catalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function full(): array
    {
        $sections = [];

        foreach ($this->catalog->all() as $definition) {
            $sections[] = [
                'type' => 'object',
                'description' => $definition->description(),
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Unique in the page: lowercase letters, digits, hyphens and underscores.'],
                    'component' => ['type' => 'string', 'const' => $definition->name()],
                    'props' => $definition->schema()->toJsonSchema(),
                    'appearance' => self::appearance(),
                ],
                'required' => ['id', 'component', 'props'],
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'integer', 'const' => Document::VERSION],
                'sections' => ['type' => 'array', 'items' => ['anyOf' => $sections]],
            ],
            'required' => ['version', 'sections'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param list<string> $kept       components of the catalogue the site no longer
     *                                 offers, allowed all the same because the
     *                                 document being revised or translated uses them
     * @param bool         $withFields whether the answer also writes the values of
     *                                 the fields of the page, as a JSON object written
     *                                 as a string like the props (docs/adr/0009)
     * @param bool         $withSeo    whether the answer may also propose the title and
     *                                 the description a search engine shows for the page
     *
     * @return array<string, mixed>
     */
    public function strict(array $kept = [], bool $withFields = false, bool $withSeo = false): array
    {
        $names = $this->namesWith($kept);

        $schema = [
            'type' => 'object',
            'properties' => [
                'version' => ['type' => 'integer', 'const' => Document::VERSION],
                'sections' => [
                    'type' => 'array',
                    'description' => 'The sections of the page, in order.',
                    'items' => self::sectionItem($names),
                ],
            ],
            'required' => ['version', 'sections'],
            'additionalProperties' => false,
        ];

        // Optional: the editor is told what the page could not hold, rather
        // than finding out by reading it.
        $schema['properties']['left_out'] = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => 'Only when the page leaves out something the brief asked for: one short sentence each, in the language of the page, saying what is missing and why — an image the media library does not hold and the brief gives no https address of, a product the search does not find, a page that could not be read.',
        ];

        if ($withSeo) {
            $schema['properties']['meta_title'] = [
                'type' => 'string',
                'description' => \sprintf('Optional. The title a search engine shows for the page, in the language of the page, plain text of %d characters at most (longer is ignored): what the page is about, not the title of the page again word for word.', self::META_TITLE_LENGTH),
            ];
            $schema['properties']['meta_description'] = [
                'type' => 'string',
                'description' => \sprintf('Optional. The description a search engine shows for the page, in the language of the page, plain text of %d characters at most (longer is ignored): what a visitor finds on the page.', self::META_DESCRIPTION_LENGTH),
            ];
        }

        if ($withFields) {
            $schema['properties']['fields'] = [
                'type' => 'string',
                'description' => 'The values of the fields of the page, as a JSON object written as a string, by field code, with the values the fields of the page type take.',
            ];
            $schema['required'][] = 'fields';
        }

        return $schema;
    }

    /**
     * The schema a section written alone is held to: the shape of one item of
     * `sections`, nothing around it. What a request to write one section again
     * is constrained with, the rest of the document staying what it is.
     *
     * @param list<string> $kept components the site no longer offers that the document uses
     *
     * @return array<string, mixed>
     */
    public function strictSection(array $kept = []): array
    {
        return self::sectionItem($this->namesWith($kept));
    }

    /**
     * @param list<string> $kept
     *
     * @return list<string>
     */
    private function namesWith(array $kept): array
    {
        $names = $this->catalog->offeredNames();

        foreach ($kept as $name) {
            if (!\in_array($name, $names, true) && null !== $this->catalog->find($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, mixed>
     */
    private static function sectionItem(array $names): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Unique in the page: lowercase letters, digits, hyphens and underscores, saying what the section is.'],
                'component' => ['type' => 'string', 'enum' => $names, 'description' => 'The name of a component of the catalogue.'],
                'props' => ['type' => 'string', 'description' => 'The props of the component, as a JSON object written as a string, with the keys and the values the catalogue describes for this component.'],
                'appearance' => self::appearance(),
            ],
            'required' => ['id', 'component', 'props'],
            'additionalProperties' => false,
        ];
    }

    /**
     * How a section stands out from the page. Optional, and so is each of
     * its settings: a section without it is drawn as its component draws it.
     * The colours are checked when the document is read, the schema having
     * no pattern the API accepts.
     *
     * @return array<string, mixed>
     */
    private static function appearance(): array
    {
        return [
            'type' => 'object',
            'description' => 'Only when the brief asks for it: how the section stands out from the page.',
            'properties' => [
                'background' => ['type' => 'string', 'description' => 'Background colour of the whole width of the section, written #rrggbb.'],
                'text' => ['type' => 'string', 'description' => 'Colour of the text, written #rrggbb, readable on the background.'],
                'padding' => ['type' => 'string', 'enum' => array_keys(SectionAppearance::PADDINGS), 'description' => 'Room added above and below the section.'],
                'align' => ['type' => 'string', 'enum' => SectionAppearance::ALIGNMENTS, 'description' => 'Alignment of the text.'],
            ],
            'additionalProperties' => false,
        ];
    }
}
