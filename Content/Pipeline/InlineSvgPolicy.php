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

namespace TheliaCMS\Content\Pipeline;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * What a page keeps of an SVG written in it: the icons a component of the
 * theme draws (`ux_icon()`) and the icons of the media library.
 *
 * Shapes, and the definitions that paint or cut them: gradients, masks and
 * clipping paths. Nothing that loads, links, runs or styles the rest of the
 * page: no `use`, `image`, `a`, `foreignObject`, `style`, `script`, `filter`
 * or `animate*`, and no event handler. A paint, a mask, a clipping path or the
 * `href` of a gradient points to an element of the same SVG, never to a file.
 *
 * Two copies of an icon in a page would share their ids, and the second would
 * draw with the definitions of the first, which may be hidden with its menu:
 * after the sanitizer of Symfony, `clean()` gives every id an SVG holds a
 * prefix of its own and drops a reference to an id the SVG does not hold.
 *
 * The sanitizer writes names in lowercase (`viewbox`, `lineargradient`); the
 * HTML parser of a browser puts their case back inside an `svg`.
 */
final readonly class InlineSvgPolicy
{
    /** What paints, on every shape and group. */
    private const array PRESENTATION = ['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'fill-opacity', 'opacity', 'mask', 'clip-path', 'shape-rendering'];

    /**
     * The elements kept and, for each, its own attributes.
     */
    public const array ELEMENTS = [
        'svg' => ['xmlns', 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'fill-opacity', 'fill-rule', 'clip-rule', 'opacity', 'mask', 'clip-path', 'shape-rendering'],
        'g' => [...self::PRESENTATION, 'fill-rule', 'clip-rule', 'transform'],
        'path' => ['d', ...self::PRESENTATION, 'fill-rule', 'clip-rule', 'transform'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'stroke-opacity', 'fill-opacity', 'opacity', 'mask', 'clip-path', 'transform', 'shape-rendering'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'stroke-opacity', 'fill-opacity', 'opacity', 'mask', 'clip-path', 'transform', 'shape-rendering'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'stroke-linejoin', 'stroke-opacity', 'fill-opacity', 'opacity', 'mask', 'clip-path', 'transform', 'shape-rendering'],
        'line' => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-dasharray', 'stroke-opacity', 'opacity', 'mask', 'clip-path', 'transform', 'shape-rendering'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'fill-opacity', 'opacity', 'mask', 'clip-path', 'transform', 'shape-rendering'],
        'polygon' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'fill-opacity', 'fill-rule', 'opacity', 'mask', 'clip-path', 'transform', 'shape-rendering'],
        'defs' => [],
        'lineargradient' => ['x1', 'y1', 'x2', 'y2', 'gradientunits', 'gradienttransform', 'spreadmethod', 'href'],
        'radialgradient' => ['cx', 'cy', 'r', 'fx', 'fy', 'fr', 'gradientunits', 'gradienttransform', 'spreadmethod', 'href'],
        'stop' => ['offset', 'stop-color', 'stop-opacity'],
        'mask' => ['x', 'y', 'width', 'height', 'maskunits', 'maskcontentunits'],
        'clippath' => ['clippathunits', 'transform'],
    ];

    /** Kept on every element of a page, an SVG one included. */
    public const array COMMON_ATTRIBUTES = ['class', 'id', 'style'];

    /** The attributes that hold a reference to an element of the same SVG. */
    public const array REFERENCES = ['fill', 'stroke', 'mask', 'clip-path', 'href'];

    /** The one namespace an inline SVG may declare. */
    public const string NAMESPACE = 'http://www.w3.org/2000/svg';

    /** An id a reference may name. */
    private const string ID = '[A-Za-z0-9_.:-]+';

    /** A hint of how the edges are drawn, nothing more. */
    private const array SHAPE_RENDERING = ['auto', 'optimizeSpeed', 'crispEdges', 'geometricPrecision'];

    private const string COLOR = '/^(?:[a-z]+|#[0-9a-f]{3,8}|(?:rgb|rgba|hsl|hsla)\([0-9.,%\s\/]++\))$/i';

    private const string TRANSFORM = '/^(?:(?:matrix|translate|scale|rotate|skewX|skewY)\([0-9eE.,+\-\s]*+\)\s*+(?:,\s*+)?+)++$/';

    /** The prefix `clean()` gives the ids of an SVG, read back (however many times it was given) so that cleaning twice changes nothing. */
    private const string PREFIX = '/^(?:s[0-9a-f]{8}-[0-9]+-)+/';

    /**
     * The tags that close an SVG in the parser of a browser: the HTML of a
     * page that a writer left inside an `svg`. The parser of PHP 8.3 keeps
     * them inside, the browser does not, and neither does `clean()`.
     */
    private const array LEAVES_SVG = ['b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var'];

    /** The elements whose content a mask or a clipping path must not mask or clip again. */
    private const array CUTTERS = ['mask', 'clippath'];

    /** The references that mask or clip, dropped inside a mask or a clipping path. */
    private const array CUTTING_REFERENCES = ['mask', 'clip-path'];

    /** A tag of the markup the sanitizer of Symfony writes: its values are quoted and escaped, never a raw `>`. */
    private const string TAG = '/<(\/?)([a-z][a-z0-9-]*)((?:\s[^>]*)?)>/';

    private const string ATTRIBUTE = '/\s([^\s="]+)(?:="([^"]*)")?/';

    /**
     * The attributes an element keeps: its own, those of every element, and
     * those the caller adds to every element (the accessibility ones).
     *
     * @param list<string> $everywhere
     *
     * @return list<string>|null null for an element the policy does not keep
     */
    public static function attributesOf(string $element, array $everywhere = []): ?array
    {
        $own = self::ELEMENTS[strtolower($element)] ?? null;

        return null === $own ? null : [...$own, ...self::COMMON_ATTRIBUTES, ...$everywhere];
    }

    /**
     * Whether a value has the form its attribute takes. A reference is only
     * read here as one (`url(#id)`, `#id`); whether the SVG holds that id is
     * the business of `clean()` and of the validation.
     */
    public static function acceptsValue(string $element, string $attribute, string $value): bool
    {
        $value = trim($value);

        return match (strtolower($attribute)) {
            'xmlns' => self::NAMESPACE === $value,
            'transform', 'gradienttransform' => 1 === preg_match(self::TRANSFORM, $value),
            'stop-color' => 1 === preg_match(self::COLOR, $value),
            'fill', 'stroke' => 1 === preg_match(self::COLOR, $value) || null !== self::reference($attribute, $value),
            'mask', 'clip-path' => 'none' === strtolower($value) || null !== self::reference($attribute, $value),
            'shape-rendering' => \in_array($value, self::SHAPE_RENDERING, true),
            'href' => !isset(self::ELEMENTS[strtolower($element)]) || null !== self::reference($attribute, $value),
            default => true,
        };
    }

    /**
     * The id a reference points to: `url(#id)` (quoted or not) for a paint, a
     * mask or a clipping path, `#id` for an `href`. Null when the value is not
     * a reference to an element of the same SVG.
     */
    public static function reference(string $attribute, string $value): ?string
    {
        $value = trim($value);
        $pattern = 'href' === strtolower($attribute)
            ? '/^#('.self::ID.')$/'
            : '/^url\(\s*(["\']?)#('.self::ID.')\1\s*\)$/i';

        if (1 !== preg_match($pattern, $value, $match)) {
            return null;
        }

        return 'href' === strtolower($attribute) ? $match[1] : $match[2];
    }

    /**
     * The sanitizer of Symfony told which elements and attributes it keeps,
     * and what each value of an SVG must look like.
     *
     * @param list<string> $everywhere the attributes the page keeps on every element
     */
    public function configure(HtmlSanitizerConfig $config, array $everywhere): HtmlSanitizerConfig
    {
        foreach (array_keys(self::ELEMENTS) as $element) {
            // An element with a list of its own loses the attributes allowed
            // on every element: the common ones are named again.
            $config = $config->allowElement($element, self::attributesOf($element, $everywhere) ?? []);
        }

        return $config->withAttributeSanitizer(new SvgAttributeSanitizer());
    }

    /**
     * Everything a page would not keep of an SVG file, in the order of the
     * drawing: the same rules as `configure()` and `clean()`, read on the
     * file instead of applied to a page. An empty list means the drawing is
     * written in a page whole, its ids aside.
     *
     * What stands inside an element a page drops is not read any further.
     * Namespace declarations (`xmlns`, `xmlns:*`) are not attributes for the
     * XML reader, so they never count.
     *
     * @param list<string> $everywhere the attributes the page keeps on every element
     *
     * @return list<InlineSvgViolation>
     */
    public function validate(string $svg, array $everywhere = []): array
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
            return [new InlineSvgViolation(InlineSvgViolationKind::Unreadable, '')];
        }

        $ids = [];
        self::collectIds($document->documentElement, $ids);
        $violations = [];
        $this->inspect($document->documentElement, $everywhere, $ids, false, $violations);

        return $violations;
    }

    /**
     * Gives every id of an SVG of the markup a prefix of its own and points
     * its references to them; a reference to an id the SVG does not hold is
     * dropped, and so is a mask or a clipping path asked for inside another
     * one, which would multiply the drawing at each level. No id of the SVG
     * keeps its name: a mask, a gradient or a clipping path the theme draws
     * outside the filter, or a page draws in another SVG, cannot resolve to
     * it.
     *
     * The prefix is drawn from the markup and the rank of the SVG in it, so
     * that the same markup is written the same way each time it is saved, and
     * cleaning twice changes nothing.
     *
     * It reads the markup the sanitizer of Symfony wrote, nothing else, one
     * SVG at a time: the rest of a page is copied as it is.
     */
    public function clean(string $html): string
    {
        if (1 !== preg_match('/<svg[\s>]/', $html)) {
            return $html;
        }

        $plain = $this->rewrite($html, static fn (int $rank): string => '');
        $hash = hash('xxh32', $plain);

        return $this->rewrite($plain, static fn (int $rank): string => 's'.$hash.'-'.$rank.'-');
    }

    /**
     * Whether a page keeps the element itself: an SVG element of the list,
     * written without a prefix (`svg:path` is not read as an SVG element by
     * the parser of a page).
     */
    private static function isKept(\DOMElement $element): bool
    {
        return isset(self::ELEMENTS[strtolower($element->localName ?? $element->nodeName)])
            && '' === $element->prefix
            && (self::NAMESPACE === $element->namespaceURI || '' === (string) $element->namespaceURI);
    }

    /**
     * The ids of the elements a page keeps: a reference to an id written on
     * an element it drops would point to nothing.
     *
     * @param array<string, true> $ids
     */
    private static function collectIds(\DOMElement $element, array &$ids): void
    {
        if (!self::isKept($element)) {
            return;
        }

        if ($element->hasAttribute('id')) {
            $ids[$element->getAttribute('id')] = true;
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                self::collectIds($child, $ids);
            }
        }
    }

    /**
     * @param list<string>             $everywhere
     * @param array<string, true>      $ids
     * @param bool                     $inCutter   whether the element stands inside a mask or a clipping path
     * @param list<InlineSvgViolation> $violations
     */
    private function inspect(\DOMElement $element, array $everywhere, array $ids, bool $inCutter, array &$violations): void
    {
        if (!self::isKept($element)) {
            $violations[] = new InlineSvgViolation(InlineSvgViolationKind::Element, $element->nodeName);

            return;
        }

        $name = strtolower($element->localName ?? $element->nodeName);
        $allowed = self::attributesOf($name, $everywhere) ?? [];

        foreach ($element->attributes ?? [] as $attribute) {
            $attributeName = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (!\in_array($attributeName, $allowed, true)) {
                $violations[] = new InlineSvgViolation(InlineSvgViolationKind::Attribute, $attribute->nodeName);
            } elseif (!self::acceptsValue($name, $attributeName, $value)) {
                $violations[] = new InlineSvgViolation(InlineSvgViolationKind::Value, $attribute->nodeName);
            } elseif ($inCutter && \in_array($attributeName, self::CUTTING_REFERENCES, true) && null !== self::reference($attributeName, $value)) {
                $violations[] = new InlineSvgViolation(InlineSvgViolationKind::NestedCut, $attribute->nodeName);
            } elseif (\in_array($attributeName, self::REFERENCES, true) && null !== ($reference = self::reference($attributeName, $value)) && !isset($ids[$reference])) {
                $violations[] = new InlineSvgViolation(InlineSvgViolationKind::Reference, $attribute->nodeName);
            } elseif ('style' === $attributeName && trim((new CssAllowList(urls: false))->declarations($value), " \t\n\r;") !== trim($value, " \t\n\r;")) {
                $violations[] = new InlineSvgViolation(InlineSvgViolationKind::Style, $attribute->nodeName);
            }
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $this->inspect($child, $everywhere, $ids, $inCutter || \in_array($name, self::CUTTERS, true), $violations);
            } elseif ($child instanceof \DOMText && '' !== trim($child->data)) {
                $violations[] = new InlineSvgViolation(InlineSvgViolationKind::Text, 'text');
            }
        }
    }

    /**
     * @param \Closure(int): string $prefixOf the prefix of the ids of the SVG at that rank
     */
    private function rewrite(string $html, \Closure $prefixOf): string
    {
        $written = '';
        $position = 0;
        $rank = 0;

        foreach (self::segments($html) as [$start, $end]) {
            $written .= substr($html, $position, $start - $position);
            $written .= self::rewriteSvg(substr($html, $start, $end - $start), $prefixOf(++$rank));
            $position = $end;
        }

        return $written.substr($html, $position);
    }

    /**
     * Where each SVG of the markup starts and ends, as a browser reads it: at
     * its closing tag, or at the first tag of HTML that leaves it. A tag is
     * read one at a time, so that a long page costs no more memory than one
     * of its tags.
     *
     * @return list<array{int, int}>
     */
    private static function segments(string $html): array
    {
        $segments = [];
        $offset = 0;

        while (1 === preg_match('/<svg[\s>]/', $html, $opening, \PREG_OFFSET_CAPTURE, $offset)) {
            $start = $opening[0][1];
            $end = \strlen($html);
            $position = $start;
            $depth = 0;

            while (1 === preg_match(self::TAG, $html, $tag, \PREG_OFFSET_CAPTURE, $position)) {
                [$whole, $at] = $tag[0];
                $closing = '/' === $tag[1][0];
                $name = $tag[2][0];
                $position = $at + \strlen($whole);

                if ('svg' === $name) {
                    $depth += $closing ? -1 : 1;

                    if (0 === $depth) {
                        $end = $position;

                        break;
                    }
                } elseif (self::leavesSvg($name, $closing, $tag[3][0])) {
                    $end = $at;

                    break;
                }
            }

            $segments[] = [$start, $end];
            $offset = $end;
        }

        return $segments;
    }

    /**
     * Whether a tag ends the SVG it is written in, in the parser of a
     * browser: one of the HTML elements that leave foreign content, a `font`
     * that sets a color, a face or a size, and the end tags of `p` and `br`.
     */
    private static function leavesSvg(string $name, bool $closing, string $attributes): bool
    {
        if ($closing) {
            return 'p' === $name || 'br' === $name;
        }

        return \in_array($name, self::LEAVES_SVG, true)
            || ('font' === $name && 1 === preg_match('/\s(?:color|face|size)[\s=]/', $attributes));
    }

    /**
     * One SVG: the ids it holds, then the SVG written again with every id
     * under the prefix and its references pointed to them.
     */
    private static function rewriteSvg(string $svg, string $prefix): string
    {
        $ids = [];
        $cutters = 0;

        preg_replace_callback(self::TAG, static function (array $tag) use (&$ids, &$cutters): string {
            self::readTag($tag, $cutters, static function (string $attribute, string $value) use (&$ids): void {
                if ('id' === $attribute) {
                    $ids[self::withoutPrefix($value)] = true;
                }
            });

            return $tag[0];
        }, $svg);

        $cutters = 0;

        return (string) preg_replace_callback(self::TAG, static function (array $tag) use ($ids, $prefix, &$cutters): string {
            $name = $tag[2];
            $svgElement = isset(self::ELEMENTS[$name]);
            $kept = '' === trim($tag[3]) ? $tag[3] : (string) preg_replace_callback(self::ATTRIBUTE, static function (array $match) use ($ids, $prefix, $svgElement, $cutters): string {
                $attribute = $match[1];

                if (!isset($match[2])) {
                    return $match[0];
                }

                $value = self::decoded($match[2]);

                if ('id' === $attribute) {
                    return ' id="'.self::encoded($prefix.self::withoutPrefix($value)).'"';
                }

                // A reference in a style would leave the SVG: no `url()`, no custom property.
                if ('style' === $attribute) {
                    return ' style="'.self::encoded((new CssAllowList(urls: false))->declarations($value)).'"';
                }

                // The `href` of a link is a link; that of a gradient points inside the SVG.
                if (!\in_array($attribute, self::REFERENCES, true) || ('href' === $attribute && !$svgElement)) {
                    return $match[0];
                }

                $reference = self::reference($attribute, $value);

                if (null === $reference) {
                    return $match[0];
                }

                $reference = self::withoutPrefix($reference);

                if (!isset($ids[$reference]) || null === self::followed($attribute, $value, $cutters > 0)) {
                    return '';
                }

                return ' '.$attribute.'="'.self::encoded('href' === $attribute ? '#'.$prefix.$reference : 'url(#'.$prefix.$reference.')').'"';
            }, $tag[3]);

            self::countCutters($tag[1], $name, $cutters);

            return '<'.$tag[1].$name.$kept.'>';
        }, $svg);
    }

    /**
     * Hands each attribute of a tag to the reader, with whether the tag
     * stands inside a mask or a clipping path, then counts the cutters.
     *
     * @param array<int, string>                   $tag
     * @param \Closure(string, string, bool): void $read
     */
    private static function readTag(array $tag, int &$cutters, \Closure $read): void
    {
        if ('' === $tag[1] && preg_match_all(self::ATTRIBUTE, $tag[3], $attributes, \PREG_SET_ORDER) > 0) {
            foreach ($attributes as $attribute) {
                // The `href` of a link is a link, not a reference.
                if (isset($attribute[2]) && ('href' !== $attribute[1] || isset(self::ELEMENTS[$tag[2]]))) {
                    $read($attribute[1], self::decoded($attribute[2]), $cutters > 0);
                }
            }
        }

        self::countCutters($tag[1], $tag[2], $cutters);
    }

    private static function countCutters(string $closing, string $name, int &$cutters): void
    {
        if (\in_array($name, self::CUTTERS, true)) {
            $cutters = max(0, $cutters + ('/' === $closing ? -1 : 1));
        }
    }

    /**
     * The id a reference points to when a page follows it: not a mask or a
     * clipping path asked for inside another one.
     */
    private static function followed(string $attribute, string $value, bool $inCutter): ?string
    {
        if (!\in_array($attribute, self::REFERENCES, true) || ($inCutter && \in_array($attribute, self::CUTTING_REFERENCES, true))) {
            return null;
        }

        return self::reference($attribute, $value);
    }

    private static function withoutPrefix(string $id): string
    {
        return (string) preg_replace(self::PREFIX, '', $id);
    }

    private static function decoded(string $value): string
    {
        return html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    private static function encoded(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
