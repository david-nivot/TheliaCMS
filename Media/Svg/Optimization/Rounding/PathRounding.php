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
 *
 * Simplifying also takes points out where the drawing hardly turns (see
 * StraightRuns), within what the rounding leaves of the tolerance, so that
 * both together keep every point within it. A shape whose line ends or
 * corners are drawn (a round or square cap, a marker) keeps all its points.
 *
 * A drawing with more path data than the ceiling is left as it is: the
 * work grows with the data, and the heaviest real drawings hold less than
 * three quarters of it.
 */
final readonly class PathRounding
{
    public const int MAXIMUM_PATH_BYTES = 500_000;

    private const array ROLES = [
        'M' => 'xy', 'L' => 'xy', 'H' => 'x', 'V' => 'y', 'C' => 'xyxyxy',
        'S' => 'xyxy', 'Q' => 'xyxy', 'T' => 'xy', 'A' => 'rrnnnxy', 'Z' => '',
    ];

    private const array OWN_SCALE = ['pattern', 'marker', 'symbol', 'svg'];

    private const array BOUNDING_BOX_UNITS = ['mask' => 'maskContentUnits', 'clipPath' => 'clipPathUnits'];

    private const string NUMBER = '[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?';

    // The property itself, not text-transform.
    private const string STYLE_TRANSFORM = '/(?<![\w-])transform\s*:/i';

    private const string STYLE_DRAWN_ENDS = '/(?<![\w-])(?:stroke-linecap\s*:\s*(?!butt\b)|marker(?:-start|-mid|-end)?\s*:\s*(?!none\b))/i';

    private const array DRAWN_END_ATTRIBUTES = ['stroke-linecap' => 'butt', 'marker' => 'none', 'marker-start' => 'none', 'marker-mid' => 'none', 'marker-end' => 'none'];

    public function __construct(
        private PathData $paths = new PathData(),
        private ShorterPathNotation $notation = new ShorterPathNotation(),
        private StraightRuns $runs = new StraightRuns(),
    ) {
    }

    /**
     * The drawing rounded, or as it was when nothing comes out lighter.
     *
     * @param float $share tolerance, as a share of the diagonal of the viewBox
     */
    public function round(string $svg, float $share): string
    {
        return $this->rewritten($svg, $share, false);
    }

    /**
     * The drawing with fewer points and rounded, or as it was when nothing
     * comes out lighter.
     *
     * @param float $share tolerance, as a share of the diagonal of the viewBox
     */
    public function simplify(string $svg, float $share): string
    {
        return $this->rewritten($svg, $share, true);
    }

    private function rewritten(string $svg, float $share, bool $simplifying): string
    {
        $document = SvgDocument::load($svg);
        $tolerance = null === $document || $this->pathBytes($document) > self::MAXIMUM_PATH_BYTES ? null : $this->tolerance($document, $share);

        if (null === $document || null === $tolerance) {
            return $svg;
        }

        $decimals = (int) ceil(-log10(2 * $tolerance));
        $best = $this->rewrittenAt($document, $decimals, $tolerance, $simplifying) ?? $svg;

        // The fewest decimals can leave less than half of the tolerance to
        // take points out; one decimal more leaves most of it.
        $finer = $simplifying && 0.5 * 10 ** -$decimals * \M_SQRT2 > $tolerance / 2 ? SvgDocument::load($svg) : null;
        $other = null === $finer ? null : $this->rewrittenAt($finer, $decimals + 1, $tolerance, true);

        if (null !== $other && \strlen($other) < \strlen($best)) {
            $best = $other;
        }

        return \strlen($best) < \strlen($svg) ? $best : $svg;
    }

    /**
     * The drawing with its points rounded to the given decimals and, given
     * a tolerance, fewer of them; null when no path came out shorter.
     */
    private function rewrittenAt(\DOMDocument $document, int $decimals, float $tolerance, bool $simplifying): ?string
    {
        // Rounding to k decimals moves a point by half a unit of the k-th
        // on each axis; taking points out has what remains.
        $straightening = !$simplifying || $this->styleDrawsEnds($document) ? 0.0 : $tolerance - 0.5 * 10 ** -$decimals * \M_SQRT2;
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
            $budget = $straightening > 0.0 && !$this->drawsEnds($element) ? $straightening : 0.0;
            $rounded = 'd' === $attribute ? $this->roundedPath($data, $decimals, $tolerance, $budget) : $this->roundedPoints($data, $decimals, $budget);

            if (null !== $rounded && \strlen($rounded) < \strlen($data)) {
                $element->setAttribute($attribute, $rounded);
                $changed = true;
            }
        }

        return $changed ? SvgDocument::serialize($document) : null;
    }

    private function pathBytes(\DOMDocument $document): int
    {
        $bytes = 0;

        foreach ($document->getElementsByTagName('*') as $element) {
            $bytes += \strlen($element->getAttribute('d')) + \strlen($element->getAttribute('points'));
        }

        return $bytes;
    }

    /**
     * The tolerance in units of the drawing; null when the drawing cannot
     * be measured.
     */
    private function tolerance(\DOMDocument $document, float $share): ?float
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

        return is_finite($tolerance) && $tolerance > 0.0 ? $tolerance : null;
    }

    private function styleDrawsEnds(\DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('style') as $style) {
            if (1 === preg_match(self::STYLE_DRAWN_ENDS, $style->textContent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the shape, or a group around it, draws the ends or the
     * corners of its lines: taking a point out would move what is drawn.
     */
    private function drawsEnds(\DOMElement $element): bool
    {
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            foreach (self::DRAWN_END_ATTRIBUTES as $attribute => $none) {
                if ($node->hasAttribute($attribute) && $none !== strtolower(trim($node->getAttribute($attribute)))) {
                    return true;
                }
            }

            if (1 === preg_match(self::STYLE_DRAWN_ENDS, $node->getAttribute('style'))) {
                return true;
            }
        }

        return false;
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

    private function roundedPath(string $data, int $decimals, float $tolerance, float $straightening): ?string
    {
        $drawn = $this->absolute($data);

        if (null === $drawn) {
            return null;
        }

        if ($straightening > 0.0) {
            $drawn = $this->runs->straighten($drawn, $straightening);
        }

        $rounded = [];
        $previousEnd = null;
        // The control point a smooth curve mirrors, as drawn and as rounded,
        // and the end of the segment before it.
        $mirrored = null;
        $origin = [0.0, 0.0];
        $roundedOrigin = [0.0, 0.0];

        foreach ($drawn as $position => $segment) {
            $roles = str_split(self::ROLES[$segment['command']]);
            $arguments = [];

            foreach ($segment['values'] as $index => $value) {
                $arguments[] = match ($roles[$index]) {
                    'n' => (string) $value,
                    'r' => $this->roundedRadius((float) $value, $decimals),
                    default => $this->rounded((float) $value, $decimals),
                };
            }

            $end = [$this->rounded($segment['end'][0], $decimals), $this->rounded($segment['end'][1], $decimals)];
            $command = $segment['command'];

            // A smooth curve mirrors a control point it does not write: once
            // rounded, its neighbours can move that point out of the
            // tolerance, and along a chain of them further. It is then written
            // with that point, from where the chain starts again.
            $control = $roundedControl = $segment['end'];

            if ('S' === $command || 'T' === $command) {
                $pair = 'S' === $command ? ['C', 'S'] : ['Q', 'T'];
                $follows = null !== $mirrored && \in_array($mirrored['after'], $pair, true);
                $implicit = $follows ? [2 * $origin[0] - $mirrored['drawn'][0], 2 * $origin[1] - $mirrored['drawn'][1]] : $origin;
                $roundedImplicit = $follows ? [2 * $roundedOrigin[0] - $mirrored['rounded'][0], 2 * $roundedOrigin[1] - $mirrored['rounded'][1]] : $roundedOrigin;

                if (max(abs($implicit[0] - $roundedImplicit[0]), abs($implicit[1] - $roundedImplicit[1])) > $tolerance) {
                    $command = 'S' === $command ? 'C' : 'Q';
                    array_unshift($arguments, $this->rounded($implicit[0], $decimals), $this->rounded($implicit[1], $decimals));
                    $roundedImplicit = [(float) $arguments[0], (float) $arguments[1]];
                }

                $control = 'S' === $segment['command'] ? [(float) $segment['values'][0], (float) $segment['values'][1]] : $implicit;
                $roundedControl = 'S' === $segment['command'] ? [(float) $arguments[\count($arguments) - 4], (float) $arguments[\count($arguments) - 3]] : $roundedImplicit;
            } elseif ('C' === $command || 'Q' === $command) {
                $at = 'C' === $command ? 2 : 0;
                $control = [(float) $segment['values'][$at], (float) $segment['values'][$at + 1]];
                $roundedControl = [(float) $arguments[$at], (float) $arguments[$at + 1]];
            }

            $mirrored = \in_array($command, ['C', 'S', 'Q', 'T'], true) ? ['after' => $segment['command'], 'drawn' => $control, 'rounded' => $roundedControl] : null;
            $origin = $segment['end'];
            $roundedOrigin = [(float) $end[0], (float) $end[1]];

            // A line that the rounding brought down to nothing leaves, unless
            // a smooth curve follows: it would then mirror the curve before.
            $next = $drawn[$position + 1]['command'] ?? '';

            if ($straightening > 0.0 && \in_array($segment['command'], ['L', 'H', 'V'], true) && $end === $previousEnd && !\in_array($next, ['S', 'T'], true)) {
                continue;
            }

            $rounded[] = new PathSegment('Z' === $command ? 'z' : $command, $arguments);
            $previousEnd = $end;
        }

        $absolute = $this->paths->write($rounded);

        return $this->notation->shorterData($absolute) ?? $absolute;
    }

    /**
     * The segments of a path with every position absolute: their values in
     * the order of their arguments, where they end and their control points.
     *
     * @return list<array{command: string, values: list<float|string>, end: array{float, float}, controls: list<array{float, float}>}>|null
     */
    private function absolute(string $data): ?array
    {
        $segments = $this->paths->segments($data);

        if (null === $segments) {
            return null;
        }

        $drawn = [];
        $current = [0.0, 0.0];
        $start = $current;

        foreach ($segments as $segment) {
            $command = strtoupper($segment->command);
            $values = [];
            $points = [];
            $next = $current;

            foreach (str_split(self::ROLES[$command]) as $index => $role) {
                $text = $segment->arguments[$index];

                if ('n' === $role || 'r' === $role) {
                    $values[] = 'n' === $role ? $text : (float) $text;

                    continue;
                }

                $axis = 'x' === $role ? 0 : 1;
                $next[$axis] = (float) $text + ($segment->isRelative() ? $current[$axis] : 0.0);
                $values[] = $next[$axis];

                if ('y' === $role) {
                    $points[] = $next;
                }
            }

            if ('M' === $command) {
                $start = $next;
            }

            $current = 'Z' === $command ? $start : $next;
            $controls = \in_array($command, ['C', 'S', 'Q'], true) ? \array_slice($points, 0, -1) : [];
            $drawn[] = ['command' => $command, 'values' => $values, 'end' => $current, 'controls' => $controls];
        }

        return $drawn;
    }

    private function roundedPoints(string $list, int $decimals, float $straightening): ?string
    {
        $listed = $this->paths->pointList($list);

        if (null === $listed) {
            return null;
        }

        $points = array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $listed);

        if ($straightening > 0.0) {
            $points = $this->runs->straightenedPoints($points, $straightening);
        }

        $numbers = [];

        foreach ($points as [$x, $y]) {
            $numbers[] = $this->rounded($x, $decimals);
            $numbers[] = $this->rounded($y, $decimals);
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
