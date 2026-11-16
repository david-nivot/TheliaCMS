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

use TheliaCMS\Media\Svg\Optimization\Path\ExactDecimal;
use TheliaCMS\Media\Svg\Optimization\Path\PathData;
use TheliaCMS\Media\Svg\Optimization\Path\PathSegment;
use TheliaCMS\Media\Svg\Optimization\Path\ShorterPathNotation;
use TheliaCMS\Media\Svg\Optimization\SvgDocument;

/**
 * Rounds the points of the paths and polygons of a drawing to the fewest
 * decimals that keep each point within a tolerance, a share of the diagonal
 * of the viewBox. The positions are rounded where they stand, so a move
 * written relative to the point before starts from that point as rounded
 * and no error adds up along a path.
 *
 * Only `d` and `points` change. The tolerance tightens by the strongest
 * enlargement a `transform` attribute of the drawing applies; a content
 * drawn at a scale of its own (pattern, marker, symbol, nested svg, units of
 * the bounding box) is left as it is, and so is a drawing whose styles
 * transform it.
 */
final readonly class PathRounding
{
    private const array ROLES = [
        'M' => 'xy', 'L' => 'xy', 'H' => 'x', 'V' => 'y', 'C' => 'xyxyxy',
        'S' => 'xyxy', 'Q' => 'xyxy', 'T' => 'xy', 'A' => 'rrnnnxy', 'Z' => '',
    ];

    private const array OWN_SCALE = ['pattern', 'marker', 'symbol', 'svg'];

    private const array BOUNDING_BOX_UNITS = ['mask' => 'maskContentUnits', 'clipPath' => 'clipPathUnits'];

    private const string NUMBER = '[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?';

    // The property itself, not text-transform.
    private const string STYLE_TRANSFORM = '/(?<![\w-])transform\s*:/i';

    public function __construct(
        private PathData $paths = new PathData(),
        private ShorterPathNotation $notation = new ShorterPathNotation(),
    ) {
    }

    /**
     * The drawing rounded, or as it was when nothing comes out lighter.
     *
     * @param float $share tolerance, as a share of the diagonal of the viewBox
     */
    public function round(string $svg, float $share): string
    {
        $document = SvgDocument::load($svg);
        $decimals = null === $document ? null : $this->decimals($document, $share);

        if (null === $document || null === $decimals) {
            return $svg;
        }

        $changed = false;

        foreach ($document->getElementsByTagName('*') as $element) {
            $attribute = match ($element->localName) {
                'path' => 'd',
                'polyline', 'polygon' => 'points',
                default => null,
            };

            if (null === $attribute || !$element->hasAttribute($attribute) || $this->drawnAtAScaleOfItsOwn($element)) {
                continue;
            }

            $data = $element->getAttribute($attribute);
            $rounded = 'd' === $attribute ? $this->roundedPath($data, $decimals) : $this->roundedPoints($data, $decimals);

            if (null !== $rounded && \strlen($rounded) < \strlen($data)) {
                $element->setAttribute($attribute, $rounded);
                $changed = true;
            }
        }

        if (!$changed) {
            return $svg;
        }

        $rounded = SvgDocument::serialize($document);

        return \strlen($rounded) < \strlen($svg) ? $rounded : $svg;
    }

    /**
     * The fewest decimals whose rounding stays within the tolerance; null
     * when the drawing cannot be measured.
     */
    private function decimals(\DOMDocument $document, float $share): ?int
    {
        $root = $document->documentElement;
        $box = $root instanceof \DOMElement ? preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')), -1, \PREG_SPLIT_NO_EMPTY) : false;

        if (false === $box || 4 !== \count($box) || !is_numeric($box[2]) || !is_numeric($box[3])) {
            return null;
        }

        $enlargement = $this->strongestEnlargement($document);

        if (null === $enlargement) {
            return null;
        }

        $tolerance = $share * hypot((float) $box[2], (float) $box[3]) / max(1.0, $enlargement);

        if (!is_finite($tolerance) || $tolerance <= 0.0) {
            return null;
        }

        // Rounding to k decimals moves a value by half a unit of the k-th.
        return (int) ceil(-log10(2 * $tolerance));
    }

    /**
     * The largest factor by which a `transform` attribute enlarges what it
     * holds, the attributes read one by one; null when one cannot be read
     * or a style sheet transforms the drawing.
     */
    private function strongestEnlargement(\DOMDocument $document): ?float
    {
        $strongest = 1.0;

        foreach ($document->getElementsByTagName('*') as $element) {
            if ('style' === $element->localName && 1 === preg_match(self::STYLE_TRANSFORM, $element->textContent)) {
                return null;
            }

            if (1 === preg_match(self::STYLE_TRANSFORM, $element->getAttribute('style'))) {
                return null;
            }

            if (!$element->hasAttribute('transform')) {
                continue;
            }

            $enlargement = $this->enlargementOf($element->getAttribute('transform'));

            if (null === $enlargement) {
                return null;
            }

            $strongest = max($strongest, $enlargement);
        }

        return $strongest;
    }

    private function enlargementOf(string $transform): ?float
    {
        $function = '/\s*(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)\s*,?/A';
        $offset = 0;
        $enlargement = 1.0;
        $length = \strlen($transform);

        while ($offset < $length && 1 === preg_match($function, $transform, $match, 0, $offset)) {
            $offset += \strlen($match[0]);
            preg_match_all('/'.self::NUMBER.'/', $match[2], $numbers);
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

        return '' === trim(substr($transform, $offset)) ? $enlargement : null;
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

    private function drawnAtAScaleOfItsOwn(\DOMElement $element): bool
    {
        for ($ancestor = $element->parentNode; $ancestor instanceof \DOMElement && $ancestor !== $element->ownerDocument?->documentElement; $ancestor = $ancestor->parentNode) {
            if (\in_array($ancestor->localName, self::OWN_SCALE, true)) {
                return true;
            }

            $units = self::BOUNDING_BOX_UNITS[$ancestor->localName] ?? null;

            if (null !== $units && 'objectBoundingBox' === trim($ancestor->getAttribute($units))) {
                return true;
            }
        }

        return false;
    }

    private function roundedPath(string $data, int $decimals): ?string
    {
        $segments = $this->paths->segments($data);

        if (null === $segments) {
            return null;
        }

        $rounded = [];
        $current = [0.0, 0.0];
        $start = $current;

        foreach ($segments as $segment) {
            $command = strtoupper($segment->command);
            $arguments = [];
            $next = $current;

            foreach (str_split(self::ROLES[$command]) as $index => $role) {
                $text = $segment->arguments[$index];

                if ('n' === $role) {
                    $arguments[] = $text;

                    continue;
                }

                $value = (float) $text;

                if ('r' === $role) {
                    $arguments[] = $this->roundedRadius($value, $decimals);

                    continue;
                }

                $axis = 'x' === $role ? 0 : 1;
                $value += $segment->isRelative() ? $current[$axis] : 0.0;
                $next[$axis] = $value;
                $arguments[] = $this->rounded($value, $decimals);
            }

            $rounded[] = new PathSegment('Z' === $command ? 'z' : $command, $arguments);

            if ('M' === $command) {
                $start = $next;
            }

            $current = 'Z' === $command ? $start : $next;
        }

        $absolute = $this->paths->write($rounded);

        return $this->notation->shorterData($absolute) ?? $absolute;
    }

    private function roundedPoints(string $list, int $decimals): ?string
    {
        $points = $this->paths->pointList($list);

        if (null === $points) {
            return null;
        }

        $numbers = [];

        foreach ($points as [$x, $y]) {
            $numbers[] = $this->rounded((float) $x, $decimals);
            $numbers[] = $this->rounded((float) $y, $decimals);
        }

        return implode(' ', $numbers);
    }

    // A radius never rounds to zero: a zero radius draws a straight line,
    // where a radius too short to join the ends is enlarged by the renderer.
    private function roundedRadius(float $radius, int $decimals): string
    {
        $rounded = $this->rounded($radius, $decimals);

        if ('0' !== $rounded || 0.0 === $radius) {
            return $rounded;
        }

        return ExactDecimal::fromText(($radius < 0 ? '-' : '').'1e'.(-$decimals))?->toText() ?? $rounded;
    }

    private function rounded(float $value, int $decimals): string
    {
        $text = number_format(round($value, $decimals), max(0, $decimals), '.', '');

        return ExactDecimal::fromText($text)?->toText() ?? $text;
    }
}
