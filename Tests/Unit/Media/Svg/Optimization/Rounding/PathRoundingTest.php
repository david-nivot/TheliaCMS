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
use TheliaCMS\Media\Svg\Optimization\Rounding\ArcRounding;
use TheliaCMS\Media\Svg\Optimization\Rounding\PathRounding;
use TheliaCMS\Media\Svg\Optimization\Rounding\SegmentRounding;

final class PathRoundingTest extends TestCase
{
    // A viewBox of 30 by 40, a diagonal of 50: at 1/400, a tolerance of
    // 0.125, one decimal kept.
    private const float SHARE = 1 / 400;

    private const float TOLERANCE = 0.125;

    // A round cap draws the ends of the lines: every point stays, and the
    // tests below see the rounding alone.
    private const string DRAWN_ENDS = ' stroke-linecap="round"';

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
        $segments = (new PathData())->segments($this->roundedData('M10.123 10.123A.04 .06 33.333 1 0 10.523 10.123'));

        self::assertNotNull($segments);
        self::assertSame(['33.333', '1', '0'], \array_slice($segments[1]->arguments, 2, 3));
        self::assertNotSame('0', $segments[1]->arguments[0]);
        self::assertNotSame('0', $segments[1]->arguments[1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function arcs(): iterable
    {
        // Rounded as the points are, the radius of 4.98 joins ends 10 apart
        // only once enlarged to 5: the centre falls on the chord, and the top
        // of the arc moves by 0.426.
        yield 'a radius that the rounding makes too short' => ['M.04 5A4.98 4.98 0 1 0 9.96 5'];
        yield 'a radius rounded by a third of its length' => ['M0 0A1.04 1.04 0 0 1 2 0'];
        yield 'a radius floored at a unit of the last decimal' => ['M10.123 10.123A.004 5.537 33.333 1 0 20.123 10.123'];
        yield 'a relative arc, and a relative line after it' => ['m.04 5a4.98 4.98 0 1 0 9.92 0l.0123 7.0123'];
        yield 'an arc after a close' => ['M.04 5h3.0123v3.0123zA4.98 4.98 0 1 0 9.96 5'];
    }

    #[DataProvider('arcs')]
    public function testAnArcStaysWithinTheToleranceAllAlongIt(string $data): void
    {
        $rounded = $this->roundedData($data);

        self::assertLessThan(\strlen($data), \strlen($rounded), 'Rounded, not left as written.');
        self::assertLessThanOrEqual(self::TOLERANCE + 1e-9, $this->farthestAlongArcs($data, $rounded), $rounded);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function strayingArcs(): iterable
    {
        yield 'after a moveto' => ['M.004 5A4.998 4.998 0 1 0 9.996 5', 'M.004 5'];
        yield 'relative, and a relative line after it' => ['m.004 5a4.998 4.998 0 1 0 9.992 0l.0123 7.0123', 'M.004 5'];
        yield 'after a close' => ['M.004 5h3.0123v3.0123zA4.998 4.998 0 1 0 9.996 5', 'M.004 5'];
        yield 'after a line' => ['M1.23 1.23L.004 5A4.998 4.998 0 1 0 9.996 5', 'M1.23 1.23.004 5'];
    }

    #[DataProvider('strayingArcs')]
    public function testAnArcThatStraysFromWhereItNowStartsKeepsThatStart(string $data, string $start): void
    {
        // A viewBox with a diagonal of 18: a tolerance of 0.045, two decimals,
        // which leave enough of the tolerance that one more is not tried.
        $output = (new PathRounding())->simplify('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10.8 14.4"><path'.self::DRAWN_ENDS.' d="'.$data.'"/></svg>', self::SHARE);
        self::assertSame(1, preg_match('/ d="([^"]*)"/', $output, $match));

        self::assertStringStartsWith($start, $match[1]);
        self::assertLessThan(\strlen($data), \strlen($match[1]), 'Rounded, not left as written.');
        self::assertLessThanOrEqual(.045 + 1e-9, $this->farthestAlongArcs($data, $match[1]), $match[1]);
    }

    public function testEachArcThatStraysResumesFromTheSegmentBeforeIt(): void
    {
        // A chord just shorter than the diameter: a move of the start of
        // .04 moves the centre by some 4. Each arc strays from the line
        // before it once rounded, and that line keeps its end; the rounding
        // resumes from that line, not from the start of the path, so each
        // arc is checked some ten times over both precisions (resumed from
        // the start, 1.76 million times for these thousand arcs).
        $arcs = 1_000;
        $data = 'M0 0'.str_repeat('l.06 0a1000 1000 0 0 1 1999.99 0', $arcs);
        $geometry = new CountedArcGeometry();
        $output = (new PathRounding(segments: new SegmentRounding(new ArcRounding($geometry))))->simplify($this->svg('<path d="'.$data.'"/>'), self::SHARE);

        self::assertSame(1, preg_match('/ d="([^"]*)"/', $output, $match));
        self::assertLessThan(\strlen($data), \strlen($match[1]), 'Rounded, not left as written.');
        self::assertLessThanOrEqual(20 * $arcs, $geometry->checks);
    }

    public function testAnArcThatHoldsOnceRoundedIsRounded(): void
    {
        // Too short to join the ends, either radius is enlarged to 5: the
        // rounding only moves the arc by 0.01.
        self::assertSame('M0 5A4 4 0 1010 5', $this->roundedData('M.01 5A4.01 4.01 0 1 0 10.01 5'));
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

    public function testAPathHoldingMoreNumbersThanTheCeilingOfPathDataLeavesTheDrawingAsItIs(): void
    {
        $rounding = new PathRounding();
        // Each segment of h1v1 holds one number, the cheapest to write.
        $long = $this->svg('<path d="M0 0'.str_repeat('h1.01v1.01', intdiv(PathData::MAXIMUM_NUMBERS, 2)).'"/><path d="M1.234 1.236L2.341 2.347"/>');
        $within = $this->svg('<path d="M0 0'.str_repeat('h1.01v1.01', intdiv(PathData::MAXIMUM_NUMBERS, 2) - 1).'"/><path d="M1.234 1.236L2.341 2.347"/>');

        self::assertTrue($rounding->exceedsCeiling($long));
        self::assertSame($long, $rounding->simplify($long, self::SHARE));
        self::assertFalse($rounding->exceedsCeiling($within));
    }

    public function testThePointsOfAPolygonAreRounded(): void
    {
        $output = (new PathRounding())->simplify($this->svg('<polygon points="1.234,1.236 2.341,2.347 3.111,1.009"/>'), self::SHARE);

        self::assertStringContainsString('points="1.2 1.2 2.3 2.3 3.1 1"', $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notFinite(): iterable
    {
        yield 'a coordinate' => ['<path d="M1e400 0L5.123 5.123"/>', 'd="M1e400 0L5.123 5.123"'];
        yield 'a relative move that adds up past a float' => ['<path d="M1e308 0l1e308 0L5.123 5.123"/>', 'd="M1e308 0l1e308 0L5.123 5.123"'];
        yield 'an arc angle' => ['<path d="M0 0A1.123 1.123 1e400 0 1 2.123 0"/>', 'd="M0 0A1.123 1.123 1e400 0 1 2.123 0"'];
        yield 'a point of a polygon' => ['<polygon points="1e400 0 5.123 5.123 9.123 1.123"/>', 'points="1e400 0 5.123 5.123 9.123 1.123"'];
        // Their squares are zero for a float: the arc cannot be measured.
        yield 'the ends of an arc too close for a float' => ['<path d="M0 0A1 1 0 0 1 1e-300 0"/>', 'd="M0 0A1 1 0 0 1 1e-300 0"'];
        yield 'the radii of an arc too short for a float' => ['<path d="M0 0A1e-200 1e-200 0 0 1 1 0"/>', 'd="M0 0A1e-200 1e-200 0 0 1 1 0"'];
    }

    #[DataProvider('notFinite')]
    public function testAValueBeyondWhatAFloatMeasuresLeavesTheShapeAsItIs(string $shape, string $expected): void
    {
        $output = (new PathRounding())->simplify($this->svg($shape.'<path d="M1.234 1.236L2.341 2.347"/>'), self::SHARE);

        self::assertStringContainsString($expected, $output);
        self::assertStringNotContainsStringIgnoringCase('inf', $output);
        self::assertStringContainsString('d="M1.2 1.2 2.3 2.3"', $output, 'The other shapes are rounded.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function enlargements(): iterable
    {
        yield 'none' => ['<g>%s</g>', 'M1.2 1.2 2.3 2.3'];
        yield 'a scale' => ['<g transform="scale(10)">%s</g>', 'M1.23 1.24 2.34 2.35'];
        yield 'a matrix' => ['<g transform="matrix(2 0 0 10 5 5)">%s</g>', 'M1.23 1.24 2.34 2.35'];
        yield 'a rotation then a scale' => ['<g transform="rotate(45) scale(4)">%s</g>', 'M1.23 1.24 2.34 2.35'];
        yield 'a reduction' => ['<g transform="scale(.1)">%s</g>', 'M1.2 1.2 2.3 2.3'];
        yield 'a style that only transforms text' => ['<g style="text-transform:none">%s</g><style>text{text-transform:uppercase}</style>', 'M1.2 1.2 2.3 2.3'];
        yield 'a scale on the shape itself' => ['%s', 'M1.23 1.24 2.34 2.35', ' transform="scale(10)"'];
        // Ten times ten: a tolerance of 0.00125, three decimals; ten alone keeps two.
        yield 'nested scales multiply' => ['<g transform="scale(10)"><g transform="scale(10)">%s</g></g>', 'M1.235 1.236 2.341 2.347'];
        yield 'a group that holds no shape enlarges nothing' => ['%s<g transform="scale(100)"/>', 'M1.2 1.2 2.3 2.3'];
    }

    #[DataProvider('enlargements')]
    public function testAnEnlargingTransformKeepsMoreDecimals(string $around, string $expected, string $onTheShape = ''): void
    {
        $output = (new PathRounding())->simplify($this->svg(\sprintf($around, '<path'.$onTheShape.' d="M1.23456 1.23567L2.34123 2.34657"/>')), self::SHARE);

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
        yield 'a use in a marker' => ['<defs><path id="p" d="M1.234 1.236L2.341 2.347"/></defs><marker id="m"><use href="#p"/></marker>'];
        yield 'a use in a clip path in units of the bounding box' => ['<defs><g id="g"><path d="M1.234 1.236L2.341 2.347"/></g></defs><clipPath id="c" clipPathUnits="objectBoundingBox"><use xlink:href="#g" xmlns:xlink="http://www.w3.org/1999/xlink"/></clipPath>'];
        yield 'a use that references itself' => ['<g id="g"><path d="M1.234 1.236L2.341 2.347"/><use href="#g"/></g>'];
        yield 'a path a text follows' => ['<path id="t" d="M1.234 1.236L2.341 2.347"/><text><textPath href="#t">A</textPath></text>'];
        yield 'a path a motion follows' => ['<path id="t" d="M1.234 1.236L2.341 2.347"/><circle r="1"><animateMotion dur="1s"><mpath xlink:href="#t" xmlns:xlink="http://www.w3.org/1999/xlink"/></animateMotion></circle>'];
        yield 'a clip path a style sheet references' => ['<style>.c{clip-path:url(#c)}</style><clipPath id="c"><path d="M1.234 1.236L2.341 2.347"/></clipPath><g transform="scale(10)"><rect class="c" width="9" height="9"/></g>'];
    }

    #[DataProvider('leftAlone')]
    public function testADrawingAtAScaleOfItsOwnIsLeftAsItIs(string $content): void
    {
        $svg = $this->svg($content);

        self::assertStringContainsString('d="M1.234 1.236L2.341 2.347"', (new PathRounding())->simplify($svg, self::SHARE));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uses(): iterable
    {
        yield 'a use that scales the shape' => ['<defs><path id="p" d="M1.23456 1.23567L2.34123 2.34657"/></defs><use href="#p" transform="scale(10)"/>', 'M1.23 1.24 2.34 2.35'];
        yield 'the groups around a use multiply' => ['<defs><path id="p" d="M1.23456 1.23567L2.34123 2.34657"/></defs><g transform="scale(10)"><use href="#p" transform="scale(10)"/></g>', 'M1.235 1.236 2.341 2.347'];
        yield 'a use of a group, by xlink:href' => ['<defs><g id="g" transform="scale(10)"><path d="M1.23456 1.23567L2.34123 2.34657"/></g></defs><use xlink:href="#g" xmlns:xlink="http://www.w3.org/1999/xlink" transform="scale(10)"/>', 'M1.235 1.236 2.341 2.347'];
        yield 'a use of a use' => ['<defs><path id="p" d="M1.23456 1.23567L2.34123 2.34657"/><use id="u" href="#p" transform="scale(10)"/></defs><use href="#u" transform="scale(10)"/>', 'M1.235 1.236 2.341 2.347'];
    }

    #[DataProvider('uses')]
    public function testAUseCountsTheEnlargementOfWhereItDraws(string $content, string $expected): void
    {
        self::assertStringContainsString('d="'.$expected.'"', (new PathRounding())->simplify($this->svg($content), self::SHARE));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function clippings(): iterable
    {
        yield 'a clip path' => ['<defs><clipPath id="c"><path d="M1.23456 1.23567L2.34123 2.34657"/></clipPath></defs><g transform="scale(10)"><rect clip-path="url(#c)" width="9" height="9"/></g>', 'M1.23 1.24 2.34 2.35'];
        yield 'a mask, in a style' => ['<mask id="m"><path d="M1.23456 1.23567L2.34123 2.34657"/></mask><g transform="scale(10)"><rect style="fill:red;mask: url(\'#m\')" width="9" height="9"/></g>', 'M1.23 1.24 2.34 2.35'];
        yield 'the transform of the element that references it' => ['<clipPath id="c"><path d="M1.23456 1.23567L2.34123 2.34657"/></clipPath><g transform="scale(10)"><rect clip-path="url(#c)" transform="scale(10)" width="9" height="9"/></g>', 'M1.235 1.236 2.341 2.347'];
        yield 'a clip path referenced through a use' => ['<clipPath id="c"><path d="M1.23456 1.23567L2.34123 2.34657"/></clipPath><defs><rect id="r" clip-path="url(#c)" width="9" height="9"/></defs><use href="#r" transform="scale(10)"/>', 'M1.23 1.24 2.34 2.35'];
        yield 'a clip path in place' => ['<clipPath id="c" transform="scale(10)"><path d="M1.23456 1.23567L2.34123 2.34657"/></clipPath><rect clip-path="url(#c)" width="9" height="9"/>', 'M1.23 1.24 2.34 2.35'];
    }

    #[DataProvider('clippings')]
    public function testAClipPathOrAMaskCountsTheEnlargementOfWhatReferencesIt(string $content, string $expected): void
    {
        self::assertStringContainsString('d="'.$expected.'"', (new PathRounding())->simplify($this->svg($content), self::SHARE));
    }

    public function testADrawingWithNothingToRoundComesBackAsItWas(): void
    {
        $svg = $this->svg('<path d="M1 1L2 2"/>');

        self::assertSame($svg, (new PathRounding())->simplify($svg, self::SHARE));
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"><path d="M1.234 1.236L2.341 2.347"/></svg>', (new PathRounding())->simplify('<svg xmlns="http://www.w3.org/2000/svg"><path d="M1.234 1.236L2.341 2.347"/></svg>', self::SHARE), 'Without a viewBox, no tolerance.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nothingToTakeOut(): iterable
    {
        yield 'corners and a polyline' => ['<path d="m1.23456 2.34567l9.75309 1.11111h1.35795v16.98765z"/><polyline points="1.234 1.236 2.341 2.347"/>'];
        yield 'curves that bend and an arc' => ['<path d="M1.234 1.236C5.111 9.222 9.333 9.444 13.555 1.666S19.777 9.888 21.999 1.111A3.21 3.21 0 0 1 28.123 1.123"/>'];
        yield 'ends drawn' => ['<path'.self::DRAWN_ENDS.' d="M0 0L5.0123 .0512L10.0123 0"/>'];
    }

    #[DataProvider('nothingToTakeOut')]
    public function testSimplifyingTwiceADrawingWithNothingToTakeOutChangesNothing(string $content): void
    {
        $rounding = new PathRounding();
        $once = $rounding->simplify($this->svg($content), self::SHARE);

        self::assertNotSame($this->svg($content), $once);
        self::assertSame($once, $rounding->simplify($once, self::SHARE));
    }

    private function svg(string $content): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 40">'.$content.'</svg>';
    }

    private function roundedData(string $data): string
    {
        $output = (new PathRounding())->simplify($this->svg('<path'.self::DRAWN_ENDS.' d="'.$data.'"/>'), self::SHARE);
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

    /**
     * The farthest a point of either path lies from the other, arcs drawn
     * as a renderer draws them. Moveto, lineto, arc and closepath only.
     */
    private function farthestAlongArcs(string $data, string $other): float
    {
        $from = $this->polyline($data);
        $to = $this->polyline($other);

        return max($this->farthestFrom($from, $to), $this->farthestFrom($to, $from));
    }

    /**
     * @return list<array{float, float}>
     */
    private function polyline(string $data): array
    {
        $segments = (new PathData())->segments($data);
        self::assertNotNull($segments);
        $current = $start = [0.0, 0.0];
        $points = [];

        foreach ($segments as $segment) {
            $command = strtoupper($segment->command);
            self::assertContains($command, ['M', 'L', 'H', 'V', 'A', 'Z']);
            $offset = $segment->isRelative() ? $current : [0.0, 0.0];
            $values = array_map(floatval(...), $segment->arguments);
            $end = match ($command) {
                'H' => [$values[0] + $offset[0], $current[1]],
                'V' => [$current[0], $values[0] + $offset[1]],
                'A' => [$values[5] + $offset[0], $values[6] + $offset[1]],
                'Z' => $start,
                default => [$values[0] + $offset[0], $values[1] + $offset[1]],
            };

            if ('A' === $command) {
                array_push($points, ...$this->arc($current, $values, $end));
            }

            $points[] = $end;
            $start = 'M' === $command ? $end : $start;
            $current = $end;
        }

        return $points;
    }

    /**
     * Points along an arc, from the endpoint to the centre parameterization
     * of the SVG specification (F.6.5), radii enlarged when too short.
     *
     * @param array{float, float} $from
     * @param list<float>         $values
     * @param array{float, float} $to
     *
     * @return list<array{float, float}>
     */
    private function arc(array $from, array $values, array $to): array
    {
        [$rx, $ry, $angle, $large, $sweep] = [abs($values[0]), abs($values[1]), deg2rad($values[2]), 1.0 === $values[3], 1.0 === $values[4]];
        $x1 = cos($angle) * ($from[0] - $to[0]) / 2 + sin($angle) * ($from[1] - $to[1]) / 2;
        $y1 = -sin($angle) * ($from[0] - $to[0]) / 2 + cos($angle) * ($from[1] - $to[1]) / 2;
        $lambda = $x1 ** 2 / $rx ** 2 + $y1 ** 2 / $ry ** 2;
        [$rx, $ry] = $lambda > 1 ? [$rx * sqrt($lambda), $ry * sqrt($lambda)] : [$rx, $ry];
        $root = ($large === $sweep ? -1 : 1) * sqrt(max(0.0, ($rx ** 2 * $ry ** 2 - $rx ** 2 * $y1 ** 2 - $ry ** 2 * $x1 ** 2) / ($rx ** 2 * $y1 ** 2 + $ry ** 2 * $x1 ** 2)));
        [$cx1, $cy1] = [$root * $rx * $y1 / $ry, -$root * $ry * $x1 / $rx];
        $cx = cos($angle) * $cx1 - sin($angle) * $cy1 + ($from[0] + $to[0]) / 2;
        $cy = sin($angle) * $cx1 + cos($angle) * $cy1 + ($from[1] + $to[1]) / 2;
        $theta = atan2(($y1 - $cy1) / $ry, ($x1 - $cx1) / $rx);
        $delta = atan2((-$y1 - $cy1) / $ry, (-$x1 - $cx1) / $rx) - $theta;
        $delta += $sweep && $delta < 0 ? 2 * \M_PI : (!$sweep && $delta > 0 ? -2 * \M_PI : 0);
        $points = [];

        for ($step = 1; $step < 400; ++$step) {
            $t = $theta + $delta * $step / 400;
            $points[] = [$cx + $rx * cos($angle) * cos($t) - $ry * sin($angle) * sin($t), $cy + $rx * sin($angle) * cos($t) + $ry * cos($angle) * sin($t)];
        }

        return $points;
    }

    /**
     * @param list<array{float, float}> $from
     * @param list<array{float, float}> $to
     */
    private function farthestFrom(array $from, array $to): float
    {
        $farthest = 0.0;

        foreach ($from as $point) {
            $nearest = \INF;

            for ($index = 1, $count = \count($to); $index < $count; ++$index) {
                [$a, $b] = [$to[$index - 1], $to[$index]];
                [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
                $length = $dx ** 2 + $dy ** 2;
                $along = 0.0 === $length ? 0.0 : max(0.0, min(1.0, (($point[0] - $a[0]) * $dx + ($point[1] - $a[1]) * $dy) / $length));
                $nearest = min($nearest, hypot($point[0] - $a[0] - $along * $dx, $point[1] - $a[1] - $along * $dy));
            }

            $farthest = max($farthest, $nearest);
        }

        return $farthest;
    }
}
