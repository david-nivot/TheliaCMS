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

namespace TheliaCMS\Media\Svg\Optimization;

/**
 * Removes the clip an editor puts on every export: a clipPath holding one
 * rect that clips nothing, because it contains everything it clips, read
 * point by point. A rect that only covers the viewBox is not enough: an image
 * drawn in other proportions than its viewBox shows what lies beyond it, and
 * the clip hides it. At the slightest doubt the clip stays.
 */
final readonly class FigmaClipRemover
{
    private const array DRAWN = ['path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon'];

    private const array FORBIDDEN_ATTRIBUTES = [
        'transform', 'filter', 'mask', 'style', 'marker', 'marker-start', 'marker-mid', 'marker-end', 'vector-effect',
    ];

    public function __construct(
        private GeometryEnvelope $geometry,
        private SvgReferences $references,
    ) {
    }

    public function remove(\DOMDocument $document): bool
    {
        $root = $document->documentElement;

        if (!$root instanceof \DOMElement || [] !== iterator_to_array($document->getElementsByTagName('style'))) {
            return false;
        }

        $clipPaths = [];
        $clipped = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            if ('clipPath' === $element->localName && $element->hasAttribute('id')) {
                $clipPaths[$element->getAttribute('id')][] = $element;
            }

            if (null !== $this->clipId($element)) {
                $clipped[] = $element;
            }
        }

        $touched = [];

        foreach ($clipped as $element) {
            $id = (string) $this->clipId($element);
            $candidates = $clipPaths[$id] ?? [];

            if (1 !== \count($candidates) || !$this->clipsNothing($element, $candidates[0])) {
                continue;
            }

            $element->removeAttribute('clip-path');
            $touched[$id] = $candidates[0];
        }

        $references = $this->references->in($document);

        foreach ($touched as $id => $clipPath) {
            if (!$this->references->cites($references, $id)) {
                $clipPath->parentNode?->removeChild($clipPath);
            }
        }

        return [] !== $touched;
    }

    private function clipId(\DOMElement $element): ?string
    {
        if (!$element->hasAttribute('clip-path')) {
            return null;
        }

        $matched = preg_match('/^\s*url\(\s*[\'"]?#([^)\'"\s]+)[\'"]?\s*\)\s*$/', $element->getAttribute('clip-path'), $match);

        return 1 === $matched ? $match[1] : null;
    }

    private function clipsNothing(\DOMElement $element, \DOMElement $clipPath): bool
    {
        $rect = $this->clipRect($clipPath);

        if (null === $rect || $element->hasAttribute('transform') || $element->hasAttribute('style')) {
            return false;
        }

        $drawn = $this->drawnElements($element);

        if (null === $drawn) {
            return false;
        }

        $envelope = $this->envelope($drawn, $element);

        return null !== $envelope
            && $envelope[0] >= $rect[0] - 1e-9 && $envelope[1] >= $rect[1] - 1e-9
            && $envelope[2] <= $rect[2] + 1e-9 && $envelope[3] <= $rect[3] + 1e-9;
    }

    /**
     * @return array{float, float, float, float}|null left, top, right, bottom
     */
    private function clipRect(\DOMElement $clipPath): ?array
    {
        foreach (['transform', 'style', 'clip-path', 'mask', 'filter'] as $attribute) {
            if ($clipPath->hasAttribute($attribute)) {
                return null;
            }
        }

        if ('userSpaceOnUse' !== ($clipPath->getAttribute('clipPathUnits') ?: 'userSpaceOnUse')) {
            return null;
        }

        $children = array_values(array_filter(
            iterator_to_array($clipPath->childNodes),
            static fn (\DOMNode $node): bool => $node instanceof \DOMElement || ($node instanceof \DOMText && '' !== trim($node->data)) || $node instanceof \DOMCdataSection,
        ));

        if (1 !== \count($children) || !$children[0] instanceof \DOMElement || 'rect' !== $children[0]->localName) {
            return null;
        }

        $rect = $children[0];

        foreach (['transform', 'style', 'clip-path', 'mask', 'filter', 'rx', 'ry'] as $attribute) {
            if ($rect->hasAttribute($attribute)) {
                return null;
            }
        }

        $points = $this->geometry->pointsOf($rect);

        if (null === $points || $points[1][0] <= $points[0][0] || $points[1][1] <= $points[0][1]) {
            return null;
        }

        return [$points[0][0], $points[0][1], $points[1][0], $points[1][1]];
    }

    /**
     * The clipped element and what it holds, or null if one of them can
     * draw outside of its geometry or somewhere else (transform, use, text,
     * image, filter, mask, marker, style).
     *
     * @return list<\DOMElement>|null
     */
    private function drawnElements(\DOMElement $element): ?array
    {
        $elements = [];
        $stack = [$element];

        while ([] !== $stack) {
            $current = array_pop($stack);

            foreach (self::FORBIDDEN_ATTRIBUTES as $attribute) {
                if ($current->hasAttribute($attribute)) {
                    return null;
                }
            }

            if (!\in_array($current->localName, [...self::DRAWN, 'g', 'title', 'desc'], true) && $current !== $element) {
                return null;
            }

            if ($current === $element && !\in_array($current->localName, [...self::DRAWN, 'g'], true)) {
                return null;
            }

            $elements[] = $current;

            foreach ($current->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $stack[] = $child;
                }
            }
        }

        return $elements;
    }

    /**
     * Box around everything drawn under the clipped element, the stroke
     * included.
     *
     * @param list<\DOMElement> $drawn
     *
     * @return array{float, float, float, float}|null left, top, right, bottom
     */
    private function envelope(array $drawn, \DOMElement $clipped): ?array
    {
        $margin = $this->strokeMargin([...$drawn, ...$this->ancestors($clipped)]);
        $box = null;

        if (null === $margin) {
            return null;
        }

        foreach ($drawn as $element) {
            if (!\in_array($element->localName, self::DRAWN, true)) {
                continue;
            }

            $points = $this->geometry->pointsOf($element);

            if (null === $points) {
                return null;
            }

            foreach ($points as [$x, $y]) {
                $box = null === $box
                    ? [$x, $y, $x, $y]
                    : [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
            }
        }

        return null === $box ? null : [$box[0] - $margin, $box[1] - $margin, $box[2] + $margin, $box[3] + $margin];
    }

    /**
     * @return list<\DOMElement>
     */
    private function ancestors(\DOMElement $element): array
    {
        $ancestors = [];

        for ($parent = $element->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
            $ancestors[] = $parent;
        }

        return $ancestors;
    }

    /**
     * How far a stroke reaches beyond the geometry: twice the widest one,
     * which covers the default miter. Null when a value is unreadable.
     *
     * @param list<\DOMElement> $elements
     */
    private function strokeMargin(array $elements): ?float
    {
        $stroked = false;
        $width = 1.0;
        $widest = 0.0;
        $miter = 4.0;

        foreach ($elements as $element) {
            if ($element->hasAttribute('style')) {
                return null;
            }

            $stroke = strtolower(trim($element->getAttribute('stroke')));

            if ('' !== $stroke && 'none' !== $stroke) {
                $stroked = true;
            }

            foreach (['stroke-width', 'stroke-miterlimit'] as $name) {
                if (!$element->hasAttribute($name)) {
                    continue;
                }

                $value = $this->geometry->number($element->getAttribute($name));

                if (null === $value || $value < 0) {
                    return null;
                }

                if ('stroke-width' === $name) {
                    $widest = max($widest, $value);
                } else {
                    $miter = max($miter, $value);
                }
            }
        }

        if (!$stroked) {
            return 0.0;
        }

        return $miter / 2 * ($widest > 0 ? $widest : $width);
    }
}
