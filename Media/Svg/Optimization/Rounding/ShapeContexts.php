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

namespace TheliaCMS\Media\Svg\Optimization\Rounding;

use TheliaCMS\Media\Svg\Optimization\Path\PathData;

/**
 * Where and how each shape of a drawing is drawn. A shape is drawn in place,
 * under the groups around it, and again by every `use` that references it or
 * a group around it, under that `use` and the groups around it: each of
 * these contexts counts. A clip path or a mask in user space is drawn in
 * the space of each element that references it (`clip-path` or `mask`, as an
 * attribute or in `style`), and so counts like a `use`; one that a style
 * sheet references, wherever that is, draws at a scale of its own.
 *
 * Along a context, the enlargements of the transforms multiply (the
 * strongest stretch of each, an upper bound of that of their product); a cap
 * or a marker set anywhere along it draws the ends of the lines; a pattern,
 * marker, symbol, nested svg, or a mask or clip path in units of the bounding
 * box draws at a scale of its own, and so does a path that a text follows
 * (`textPath`) or a motion (`mpath`): its points place what follows it.
 *
 * A context is held as its enlargement, whether it draws ends and whether
 * it draws at a scale of its own.
 *
 * @phpstan-type Context array{float, bool, bool}
 */
final readonly class ShapeContexts
{
    private const array OWN_SCALE = ['pattern', 'marker', 'symbol', 'svg'];

    private const array BOUNDING_BOX_UNITS = ['mask' => 'maskContentUnits', 'clipPath' => 'clipPathUnits'];

    // The property itself, not text-transform.
    private const string STYLE_TRANSFORM = '/(?<![\w-])transform\s*:/i';

    private const array FOLLOWERS = ['textPath', 'mpath'];

    private const array CLIPPING_ATTRIBUTES = ['clip-path', 'mask'];

    private const string URL_REFERENCE = '/^\s*url\(\s*([\'"]?)#([^\'")\s]+)\1\s*\)/i';

    private const string STYLE_URL_REFERENCE = '/(?<![\w-])(?:clip-path|mask)\s*:\s*url\(\s*([\'"]?)#([^\'")\s]+)\1\s*\)/i';

    private const string STYLE_DRAWN_ENDS = '/(?<![\w-])(?:stroke-linecap\s*:\s*(?!butt\b)|marker(?:-start|-mid|-end)?\s*:\s*(?!none\b))/i';

    private const array DRAWN_END_ATTRIBUTES = ['stroke-linecap' => 'butt', 'marker' => 'none', 'marker-start' => 'none', 'marker-mid' => 'none', 'marker-end' => 'none'];

    /**
     * Every path, polyline and polygon that holds its data, in the order of
     * the document; null when a transform cannot be read or a style sheet
     * or a style attribute transforms the drawing. A style sheet that draws
     * ends draws them for every shape.
     *
     * @return list<DrawnShape>|null
     */
    public function of(\DOMDocument $document): ?array
    {
        $references = $this->references($document);

        if (null === $references) {
            return null;
        }

        [$referrers, $placedApart, $sheetDrawsEnds] = $references;
        $known = [];
        $shapes = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            $attribute = match ($element->localName) {
                'path' => 'd',
                'polyline', 'polygon' => 'points',
                default => null,
            };

            if (null === $attribute || !$element->hasAttribute($attribute)) {
                continue;
            }

            [$enlargement, $drawsEnds, $ownScale] = $this->context($element, $referrers, $placedApart, $known);
            $shapes[] = new DrawnShape($element, $attribute, $enlargement, $drawsEnds || $sheetDrawsEnds, $ownScale);
        }

        return $shapes;
    }

    /**
     * The elements that draw each identifier elsewhere (`use`, a clip path
     * or a mask referenced), the identifiers drawn at a scale of their own
     * (followed by a text or a motion, or referenced by a style sheet), and
     * whether a style sheet draws ends; null when the drawing is transformed
     * in a way that cannot be read.
     *
     * @return array{array<array-key, list<\DOMElement>>, array<array-key, true>, bool}|null
     */
    private function references(\DOMDocument $document): ?array
    {
        [$referrers, $placedApart, $sheetDrawsEnds] = [[], [], false];

        foreach ($document->getElementsByTagName('*') as $element) {
            if ('style' === $element->localName) {
                if (1 === preg_match(self::STYLE_TRANSFORM, $element->textContent)) {
                    return null;
                }

                $sheetDrawsEnds = $sheetDrawsEnds || 1 === preg_match(self::STYLE_DRAWN_ENDS, $element->textContent);
                preg_match_all(self::STYLE_URL_REFERENCE, $element->textContent, $matches);
                $placedApart += array_fill_keys($matches[2], true);
            }

            if (1 === preg_match(self::STYLE_TRANSFORM, $element->getAttribute('style'))) {
                return null;
            }

            if ($element->hasAttribute('transform') && null === $this->enlargementOf($element->getAttribute('transform'))) {
                return null;
            }

            foreach ([...'use' === $element->localName ? $this->targets($element) : [], ...$this->clippings($element)] as $target) {
                $referrers[$target][] = $element;
            }

            if (\in_array($element->localName, self::FOLLOWERS, true)) {
                $placedApart += array_fill_keys($this->targets($element), true);
            }
        }

        return [$referrers, $placedApart, $sheetDrawsEnds];
    }

    /**
     * The contexts of an element merged: the strongest enlargement, and
     * whether any of them draws ends or draws at a scale of its own. Each
     * element is worked out once; a reference that leads back to itself
     * counts as drawn at a scale of its own, and the shapes it reaches stay
     * as they are.
     *
     * @param array<array-key, list<\DOMElement>>          $referrers   the elements that draw each identifier elsewhere
     * @param array<array-key, true>                       $placedApart the identifiers drawn at a scale of their own
     * @param array<int, array{\DOMElement, Context|null}> $known       the elements worked out or under way, by object id, held so that no id is reused
     *
     * @return Context
     */
    private function context(\DOMElement $element, array $referrers, array $placedApart, array &$known): array
    {
        $key = spl_object_id($element);

        if (\array_key_exists($key, $known)) {
            return $known[$key][1] ?? [1.0, false, true];
        }

        $known[$key] = [$element, null];
        $parent = $element->parentNode;
        $around = $parent instanceof \DOMElement ? $this->context($parent, $referrers, $placedApart, $known) : [1.0, false, false];
        $id = $element->getAttribute('id');

        foreach ('' === $id ? [] : $referrers[$id] ?? [] as $referrer) {
            [$enlargement, $drawsEnds, $ownScale] = $this->context($referrer, $referrers, $placedApart, $known);
            $around = [max($around[0], $enlargement), $around[1] || $drawsEnds, $around[2] || $ownScale];
        }

        $own = $element->hasAttribute('transform') ? $this->enlargementOf($element->getAttribute('transform')) ?? 1.0 : 1.0;
        $apart = ('' !== $id && isset($placedApart[$id])) || $this->drawsAtAScaleOfItsOwn($element);
        $context = [$own * $around[0], $around[1] || $this->drawsEnds($element), $around[2] || $apart];
        $known[$key] = [$element, $context];

        return $context;
    }

    /**
     * The identifiers an element references in the document, by `href` or
     * `xlink:href`.
     *
     * @return list<string>
     */
    private function targets(\DOMElement $use): array
    {
        $targets = [];

        foreach ($use->attributes ?? [] as $attribute) {
            $value = trim($attribute->value);

            if ('href' === $attribute->localName && str_starts_with($value, '#') && \strlen($value) > 1) {
                $targets[] = substr($value, 1);
            }
        }

        return $targets;
    }

    /**
     * The clip paths and masks an element references, as attributes or in
     * its `style`.
     *
     * @return list<string>
     */
    private function clippings(\DOMElement $element): array
    {
        $targets = [];

        foreach (self::CLIPPING_ATTRIBUTES as $attribute) {
            if (1 === preg_match(self::URL_REFERENCE, $element->getAttribute($attribute), $match)) {
                $targets[] = $match[2];
            }
        }

        preg_match_all(self::STYLE_URL_REFERENCE, $element->getAttribute('style'), $matches);

        return [...$targets, ...$matches[2]];
    }

    /**
     * Whether the element itself sets a cap or a marker that draws the ends
     * or corners of lines: taking a point out would move what is drawn.
     */
    private function drawsEnds(\DOMElement $element): bool
    {
        foreach (self::DRAWN_END_ATTRIBUTES as $attribute => $none) {
            if ($element->hasAttribute($attribute) && $none !== strtolower(trim($element->getAttribute($attribute)))) {
                return true;
            }
        }

        return 1 === preg_match(self::STYLE_DRAWN_ENDS, $element->getAttribute('style'));
    }

    private function drawsAtAScaleOfItsOwn(\DOMElement $element): bool
    {
        if ($element === $element->ownerDocument?->documentElement) {
            return false;
        }

        if (\in_array($element->localName, self::OWN_SCALE, true)) {
            return true;
        }

        $units = self::BOUNDING_BOX_UNITS[$element->localName] ?? null;

        return null !== $units && 'objectBoundingBox' === trim($element->getAttribute($units));
    }

    /**
     * The factor by which a `transform` attribute enlarges what it holds,
     * the product of the strongest stretch of each of its functions; null
     * when it cannot be read.
     */
    private function enlargementOf(string $transform): ?float
    {
        $function = '/\s*(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)\s*,?/A';
        $offset = 0;
        $enlargement = 1.0;
        $length = \strlen($transform);

        while ($offset < $length && 1 === preg_match($function, $transform, $match, 0, $offset)) {
            $offset += \strlen($match[0]);
            preg_match_all('/'.PathData::NUMBER.'/', $match[2], $numbers);
            $values = array_map(floatval(...), $numbers[0]);

            $factor = match ($match[1]) {
                'translate', 'rotate' => 1.0,
                'scale' => [] === $values ? null : max(abs($values[0]), abs($values[1] ?? $values[0])),
                'skewX', 'skewY' => [] === $values ? null : $this->largestStretch(1.0, 0.0, tan(deg2rad($values[0])), 1.0),
                default => 6 === \count($values) ? $this->largestStretch($values[0], $values[1], $values[2], $values[3]) : null,
            };

            if (null === $factor) {
                return null;
            }

            $enlargement *= $factor;
        }

        return '' === trim(substr($transform, $offset)) && is_finite($enlargement) ? $enlargement : null;
    }

    /**
     * Largest singular value of the linear part [[a c] [b d]].
     */
    private function largestStretch(float $a, float $b, float $c, float $d): float
    {
        $sum = $a ** 2 + $b ** 2 + $c ** 2 + $d ** 2;
        $determinant = $a * $d - $b * $c;

        return sqrt(($sum + sqrt(max(0.0, $sum ** 2 - 4 * $determinant ** 2))) / 2);
    }
}
