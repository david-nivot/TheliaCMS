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
use TheliaCMS\Media\Svg\Optimization\Path\ShorterPathNotation;
use TheliaCMS\Media\Svg\Optimization\SvgDocument;

/**
 * Takes points out of the paths and polygons of a drawing where it hardly
 * turns (see StraightRuns) and rounds the rest to the fewest decimals that
 * keep each point within a tolerance, a share of the diagonal of the
 * viewBox. The positions are rounded where they stand, so a move written
 * relative to the point before starts from that point as rounded and no
 * error adds up along a path. Taking points out has what the rounding leaves
 * of the tolerance, so that both together keep every point within it.
 *
 * Only `d` and `points` change. The tolerance tightens by the strongest
 * enlargement the transforms apply to a shape, wherever it is drawn (see
 * ShapeContexts); a shape drawn somewhere at a scale of its own is left as
 * it is, and so is a drawing whose styles transform it. A shape whose line
 * ends or corners are drawn (a round or square cap, a marker) keeps all its
 * points and is only rounded.
 *
 * An arc is checked along its length once rounded (see SegmentRounding).
 *
 * A drawing with more path data than the ceiling, or a path holding more
 * numbers than the ceiling of PathData, is left as it is: the rounding takes
 * a time that grows with the data, and a memory that grows with the longest
 * path. Taking points out can take a time that grows with the square of a
 * run of points: the distances it measures are counted, over both precisions
 * tried, and past a budget no further point leaves (see DistanceBudget).
 */
