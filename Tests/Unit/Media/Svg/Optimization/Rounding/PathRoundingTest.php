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

final class PathRoundingTest extends TestCase
{
    // A viewBox of 30 by 40, a diagonal of 50: at 1/400, a tolerance of
    // 0.125, one decimal kept.
    private const float SHARE = 1 / 400;

    private const float TOLERANCE = 0.125;

    #[DataProvider('paths')]
    public function testEveryPointStaysWithinTheToleranceOfWhereItWas(string $data): void
    {
        $rounded = $this->roundedData($data);

        self::assertLessThan(\strlen($data), \strlen($rounded));
        $before = $this->points($data);
        $after = $this->points($rounded);
        self::assertCount(\count($before), $after, 'The same commands, in the same number.');

        foreach ($before as $index => [$x, $y]) {
            self::assertEqualsWithDelta($x, $after[$index][0], self::TOLERANCE + 1e-9, "x of point $index");
            self::assertEqualsWithDelta($y, $after[$index][1], self::TOLERANCE + 1e-9, "y of point $index");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'absolute commands' => ['M1.23456 2.34567L10.98765 3.45678H12.3456V20.4321C13.111 14.222 15.333 16.444 17.555 18.666S19.777 20.888 21.999 22.111Q23.333 24.444 25.555 26.666T27.777 28.888Z'];
        yield 'relative commands' => ['m1.23456 2.34567l9.75309 1.11111h1.35795v16.98765c.7654 -6.2101 2.9876 -3.9881 5.2098 -1.7661s2.2222 2.2222 4.4444 3.4451q1.3334 2.3334 3.5556 4.5556t2.2222 2.2222z'];
        yield 'a long run of short relative moves' => ['M1.04 1.04'.str_repeat('l.04 .04', 200)];
    }

    public function testARelativeMoveStartsFromThePointAsRounded(): void
    {
        // Each .25 rounds to .3: rounding the moves themselves would end at 1.2.
        $points = $this->points($this->roundedData('M0 0l.25 3 .25 2 .25 3 .25 2'));

        self::assertEqualsWithDelta(1.0, $points[\count($points) - 1][0], 1e-9);
        self::assertEqualsWithDelta(10.0, $points[\count($points) - 1][1], 1e-9);
    }

    public function testAnArcKeepsItsFlagsAndAngleAndARadiusNeverRoundsToZero(): void
    {
        $segments = (new PathData())->segments($this->roundedData('M10.123 10.123A.004 5.537 33.333 1 0 20.123 10.123'));

        self::assertNotNull($segments);
        self::assertSame(['.1', '5.5', '33.333', '1', '0'], \array_slice($segments[1]->arguments, 0, 5));
    }

    public function testTheCeilingIsPassedOnlyByMoreThanTheMaximumOfPathData(): void
    {
        $rounding = new PathRounding();
        // `d` and `points` count, and nothing else.
        $atTheMaximum = $this->svg('<path d="'.str_repeat('M', PathRounding::MAXIMUM_PATH_BYTES - 5).'"/><polygon points="1,2 3"/>');
        $over = $this->svg('<path d="'.str_repeat('M', PathRounding::MAXIMUM_PATH_BYTES - 4).'"/><polygon points="1,2 3"/>');

        self::assertFalse($rounding->exceedsCeiling($atTheMaximum));
        self::assertTrue($rounding->exceedsCeiling($over));
        self::assertFalse($rounding->exceedsCeiling($this->svg('<path d="M1 1L2 2"/>')));
        self::assertFalse($rounding->exceedsCeiling('not an svg'), 'What cannot be read is not too detailed.');
    }

    public function testThePointsOfAPolygonAreRounded(): void
    {
        $output = (new PathRounding())->round($this->svg('<polygon points="1.234,1.236 2.341,2.347 3.111,1.009"/>'), self::SHARE);

        self::assertStringContainsString('points="1.2 1.2 2.3 2.3 3.1 1"', $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function enlargements(): iterable
    {
        yield 'none' => ['', 'M1.2 1.2 2.3 2.3'];
        yield 'a scale' => ['<g transform="scale(10)"/>', 'M1.23 1.24 2.34 2.35'];
        yield 'a matrix' => ['<g transform="matrix(2 0 0 10 5 5)"/>', 'M1.23 1.24 2.34 2.35'];
        yield 'a rotation then a scale' => ['<g transform="rotate(45) scale(4)"/>', 'M1.23 1.24 2.34 2.35'];
        yield 'a reduction' => ['<g transform="scale(.1)"/>', 'M1.2 1.2 2.3 2.3'];
        yield 'a style that only transforms text' => ['<g style="text-transform:none"/><style>text{text-transform:uppercase}</style>', 'M1.2 1.2 2.3 2.3'];
    }

    #[DataProvider('enlargements')]
    public function testAnEnlargingTransformKeepsMoreDecimals(string $transformed, string $expected): void
    {
        $output = (new PathRounding())->round($this->svg('<path d="M1.234 1.236L2.341 2.347"/>'.$transformed), self::SHARE);

        self::assertStringContainsString('d="'.$expected.'"', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leftAlone(): iterable
    {
        yield 'a pattern' => ['<pattern id="p"><path d="M1.234 1.236L2.341 2.347"/></pattern>'];
        yield 'a marker' => ['<marker id="m"><path d="M1.234 1.236L2.341 2.347"/></marker>'];
        yield 'a symbol' => ['<symbol id="s" viewBox="0 0 3 3"><path d="M1.234 1.236L2.341 2.347"/></symbol>'];
        yield 'a nested svg' => ['<svg viewBox="0 0 3 3"><path d="M1.234 1.236L2.341 2.347"/></svg>'];
        yield 'a mask in units of the bounding box' => ['<mask id="k" maskContentUnits="objectBoundingBox"><path d="M1.234 1.236L2.341 2.347"/></mask>'];
        yield 'a transform that cannot be read' => ['<g transform="scale(a)"/><path d="M1.234 1.236L2.341 2.347"/>'];
        yield 'a style that transforms' => ['<style>g{transform:scale(2)}</style><path d="M1.234 1.236L2.341 2.347"/>'];
    }

    #[DataProvider('leftAlone')]
    public function testADrawingAtAScaleOfItsOwnIsLeftAsItIs(string $content): void
    {
        $svg = $this->svg($content);

        self::assertStringContainsString('d="M1.234 1.236L2.341 2.347"', (new PathRounding())->round($svg, self::SHARE));
    }

    public function testADrawingWithNothingToRoundComesBackAsItWas(): void
    {
        $svg = $this->svg('<path d="M1 1L2 2"/>');

        self::assertSame($svg, (new PathRounding())->round($svg, self::SHARE));
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"><path d="M1.234 1.236L2.341 2.347"/></svg>', (new PathRounding())->round('<svg xmlns="http://www.w3.org/2000/svg"><path d="M1.234 1.236L2.341 2.347"/></svg>', self::SHARE), 'Without a viewBox, no tolerance.');
    }

    public function testRoundingTwiceChangesNothing(): void
    {
        $rounding = new PathRounding();
        $once = $rounding->round($this->svg('<path d="m1.23456 2.34567l9.75309 1.11111h1.35795v16.98765z"/><polyline points="1.234 1.236 2.341 2.347"/>'), self::SHARE);

        self::assertSame($once, $rounding->round($once, self::SHARE));
    }

    private function svg(string $content): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 40">'.$content.'</svg>';
    }

    private function roundedData(string $data): string
    {
        $output = (new PathRounding())->round($this->svg('<path d="'.$data.'"/>'), self::SHARE);
        self::assertSame(1, preg_match('/ d="([^"]*)"/', $output, $match));

        return $match[1];
    }

    /**
     * Every point of the path, control points included, the ends of a
     * horizontal or vertical line too.
     *
     * @return list<array{float, float}>
     */
    private function points(string $data): array
    {
        $segments = (new PathData())->segments($data);
        self::assertNotNull($segments);
        $roles = ['M' => 'xy', 'L' => 'xy', 'H' => 'x', 'V' => 'y', 'C' => 'xyxyxy', 'S' => 'xyxy', 'Q' => 'xyxy', 'T' => 'xy', 'A' => 'nnnnnxy', 'Z' => ''];
        $current = $start = [0.0, 0.0];
        $points = [];

        foreach ($segments as $segment) {
            $command = strtoupper($segment->command);
            $point = $current;

            foreach (str_split($roles[$command]) as $index => $role) {
                if ('n' === $role) {
                    continue;
                }

                $axis = 'x' === $role ? 0 : 1;
                $point[$axis] = (float) $segment->arguments[$index] + ($segment->isRelative() ? $current[$axis] : 0.0);

                if ('y' === $role || 'H' === $command) {
                    $points[] = $point;
                }
            }

            if ('M' === $command) {
                $start = $point;
            }

            $current = 'Z' === $command ? $start : $point;
        }

        return $points;
    }
}
