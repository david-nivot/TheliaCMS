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

namespace TheliaCMS\Tests\Unit\Media\Svg\Optimization\Rounding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Optimization\Path\PathData;
use TheliaCMS\Media\Svg\Optimization\Rounding\PathRounding;
use TheliaCMS\Media\Svg\Optimization\Rounding\SlightLossLevel;

final class PathSimplificationTest extends TestCase
{
    // A viewBox of 30 by 40, a diagonal of 50: at 1/400, a tolerance of
    // 0.125; one decimal kept, which moves a point by up to 0.071 and
    // leaves 0.054 to take points out.
    private const float SHARE = 1 / 400;

    private const float TOLERANCE = 0.125;

    /**
     * @return iterable<string, array{string}>
     */
    public static function drawings(): iterable
    {
        $wavy = 'M0 0';
        $bumpy = 'M0 20';

        for ($step = 1; $step <= 80; ++$step) {
            $wavy .= \sprintf('L%.4F %.4F', $step * .3, .04 * sin($step * 1.7) + ($step > 40 ? ($step - 40) * .2 : 0));
            $bumpy .= \sprintf('C%.4F %.4F %.4F %.4F %.4F %.4F', $step * .3 - .2, 20 + .03 * cos($step), $step * .3 - .1, 20 - .03 * sin($step), $step * .3, 20 + ($step % 9 ? 0 : .6));
        }

        yield 'a line that wavers then turns' => [$wavy];
        yield 'curves that hardly bend, and a few that do' => [$bumpy];
        yield 'relative commands, a closed shape' => ['m1.234 1.234l3.01.012 3.004-.011 2.998.014 3 2 .01 3.01-.012 2.99z'];
    }

    #[DataProvider('drawings')]
    public function testTheDrawingStaysWithinTheToleranceOfWhereItWas(string $data): void
    {
        $simplified = $this->simplifiedData($data);

        self::assertLessThan($this->pointCount($this->samples($data)), $this->pointCount($this->samples($simplified)), 'Points were taken out.');
        self::assertLessThanOrEqual(self::TOLERANCE + 1e-9, $this->farthest($this->samples($data), $this->samples($simplified)), 'From the original to the result.');
        self::assertLessThanOrEqual(self::TOLERANCE + 1e-9, $this->farthest($this->samples($simplified), $this->samples($data)), 'From the result to the original.');
    }

    public function testWhatTheRoundingMovesCountsInTheTolerance(): void
    {
        // The middle point lies 0.124 from the line between the ends, which
        // the rounding then moves 0.004 further away.
        $data = 'M0 .004L5 .128L10 .004';
        $simplified = $this->simplifiedData($data);

        self::assertLessThanOrEqual(self::TOLERANCE, $this->farthest($this->samples($data), $this->samples($simplified)), $simplified);
    }

    public function testPointsAlmostInLineLeave(): void
    {
        self::assertSame('M0 0H10V10', $this->simplifiedData('M0 0L5 .05L10 0L10 5L10 10'));
    }

    public function testACurveCloseToItsChordBecomesALine(): void
    {
        self::assertSame('M0 0H10', $this->simplifiedData('M0 0C3 .01 6-.01 10 0'));
    }

    public function testACurveThatBendsStaysACurve(): void
    {
        self::assertSame('M0 0C3 2 6 2 10 0', $this->simplifiedData('M0 0C3 2 6 2 10 0'));
    }

    public function testAnArcStaysAndALineRoundedToNothingLeaves(): void
    {
        self::assertSame('M0 0A1 1 0 012 2 1 1 0 014 4', $this->simplifiedData('M0 0A1 1 0 0 1 2 2L2.01 2.01A1 1 0 0 1 4 4'));
    }

    public function testASmoothCurveAndTheCurveItMirrorsStay(): void
    {
        self::assertSame('M0 0C3 0 6 0 10 0S20 5 20 10', $this->simplifiedData('M0 0C3 .01 6-.01 10 0S20 5 20 10'));
    }

