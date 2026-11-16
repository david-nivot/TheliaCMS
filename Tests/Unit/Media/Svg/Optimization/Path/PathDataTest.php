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
use TheliaCMS\Media\Svg\Optimization\Path\PathData;
use TheliaCMS\Media\Svg\Optimization\Path\PathSegment;

final class PathDataTest extends TestCase
{
    /**
     * @param list<array{string, list<string>}> $expected
     */
    #[DataProvider('readablePaths')]
    public function testAPathIsReadIntoItsSegments(string $data, array $expected): void
    {
        self::assertSame($expected, $this->read($data));
    }

    /**
     * @return iterable<string, array{string, list<array{string, list<string>}>}>
     */
    public static function readablePaths(): iterable
    {
        yield 'separators of any kind' => [" M 10 , 20\tL30 40 Z ", [['M', ['10', '20']], ['L', ['30', '40']], ['Z', []]]];
        yield 'compact notation' => ['M1.5.5.5.5', [['M', ['1.5', '.5']], ['L', ['.5', '.5']]]];
        yield 'signs as separators' => ['m1-2-3-4', [['m', ['1', '-2']], ['l', ['-3', '-4']]]];
        yield 'exponents' => ['M1e2-3E-1h2.5e+1', [['M', ['1e2', '-3E-1']], ['h', ['2.5e+1']]]];
        yield 'implicit commands' => ['M0 0c1 2 3 4 5 6 7 8 9 10 11 12', [['M', ['0', '0']], ['c', ['1', '2', '3', '4', '5', '6']], ['c', ['7', '8', '9', '10', '11', '12']]]];
        yield 'arc flags glued' => ['M0 0a9 9 0 0011 1', [['M', ['0', '0']], ['a', ['9', '9', '0', '0', '0', '11', '1']]]];
        yield 'arc flag written as a signed number' => ['M0 0A9 9 0 +1 0 11 1', [['M', ['0', '0']], ['A', ['9', '9', '0', '1', '0', '11', '1']]]];
        yield 'a moveto after a close' => ['M0 0h2z m1 1v2', [['M', ['0', '0']], ['h', ['2']], ['z', []], ['m', ['1', '1']], ['v', ['2']]]];
    }

    #[DataProvider('unreadablePaths')]
    public function testAnUnreadablePathIsNull(string $data): void
    {
        self::assertNull((new PathData())->segments($data));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePaths(): iterable
    {
        yield 'empty' => [''];
        yield 'not starting with a moveto' => ['L0 0'];
        yield 'number before any command' => ['0 0'];
        yield 'missing argument' => ['M0'];
        yield 'command without arguments' => ['M0 0L'];
        yield 'command cut by another' => ['M0 0L1Z'];
        yield 'number after a close' => ['M0 0z1 1'];
        yield 'unknown command' => ['M0 0X1 1'];
        yield 'arc flag other than 0 or 1' => ['M0 0a1 1 0 2 0 1 1'];
        yield 'not a number' => ['M0 0L1 #'];
    }

    #[DataProvider('writtenPaths')]
    public function testSegmentsAreWrittenInTheShortestNotation(string $data, string $expected): void
    {
        $paths = new PathData();
        $segments = $paths->segments($data);

        self::assertNotNull($segments);
        self::assertSame($expected, $paths->write($segments));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writtenPaths(): iterable
    {
        yield 'repeated letters left out' => ['M 10 20 L 30 40 L 50 60 Z', 'M10 20 30 40 50 60Z'];
        yield 'second decimal point as separator' => ['M 1.5 0.5 L .5 .5', 'M1.5 0.5.5.5'];
        yield 'sign as separator' => ['m 1 -2 l -3 -4', 'm1-2-3-4'];
        yield 'no point to glue to' => ['M 1 .5', 'M1 .5'];
        yield 'exponent before a decimal point' => ['M 1e2 .5', 'M1e2.5'];
        yield 'arc flags glued' => ['M0 0 a 9 9 0 0 0 11 1 a 9 9 0 1 1 -11 -1', 'M0 0a9 9 0 0011 1 9 9 0 11-11-1'];
        yield 'letter kept when the case changes' => ['M0 0 L1 1 l1 1 L3 3', 'M0 0 1 1l1 1L3 3'];
        yield 'letter after a close' => ['M0 0h2z M5 5h1z', 'M0 0h2zM5 5h1z'];
    }

    #[DataProvider('roundTrips')]
    public function testWhatIsWrittenReadsBackAsTheSameSegments(string $data): void
    {
        $paths = new PathData();
        $segments = $paths->segments($data);

        self::assertNotNull($segments);
        self::assertSame($this->read($data), $this->read($paths->write($segments)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roundTrips(): iterable
    {
        yield 'every command' => ['M1 2L3 4H5V6C7 8 9 10 11 12S13 14 15 16Q17 18 19 20T21 22A1 2 30 1 0 23 24Z'];
        yield 'every relative command' => ['m1 2l3 4h5v6c7 8 9 10 11 12s13 14 15 16q17 18 19 20t21 22a1 2 30 1 0 23 24z'];
        yield 'compact numbers' => ['M.5.5-.5-.5L1e-5.5.5 1.'];
        yield 'arcs ending on decimals and signs' => ['M0 0a1 1 0 01.5.5 1 1 0 10-.5-.5'];
    }

    public function testAPointListIsReadInPairs(): void
    {
        $paths = new PathData();

        self::assertSame([['1', '2'], ['3.5', '-4']], $paths->pointList(' 1,2 3.5 -4 '));
        self::assertNull($paths->pointList('1 2 3'));
        self::assertNull($paths->pointList('1 a'));
        self::assertNull($paths->pointList(''));
    }

    /**
     * @return list<array{string, list<string>}>|null
     */
    private function read(string $data): ?array
    {
        $segments = (new PathData())->segments($data);

        return null === $segments ? null : array_map(static fn (PathSegment $segment): array => [$segment->command, $segment->arguments], $segments);
    }
}
