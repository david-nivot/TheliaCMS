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

/**
 * Takes points out of a path where the drawing hardly turns: a curve whose
 * control points lie within the tolerance of its chord becomes a line, and
 * the points of a run of lines that stay within the tolerance of a longer
 * line leave it (Ramer-Douglas-Peucker, with an explicit stack).
 *
 * Every distance is taken from the drawing as it came in, control points
 * included: a curve lies within the hull of its control points, so a line
 * within the tolerance of all of them stays within the tolerance of the
 * curve. The ends of a run are kept. An arc, a moveto and a closepath end a
 * run, and so does a smooth curve and the curve whose control point it
 * mirrors, which are left as they are.
 *
 * Every distance measured is counted in a budget: past it, the rest of the
 * drawing keeps its points.
 *
 * @phpstan-type Point array{float, float}
 * @phpstan-type Drawn array{command: string, values: list<float|string>, end: Point, controls: list<Point>}
 */
final readonly class StraightRuns
{
    private const array CANDIDATES = ['L', 'H', 'V', 'C', 'Q'];

    private const array LINES = ['L', 'H', 'V'];

    private const array SMOOTH = ['S', 'T'];

    /**
     * @param list<Drawn> $segments absolute, in the order of the path
     *
     * @return list<Drawn>
     */
    public function straighten(array $segments, float $tolerance, DistanceBudget $budget): array
    {
        $straightened = [];
        $count = \count($segments);
        $index = 0;

        while ($index < $count) {
            if (0 === $index || !$this->isCandidate($segments, $index)) {
                $straightened[] = $segments[$index];
                ++$index;

                continue;
            }

            $end = $index;

            while ($end + 1 < $count && $this->isCandidate($segments, $end + 1)) {
                ++$end;
            }

            array_push($straightened, ...$this->straightenedRun($segments[$index - 1]['end'], \array_slice($segments, $index, $end - $index + 1), $tolerance, $budget));
            $index = $end + 1;
        }

        return $straightened;
    }

    /**
     * The points of an open line that stay, its ends always among them.
     *
     * @param list<Point> $points
     *
     * @return list<Point>
     */
    public function straightenedPoints(array $points, float $tolerance, DistanceBudget $budget): array
    {
        if (\count($points) < 3) {
            return $points;
        }

        $kept = [];

        foreach ($this->spans($points, array_fill(0, \count($points), []), $tolerance, $budget) as [$from, $to]) {
            $kept[] = $points[$from];
        }

        $kept[] = $points[\count($points) - 1];

        return $kept;
    }

    /**
     * @param list<Drawn> $segments
     */
    private function isCandidate(array $segments, int $index): bool
    {
        $next = $segments[$index + 1]['command'] ?? '';

        return \in_array($segments[$index]['command'], self::CANDIDATES, true) && !\in_array($next, self::SMOOTH, true);
    }

    /**
     * @param Point       $start
     * @param list<Drawn> $run
     *
     * @return list<Drawn>
     */
    private function straightenedRun(array $start, array $run, float $tolerance, DistanceBudget $budget): array
    {
        $vertices = [$start];
        $controls = [[]];

        foreach ($run as $segment) {
            $vertices[] = $segment['end'];
            $controls[] = $segment['controls'];
        }

        $straightened = [];

        foreach ($this->spans($vertices, $controls, $tolerance, $budget) as [$from, $to, $isLine]) {
            $kept = $run[$to - 1];

            if (!$isLine || ($to === $from + 1 && \in_array($kept['command'], self::LINES, true))) {
                $straightened[] = $kept;

                continue;
            }

            $straightened[] = ['command' => 'L', 'values' => [$kept['end'][0], $kept['end'][1]], 'end' => $kept['end'], 'controls' => []];
        }

        return $straightened;
    }

    /**
     * The spans of the run, in order: a span that holds within the
     * tolerance of the line between its ends is drawn as that line; a span
     * of one segment that does not stays as it is, and so does each segment
     * of a span the budget can no longer measure.
     *
     * @param list<Point>       $vertices
     * @param list<list<Point>> $controls the control points of the segment that ends on each vertex
     *
     * @return list<array{int, int, bool}>
     */
    private function spans(array $vertices, array $controls, float $tolerance, DistanceBudget $budget): array
    {
        $spans = [];
        $pending = [[0, \count($vertices) - 1]];
        // The control points up to each vertex, to price a span before it is measured.
        $controlsUpTo = [];
        $sum = 0;

        foreach ($controls as $vertex => $points) {
            $sum += \count($points);
            $controlsUpTo[$vertex] = $sum;
        }

        while ([] !== $pending) {
            [$from, $to] = array_pop($pending);

            if (!$budget->spend($to - $from - 1 + $controlsUpTo[$to] - $controlsUpTo[$from])) {
                for ($vertex = $from; $vertex < $to; ++$vertex) {
                    $spans[] = [$vertex, $vertex + 1, false];
                }

                continue;
            }
            $farthest = 0.0;
            $split = null;
            $splitDistance = -1.0;

            for ($vertex = $from + 1; $vertex <= $to; ++$vertex) {
                foreach ($controls[$vertex] as $control) {
                    $farthest = max($farthest, $this->distance($control, $vertices[$from], $vertices[$to]));
                }

                if ($vertex < $to) {
                    $distance = $this->distance($vertices[$vertex], $vertices[$from], $vertices[$to]);
                    $farthest = max($farthest, $distance);

                    if ($distance > $splitDistance) {
                        [$split, $splitDistance] = [$vertex, $distance];
                    }
                }
            }

            if ($farthest <= $tolerance || null === $split) {
                $spans[] = [$from, $to, $farthest <= $tolerance];

                continue;
            }

            $pending[] = [$split, $to];
            $pending[] = [$from, $split];
        }

        return $spans;
    }

    /**
     * Distance from a point to the segment between two others.
     *
     * @param Point $point
     * @param Point $from
     * @param Point $to
     */
    private function distance(array $point, array $from, array $to): float
    {
        [$dx, $dy] = [$to[0] - $from[0], $to[1] - $from[1]];
        $length = $dx ** 2 + $dy ** 2;
        $along = 0.0 === $length ? 0.0 : max(0.0, min(1.0, (($point[0] - $from[0]) * $dx + ($point[1] - $from[1]) * $dy) / $length));

        return hypot($point[0] - $from[0] - $along * $dx, $point[1] - $from[1] - $along * $dy);
    }
}
