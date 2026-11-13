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

namespace TheliaCMS\Media\Icon;

use TheliaCMS\Content\Pipeline\InlineSvgPolicy;
use TheliaCMS\Content\Pipeline\InlineSvgViolation;
use TheliaCMS\Content\Pipeline\InlineSvgViolationKind;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Locale\TranslatableText;

/**
 * Whether an SVG may become an icon: a page keeps of an inline SVG what
 * `InlineSvgPolicy` allows, so an icon that holds anything else would be
 * written in a page cut. This asks the policy, with the attributes the page
 * keeps on every element, and does not copy its rules.
 *
 * It answers with everything that stands in the way, in words of an editor
 * (the technical name in brackets), and an empty list when the drawing is fit.
 */
final readonly class IconEligibility
{
    /** Elements named in an editor's words; the others are told by their name. */
    private const array ELEMENT_WORDS = [
        'pattern' => 'a pattern (%s)',
        'text' => 'some text (%s)',
        'tspan' => 'some text (%s)',
        'style' => 'CSS style (%s)',
        'use' => 'a reused shape (%s)',
        'symbol' => 'a reusable symbol (%s)',
        'marker' => 'a line marker (%s)',
        'image' => 'an embedded image (%s)',
        'filter' => 'a filter effect (%s)',
        'foreignobject' => 'embedded HTML content (%s)',
        'script' => 'a script (%s)',
        'a' => 'a link (%s)',
        'title' => 'a title (%s)',
        'desc' => 'a description (%s)',
    ];

    private const array ATTRIBUTE_WORDS = [
        'filter' => 'a filter effect (%s)',
        'xlink:href' => 'an old way of pointing to a shape (%s)',
    ];

    /**
     * @return list<TranslatableText> each obstacle once, in the order of the drawing
     */
    public function obstacles(string $svg): array
    {
        $obstacles = [];

        foreach ((new InlineSvgPolicy())->validate($svg, PublishedContentSanitizer::ACCESSIBILITY_ATTRIBUTES) as $violation) {
            $obstacles[$violation->kind->value.':'.strtolower($violation->subject)] ??= self::said($violation);
        }

        return array_values($obstacles);
    }

    public function isFit(string $svg): bool
    {
        return [] === $this->obstacles($svg);
    }

    private static function said(InlineSvgViolation $violation): TranslatableText
    {
        $subject = $violation->subject;
        $name = strtolower($subject);

        return match ($violation->kind) {
            InlineSvgViolationKind::Unreadable => TranslatableText::of('a file that cannot be read'),
            InlineSvgViolationKind::Element => TranslatableText::of(self::ELEMENT_WORDS[$name] ?? 'an element a page does not keep (%s)', $subject),
            InlineSvgViolationKind::Attribute => TranslatableText::of(self::ATTRIBUTE_WORDS[$name] ?? 'an attribute a page does not keep (%s)', $subject),
            InlineSvgViolationKind::Value => TranslatableText::of(\in_array($name, ['fill', 'stroke', 'stop-color'], true) ? 'a color a page does not keep (%s)' : 'a value a page does not keep (%s)', $subject),
            InlineSvgViolationKind::Reference => TranslatableText::of('a reference to a shape the file does not hold (%s)', $subject),
            InlineSvgViolationKind::Style => TranslatableText::of('CSS style (%s)', $subject),
            InlineSvgViolationKind::Text => TranslatableText::of('some text (%s)', $subject),
        };
    }
}