final readonly class PathRounding
{
    public const int MAXIMUM_PATH_BYTES = 500_000;

    /**
     * Distances measured to take points out, per drawing, over both
     * precisions tried. The real drawing that measures the most takes half
     * of them (494,000).
     */
    public const int DISTANCE_BUDGET = 1_000_000;

    public function __construct(
        private PathData $paths = new PathData(),
        private ShorterPathNotation $notation = new ShorterPathNotation(),
        private StraightRuns $runs = new StraightRuns(),
        private ShapeContexts $contexts = new ShapeContexts(),
        private SegmentRounding $segments = new SegmentRounding(),
        private DecimalRounding $numbers = new DecimalRounding(),
        private int $distanceBudget = self::DISTANCE_BUDGET,
    ) {
    }

    /**
     * The drawing with fewer points and rounded, or as it was when nothing
     * comes out lighter.
     *
     * @param float $share tolerance, as a share of the diagonal of the viewBox
     */
    public function simplify(string $svg, float $share): string
    {
        $document = SvgDocument::load($svg);
        $shapes = null === $document || $this->exceedsCeilingOf($document) ? null : $this->contexts->of($document);
        $tolerance = null === $document || null === $shapes ? null : $this->tolerance($document, $shapes, $share);

        if (null === $document || null === $shapes || null === $tolerance) {
            return $svg;
        }

        $decimals = (int) ceil(-log10(2 * $tolerance));
        $budget = new DistanceBudget($this->distanceBudget);
        $best = $this->rewrittenAt($document, $shapes, $decimals, $tolerance, $budget) ?? $svg;
        // Rewritten in place, the document is read again for the other
        // precision: both would otherwise be held at once.
        unset($document, $shapes);

        // The fewest decimals can leave less than half of the tolerance to
        // take points out; one decimal more leaves most of it.
        $finer = 0.5 * 10 ** -$decimals * \M_SQRT2 > $tolerance / 2 ? SvgDocument::load($svg) : null;
        $finerShapes = null === $finer ? null : $this->contexts->of($finer);
        $other = null === $finer || null === $finerShapes ? null : $this->rewrittenAt($finer, $finerShapes, $decimals + 1, $tolerance, $budget);

        if (null !== $other && \strlen($other) < \strlen($best)) {
            $best = $other;
        }

        return \strlen($best) < \strlen($svg) ? $best : $svg;
    }

    /**
     * Whether the drawing holds more path data than the ceiling, or a path
     * holding more numbers than that of PathData, which a level leaves as it
     * is. A drawing that cannot be read does not.
     */
    public function exceedsCeiling(string $svg): bool
    {
        $document = SvgDocument::load($svg);

        return null !== $document && $this->exceedsCeilingOf($document);
    }

    private function exceedsCeilingOf(\DOMDocument $document): bool
    {
        $bytes = 0;

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (['d', 'points'] as $attribute) {
                $data = $element->getAttribute($attribute);
                $bytes += \strlen($data);

                if ($bytes > self::MAXIMUM_PATH_BYTES || $this->paths->exceedsCeiling($data)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The drawing with its points rounded to the given decimals and fewer of
     * them; null when no path came out shorter.
     *
     * @param list<DrawnShape> $shapes
     */
    private function rewrittenAt(\DOMDocument $document, array $shapes, int $decimals, float $tolerance, DistanceBudget $budget): ?string
    {
        // Rounding to k decimals moves a point by half a unit of the k-th
        // on each axis; taking points out has what remains.
        $straightening = $tolerance - 0.5 * 10 ** -$decimals * \M_SQRT2;
        $changed = false;

        foreach ($shapes as $shape) {
            if ($shape->ownScale) {
                continue;
            }

            $data = $shape->element->getAttribute($shape->attribute);
            $allowance = $straightening > 0.0 && !$shape->drawsEnds ? $straightening : 0.0;
            $rounded = 'd' === $shape->attribute ? $this->roundedPath($data, $decimals, $tolerance, $allowance, $budget) : $this->roundedPoints($data, $decimals, $allowance, $budget);

            if (null !== $rounded && \strlen($rounded) < \strlen($data)) {
                $shape->element->setAttribute($shape->attribute, $rounded);
                $changed = true;
            }
        }

        return $changed ? SvgDocument::serialize($document) : null;
    }

    /**
     * The tolerance in units of the drawing; null when the drawing cannot
     * be measured.
     *
     * @param list<DrawnShape> $shapes
     */
    private function tolerance(\DOMDocument $document, array $shapes, float $share): ?float
    {
        $root = $document->documentElement;
        $box = $root instanceof \DOMElement ? preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')), -1, \PREG_SPLIT_NO_EMPTY) : false;

        if (false === $box || 4 !== \count($box) || !is_numeric($box[2]) || !is_numeric($box[3])) {
            return null;
        }

        $enlargement = 1.0;

        foreach ($shapes as $shape) {
            $enlargement = $shape->ownScale ? $enlargement : max($enlargement, $shape->enlargement);
        }

        $tolerance = $share * hypot((float) $box[2], (float) $box[3]) / $enlargement;

        return is_finite($tolerance) && $tolerance > 0.0 ? $tolerance : null;
    }

    private function roundedPath(string $data, int $decimals, float $tolerance, float $straightening, DistanceBudget $budget): ?string
    {
        $drawn = $this->absolute($data);

        if (null === $drawn) {
            return null;
        }

        if ($straightening > 0.0) {
            $drawn = $this->runs->straighten($drawn, $straightening, $budget);
        }

        $rounded = $this->segments->rounded($drawn, $decimals, $tolerance, $straightening > 0.0);

        if (null === $rounded) {
            return null;
        }

        unset($drawn);
        $absolute = $this->paths->write($rounded);
        unset($rounded);

        return $this->notation->shorterData($absolute) ?? $absolute;
    }

    /**
     * The segments of a path with every position absolute: their values in
     * the order of their arguments, where they end and their control points;
     * null when the data cannot be read or a value is not finite (a number
     * too large for a float would be written as `inf`).
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

        // Each segment read is let go once turned: both forms of a long path
        // would otherwise be held at once.
        for ($position = 0, $count = \count($segments); $position < $count; ++$position) {
            $segment = $segments[$position];
            unset($segments[$position]);
            $command = strtoupper($segment->command);
            $values = [];
            $points = [];
            $next = $current;

            foreach (str_split(SegmentRounding::ROLES[$command]) as $index => $role) {
                $text = $segment->arguments[$index];

                if ('n' === $role || 'r' === $role) {
                    $values[] = 'n' === $role ? $text : (float) $text;

                    if (!is_finite((float) $text)) {
                        return null;
                    }

                    continue;
                }

                $axis = 'x' === $role ? 0 : 1;
                $next[$axis] = (float) $text + ($segment->isRelative() ? $current[$axis] : 0.0);
                $values[] = $next[$axis];

                if (!is_finite($next[$axis])) {
                    return null;
                }

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

    private function roundedPoints(string $list, int $decimals, float $straightening, DistanceBudget $budget): ?string
    {
        $listed = $this->paths->pointList($list);

        if (null === $listed) {
            return null;
        }

        $points = array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $listed);

        foreach ($points as [$x, $y]) {
            if (!is_finite($x) || !is_finite($y)) {
                return null;
            }
        }

        if ($straightening > 0.0) {
            $points = $this->runs->straightenedPoints($points, $straightening, $budget);
        }

        $numbers = [];

        foreach ($points as [$x, $y]) {
            $numbers[] = $this->numbers->rounded($x, $decimals);
            $numbers[] = $this->numbers->rounded($y, $decimals);
        }

        return implode(' ', $numbers);
    }
}
