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

use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;

/**
 * The catalogue as the model reads it: every section the site offers, with
 * what it is for, its props and one example. Written in the same order at
 * every call, so the prefix of the prompt stays the same and the cache holds.
 */
final readonly class CatalogSheet
{
    public function __construct(
        private SectionCatalog $catalog,
    ) {
    }

    public function text(): string
    {
        $lines = ["# The catalogue\n", 'The components a section may name, with their props. `*` marks a required prop.', ''];

        foreach ($this->catalog->offered() as $definition) {
            $lines[] = $this->section($definition);
        }

        $lines[] = self::appearance();

        return implode("\n", $lines);
    }

    public function section(SectionDefinitionInterface $definition): string
    {
        $lines = [\sprintf('## `%s` — %s', $definition->name(), $definition->label()), '', $definition->description(), '', 'Props:'];

        foreach ($definition->schema()->props as $prop) {
            if (!$prop->editorOnly) {
                $lines[] = self::propLine($prop, '- ');
            }
        }

        $lines[] = '';
        $lines[] = 'Example:';
        $lines[] = '```json';
        $lines[] = json_encode(['id' => self::exampleId($definition->name()), 'component' => $definition->name(), 'props' => $definition->sample()], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $lines[] = '```';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The settings any section takes besides its props, the only way a brief
     * changes how a section looks: the components draw the rest.
     */
    private static function appearance(): string
    {
        return implode("\n", [
            '## The appearance of a section',
            '',
            'Any section may carry an `appearance` besides its props, when the brief asks for a section to look a certain way: a background, a colour of text, more room, an alignment. Leave it out otherwise, and keep the appearance a section already has unless the brief changes it.',
            '',
            '- `background`: colour of the band the section sits on, written `#rrggbb`. A colour named in words is written as its value: "pastel red" is a light, soft red such as `#f8d7d7`.',
            '- `text`: colour of the text, written `#rrggbb`, only when the background calls for it; a text that would be hard to read on the background is not acceptable.',
            \sprintf('- `padding`: room added above and below the section: %s.', implode(', ', array_map(static fn (string $value): string => '`'.$value.'`', array_keys(SectionAppearance::PADDINGS)))),
            \sprintf('- `align`: alignment of the text: %s.', implode(', ', array_map(static fn (string $value): string => '`'.$value.'`', SectionAppearance::ALIGNMENTS))),
            '',
            'Nothing else about the look of a section can be set: no other style, no class, no stylesheet.',
            '',
            'Example:',
            '```json',
            '{"id": "selection", "component": "…", "props": {"…": "…"}, "appearance": {"background": "#f8d7d7", "padding": "medium"}}',
            '```',
            '',
        ]);
    }

    private static function propLine(Prop $prop, string $indent): string
    {
        $line = \sprintf('%s`%s`%s (%s)', $indent, $prop->name, $prop->required ? '*' : '', self::typeOf($prop));

        if ('' !== $prop->label && $prop->label !== $prop->name) {
            $line .= ': '.$prop->label;
        }

        if (null !== $prop->help && '' !== $prop->help) {
            $line .= '. '.rtrim($prop->help, '.').'.';
        }

        if (PropType::Items === $prop->type && !$prop->scalarItems) {
            $items = array_map(static fn (Prop $item): string => self::propLine($item, $indent.'  - '), array_filter($prop->items, static fn (Prop $item): bool => !$item->editorOnly));
            $line .= "\n".implode("\n", $items);
        }

        return $line;
    }

    private static function typeOf(Prop $prop): string
    {
        return match ($prop->type) {
            PropType::Text => 'text'.(null !== $prop->max ? ', '.$prop->max.' characters at most' : ''),
            PropType::Html => 'html',
            PropType::Integer => \sprintf('integer, %d to %d', $prop->min ?? 0, $prop->max ?? 0),
            PropType::Boolean => 'true or false',
            PropType::Choice => 'one of '.implode(', ', array_map(static fn (string $value): string => '`'.$value.'`', $prop->offeredChoices())),
            PropType::Image => 'image id from search_media, an https address the brief gives, or null',
            PropType::Url => 'url',
            PropType::Items => $prop->scalarItems ? self::scalarListOf($prop) : \sprintf('list of %d to %d items', $prop->minItems ?? 0, $prop->maxItems ?? 12),
            PropType::Reference => 'id from the search tools',
            PropType::Json => 'JSON string',
        };
    }

    /**
     * A list of scalars is a list of strings, not of objects with one field.
     */
    private static function scalarListOf(Prop $prop): string
    {
        $item = $prop->items[0];
        $kind = PropType::Html === $item->type ? 'html strings' : 'strings';

        return \sprintf('list of %d to %d %s%s', $prop->minItems ?? 0, $prop->maxItems ?? 12, $kind, null !== $item->max ? ', each '.$item->max.' characters at most' : '');
    }

    private static function exampleId(string $component): string
    {
        $segments = explode(':', $component);

        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', end($segments) ?: 'section') ?? 'section');
    }
}