    public function testALineRoundedToNothingBeforeASmoothCurveStays(): void
    {
        // Without the line, the smooth curve would mirror the control point of
        // the curve before it instead of starting from the current point.
        self::assertSame('M0 0C3 0 6 5 10 5l0 0s10-5 10 5', $this->simplifiedData('M0 0C3 0 6 5 10 5L10.01 5.01S20 0 20 10'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rewritings(): iterable
    {
        yield 'rounding alone' => ['round'];
        yield 'simplifying' => ['simplify'];
    }

    #[DataProvider('rewritings')]
    public function testASmoothCurveWhoseMirroredPointTheRoundingMovesTooFarWritesIt(string $rewriting): void
    {
        // Each end rounds 0.04 the other way, and the control point each
        // smooth curve mirrors drifts further: 0.04, 0.12, 0.2.
        $data = 'M0 0Q.5 1.04 1 .04T2 .06T3 .04T4 .06T5 .04';
        $output = (new PathRounding())->{$rewriting}($this->svg('<path d="'.$data.'"/>'), self::SHARE);

        self::assertSame(1, preg_match('/ d="([^"]*)"/', $output, $match));
        self::assertGreaterThan(1, preg_match_all('/[Qq]/', $match[1]), $match[1]);
        self::assertSame(\count($this->mirrored($data)), \count($this->mirrored($match[1])));

        foreach ($this->mirrored($data) as $index => [$x, $y]) {
            self::assertEqualsWithDelta($x, $this->mirrored($match[1])[$index][0], self::TOLERANCE + 1e-9, "x of control point $index");
            self::assertEqualsWithDelta($y, $this->mirrored($match[1])[$index][1], self::TOLERANCE + 1e-9, "y of control point $index");
        }
    }

    public function testAPolylineLosesThePointsAlmostInLine(): void
    {
        $output = (new PathRounding())->simplify($this->svg('<polyline points="0 0 5 .05 10 0 10 10"/>'), self::SHARE);

        self::assertStringContainsString('points="0 0 10 0 10 10"', $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function drawnEnds(): iterable
    {
        yield 'a round cap' => ['<path stroke-linecap="round" d="%s"/>', ''];
        yield 'a square cap on the group' => ['<g stroke-linecap="square"><path d="%s"/></g>', ''];
        yield 'a cap in a style attribute' => ['<g style="stroke:#000;stroke-linecap:round"><path d="%s"/></g>', ''];
        yield 'a marker at the end' => ['<path marker-end="url(#m)" d="%s"/>', ''];
        yield 'markers in a style sheet' => ['<path d="%s"/>', '<style>path{marker:url(#m)}</style>'];
    }

    #[DataProvider('drawnEnds')]
    public function testAShapeWhoseEndsAreDrawnKeepsEveryPoint(string $shape, string $sheet): void
    {
        $output = (new PathRounding())->simplify($this->svg($sheet.\sprintf($shape, 'M0 0L5 .05L10 0')), self::SHARE);

        self::assertStringContainsString('d="M0 0 5 .1 10 0"', $output, 'Rounded, every point kept.');
    }

    public function testAFlatCapAndNoMarkerLetPointsGo(): void
    {
        $output = (new PathRounding())->simplify($this->svg('<g stroke-linecap="butt" marker-end="none"><path d="M0 0L5 .05L10 0"/></g>'), self::SHARE);

        self::assertStringContainsString('d="M0 0H10"', $output);
    }

    public function testRoundingAloneKeepsEveryPoint(): void
    {
        $output = (new PathRounding())->round($this->svg('<path d="M0 0L5 .05L10 0"/>'), self::SHARE);

        self::assertStringContainsString('d="M0 0 5 .1 10 0"', $output);
    }

    public function testALevelTakesPointsOut(): void
    {
        $output = (new SlightLossLevel(new PathRounding()))->apply($this->svg('<path d="M0 0L5 .01L10 0"/>'));

        self::assertStringContainsString('d="M0 0H10"', $output);
    }

    /**
     * The control point of each quadratic curve, written or mirrored.
     *
     * @return list<array{float, float}>
     */
    private function mirrored(string $data): array
    {
        $segments = (new PathData())->segments($data);
        self::assertNotNull($segments);
        $current = [0.0, 0.0];
        $control = null;
        $controls = [];

        foreach ($segments as $segment) {
            $command = strtoupper($segment->command);
            $offset = $segment->isRelative() ? $current : [0.0, 0.0];
            $values = array_map(floatval(...), $segment->arguments);

            if ('Q' === $command) {
                $control = [$values[0] + $offset[0], $values[1] + $offset[1]];
                $current = [$values[2] + $offset[0], $values[3] + $offset[1]];
            } elseif ('T' === $command) {
                $control = null === $control ? $current : [2 * $current[0] - $control[0], 2 * $current[1] - $control[1]];
                $current = [$values[0] + $offset[0], $values[1] + $offset[1]];
            } else {
                $control = null;
                $current = [$values[0] + $offset[0], $values[1] + $offset[1]];

                continue;
            }

            $controls[] = $control;
        }

        return $controls;
    }

    private function svg(string $content): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 40">'.$content.'</svg>';
    }

    private function simplifiedData(string $data): string
    {
        $output = (new PathRounding())->simplify($this->svg('<path d="'.$data.'"/>'), self::SHARE);
        self::assertSame(1, preg_match('/ d="([^"]*)"/', $output, $match));

        return $match[1];
    }

    /**
     * The path as points along it: the ends of each line, and 32 points
     * along each curve. Moveto, lineto, curveto and closepath only.
     *
     * @return list<list<array{float, float}>> one list per subpath
     */
    private function samples(string $data): array
    {
        $segments = (new PathData())->segments($data);
        self::assertNotNull($segments);
        $current = $start = [0.0, 0.0];
        $subpaths = [];

        foreach ($segments as $segment) {
            $command = strtoupper($segment->command);
            self::assertContains($command, ['M', 'L', 'H', 'V', 'C', 'Z']);
            $offset = $segment->isRelative() ? $current : [0.0, 0.0];
            $values = array_map(floatval(...), $segment->arguments);

            [$points, $end] = match ($command) {
                'M', 'L' => [[], [$values[0] + $offset[0], $values[1] + $offset[1]]],
                'H' => [[], [$values[0] + $offset[0], $current[1]]],
                'V' => [[], [$current[0], $values[0] + $offset[1]]],
                'C' => [[[$values[0] + $offset[0], $values[1] + $offset[1]], [$values[2] + $offset[0], $values[3] + $offset[1]]], [$values[4] + $offset[0], $values[5] + $offset[1]]],
                default => [[], $start],
            };

            if ('M' === $command) {
                $subpaths[] = [$end];
                $start = $end;
                $current = $end;

                continue;
            }

            if ('C' === $command) {
                for ($step = 1; $step < 32; ++$step) {
                    $t = $step / 32;
                    $u = 1 - $t;
                    $subpaths[\count($subpaths) - 1][] = [
                        $u ** 3 * $current[0] + 3 * $u ** 2 * $t * $points[0][0] + 3 * $u * $t ** 2 * $points[1][0] + $t ** 3 * $end[0],
                        $u ** 3 * $current[1] + 3 * $u ** 2 * $t * $points[0][1] + 3 * $u * $t ** 2 * $points[1][1] + $t ** 3 * $end[1],
                    ];
                }
            }

            $subpaths[\count($subpaths) - 1][] = $end;
            $current = $end;
        }

        return $subpaths;
    }

    /**
     * The farthest any point of the first drawing lies from the lines of
     * the second.
     *
     * @param list<list<array{float, float}>> $from
     * @param list<list<array{float, float}>> $to
     */
    private function farthest(array $from, array $to): float
    {
        $farthest = 0.0;

        foreach ($from as $index => $subpath) {
            foreach ($subpath as $point) {
                $nearest = \INF;

                for ($vertex = 1, $count = \count($to[$index]); $vertex < $count; ++$vertex) {
                    [$a, $b] = [$to[$index][$vertex - 1], $to[$index][$vertex]];
                    [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
                    $length = $dx ** 2 + $dy ** 2;
                    $along = 0.0 === $length ? 0.0 : max(0.0, min(1.0, (($point[0] - $a[0]) * $dx + ($point[1] - $a[1]) * $dy) / $length));
                    $nearest = min($nearest, hypot($point[0] - $a[0] - $along * $dx, $point[1] - $a[1] - $along * $dy));
                }

                $farthest = max($farthest, $nearest);
            }
        }

        return $farthest;
    }

    /**
     * Number of points along a drawing, for the counts above.
     *
     * @param list<list<array{float, float}>> $subpaths
     */
    private function pointCount(array $subpaths): int
    {
        return array_sum(array_map(count(...), $subpaths));
    }
}
