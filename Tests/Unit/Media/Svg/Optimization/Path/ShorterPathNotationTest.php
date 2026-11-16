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

namespace TheliaCMS\Tests\Unit\Media\Svg\Optimization\Path;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Optimization\Path\ExactDecimal;
use TheliaCMS\Media\Svg\Optimization\Path\PathData;
use TheliaCMS\Media\Svg\Optimization\Path\ShorterPathNotation;
use TheliaCMS\Media\Svg\Optimization\SvgDocument;

final class ShorterPathNotationTest extends TestCase
{
    #[DataProvider('shortened')]
    public function testEachCommandTakesItsShorterNotation(string $data, string $expected): void
    {
        self::assertSame($expected, (new ShorterPathNotation())->shorterData($data));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shortened(): iterable
    {
        yield 'relative lines' => ['M100 100L101 101L102 102', 'M100 100l1 1 1 1'];
        yield 'absolute kept on a tie' => ['M10 10L20 10L20 30', 'M10 10H20V30'];
        yield 'horizontal and vertical lines' => ['M100 100L110 100L110 110', 'M100 100h10v10'];
        yield 'a moveto after a close starts from the start of the subpath' => ['M100 100L110 100L110 110ZM100 100L101 101', 'M100 100h10v10zm0 0 1 1'];
        yield 'decimals subtracted exactly' => ['M10.1 0L10.3 7', 'M10.1 0l.2 7'];
        yield 'a relative path keeps its notation unless the other is shorter' => ['m10 10l-9.5 0l0 -9.5', 'm10 10H.5V.5'];
        yield 'curves' => ['M100 100C101 101 102 102 103 103S106 106 107 107', 'M100 100c1 1 2 2 3 3s3 3 4 4'];
        yield 'arc radii and flags untouched' => ['M100 100A5 5 0 0 1 110 110', 'M100 100a5 5 0 0110 10'];
        yield 'zeros and leading zeros dropped' => ['M 0.50 1.0 L 0.70 1.0', 'M.5 1H.7'];
        yield 'the whole path weighed, not each command' => ['M3 3L1 10', 'm3 3-2 7'];
    }

    #[DataProvider('kept')]
    public function testDataThatCannotComeOutShorterOrExactIsKept(string $data): void
    {
        self::assertNull((new ShorterPathNotation())->shorterData($data));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kept(): iterable
    {
        yield 'already the shortest' => ['M0 0h1v1z'];
        yield 'unreadable' => ['M0 0L1'];
        yield 'more digits than a float keeps' => ['M1.2345678901234567 0L 2 2 L 3 3'];
    }

    #[DataProvider('paths')]
    public function testThePathWrittenHoldsTheSamePointsAndIsStable(string $data): void
    {
        $notation = new ShorterPathNotation();
        $written = $notation->shorterData($data);

        self::assertNotNull($written);
        self::assertLessThan(\strlen($data), \strlen($written));
        self::assertSame($this->points($data), $this->points($written));
        self::assertNull($notation->shorterData($written), 'A second pass changes nothing.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'every command' => ['M 210.5 110.25 L 220.75 130.5 H 240 V 150.125 C 250 160 260 170 270.5 180.5 S 290 200 300 210 Q 310 220 320 230 T 340 250 A 10 20 30 1 0 360 270 Z M 400 400 L 401 401'];
        yield 'every relative command' => ['m 210.5 110.25 l 10.25 20.25 h 19.25 v 19.625 c 10 10 20 20 30.5 30.5 s 19.5 19.5 29.5 29.5 q 10 10 20 20 t 20 20 a 10 20 30 1 0 20 20 z m 50 50 l 1 1'];
        yield 'exponents' => ['M 1e3 2E2 L 1000.5 200.5 L 1001.5 201.5'];
        yield 'long run of small steps' => ['M 1000.25 2000.25'.str_repeat(' L 1000.5 2000.5 L 1000.25 2000.25', 50)];
    }

    public function testADocumentHasEveryPathShortened(): void
    {
        $document = SvgDocument::load('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><path d="M100 100L101 101L102 102"/><g><path d="M100 100L110 100"/></g><path fill="red"/></svg>');

        self::assertNotNull($document);
        self::assertTrue((new ShorterPathNotation())->shorten($document));
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><path d="M100 100l1 1 1 1"/><g><path d="M100 100h10"/></g><path fill="red"/></svg>', SvgDocument::serialize($document));
    }

    public function testAStyleSelectingByAttributeValueLeavesThePathsAlone(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><style>path[d^="M100"]{fill:red}</style><path d="M100 100L101 101L102 102"/></svg>';
        $document = SvgDocument::load($svg);

        self::assertNotNull($document);
        self::assertFalse((new ShorterPathNotation())->shorten($document));
        self::assertSame($svg, SvgDocument::serialize($document));
    }

    /**
     * Every value the path is drawn from, computed exactly, in order: the
     * points (a horizontal or vertical line ends on a point too), the radii,
     * angle and flags of an arc.
     *
     * @return list<string>
     */
    private function points(string $data): array
    {
        $segments = (new PathData())->segments($data);
        self::assertNotNull($segments);
        $roles = ['M' => 'xy', 'L' => 'xy', 'H' => 'x', 'V' => 'y', 'C' => 'xyxyxy', 'S' => 'xyxy', 'Q' => 'xyxy', 'T' => 'xy', 'A' => 'nnnnnxy', 'Z' => ''];
        $current = $start = [ExactDecimal::zero(), ExactDecimal::zero()];
        $points = [];

        foreach ($segments as $segment) {
            $command = strtoupper($segment->command);
            $point = $current;

            foreach (str_split($roles[$command]) as $index => $role) {
                $value = ExactDecimal::fromText($segment->arguments[$index]);
                self::assertNotNull($value);

                if ('n' === $role) {
                    $points[] = $value->toText();

                    continue;
                }

                $axis = 'x' === $role ? 0 : 1;
                $value = $segment->isRelative() ? $value->plus($current[$axis]) : $value;
                self::assertNotNull($value);
                $point[$axis] = $value;

                if ('y' === $role || 'H' === $command) {
                    $points[] = $point[0]->toText().','.$point[1]->toText();
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
