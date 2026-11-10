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

use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Locale\TranslatableText;

/**
 * Whether an SVG may become an icon: a page keeps of an inline SVG only the
 * elements, and for each of them the attributes, that
 * `PublishedContentSanitizer::SVG_ELEMENTS` lists, so an icon that holds
 * anything else would be written in a page cut. This reads that list, it does
 * not copy it.
 *
 * It answers with what stands in the way, in words of an editor (the
 * technical name in brackets), and an empty list when the drawing is fit.
 * Namespace declarations (`xmlns`, `xmlns:*`) are not attributes for the XML
 * reader, so they never count.
 */
final readonly class IconEligibility
{
    private const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /** Elements named in an editor's words; the others are told by their name. */
    private const array ELEMENT_WORDS = [
        'lineargradient' => 'a gradient (%s)',
        'radialgradient' => 'a gradient (%s)',
        'stop' => 'a gradient (%s)',
        'pattern' => 'a pattern (%s)',
        'mask' => 'a mask (%s)',
        'clippath' => 'a clipping shape (%s)',
        'text' => 'some text (%s)',
        'tspan' => 'some text (%s)',
        'style' => 'CSS style (%s)',
        'use' => 'a reused shape (%s)',
        'defs' => 'a block of definitions (%s)',
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
        'style' => 'CSS style (%s)',
        'class' => 'CSS classes (%s)',
        'id' => 'an identifier (%s)',
        'clip-path' => 'a clipping (%s)',
        'mask' => 'a mask (%s)',
        'filter' => 'a filter effect (%s)',
    ];

    /**
     * @return list<TranslatableText> each obstacle once, in the order of the drawing
     */
    public function obstacles(string $svg): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = '' !== trim($svg) && $document->loadXML($svg, \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || !$document->documentElement instanceof \DOMElement) {
            return [TranslatableText::of('a file that cannot be read')];
        }

        $obstacles = [];
        $this->inspect($document->documentElement, $obstacles);

        return array_values($obstacles);
    }

    public function isFit(string $svg): bool
    {
        return [] === $this->obstacles($svg);
    }

    /**
     * @param array<string, TranslatableText> $obstacles
     */
    private function inspect(\DOMElement $element, array &$obstacles): void
    {
        $name = strtolower($element->localName ?? $element->nodeName);
        $allowed = PublishedContentSanitizer::SVG_ELEMENTS[$name] ?? null;

        // A prefixed element (`svg:path`) is not read as an SVG element by the parser of a page.
        if (null === $allowed || '' !== $element->prefix || (self::SVG_NAMESPACE !== $element->namespaceURI && '' !== (string) $element->namespaceURI)) {
            $subject = $element->nodeName;
            $obstacles['element:'.strtolower($subject)] ??= TranslatableText::of(self::ELEMENT_WORDS[$name] ?? 'an element a page does not keep (%s)', $subject);

            // What stands inside an element a page drops is no more to be read.
            return;
        }

        foreach ($element->attributes ?? [] as $attribute) {
            $attributeName = strtolower($attribute->nodeName);

            if (!\in_array($attributeName, $allowed, true)) {
                $obstacles['attribute:'.$attributeName] ??= TranslatableText::of(self::ATTRIBUTE_WORDS[$attributeName] ?? 'an attribute a page does not keep (%s)', $attribute->nodeName);

                continue;
            }

            // A page keeps a paint that is a color; one that points to a gradient or a pattern is dropped.
            if (\in_array($attributeName, ['fill', 'stroke'], true) && str_contains(strtolower($attribute->nodeValue ?? ''), 'url(')) {
                $obstacles['paint'] ??= TranslatableText::of('a gradient or a pattern as paint (%s)', $attribute->nodeName);
            }
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $this->inspect($child, $obstacles);
            } elseif ($child instanceof \DOMText && '' !== trim($child->data)) {
                $obstacles['text'] ??= TranslatableText::of('some text (%s)', 'text');
            }
        }
    }
}
