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

namespace TheliaCMS\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\ImageFrame;

final class ImageFrameTest extends TestCase
{
    /**
     * @param array{x: int, y: int, w: int, h: int}|null $expected
     */
    #[DataProvider('zones')]
    public function testTheZoneThatFillsTheBox(int $width, int $height, int $boxWidth, int $boxHeight, int $focusX, int $focusY, int $zoom, ?array $expected): void
    {
        self::assertSame($expected, ImageFrame::zone($width, $height, $boxWidth, $boxHeight, $focusX, $focusY, $zoom));
    }

    /**
     * @return iterable<string, array{int, int, int, int, int, int, int, array{x: int, y: int, w: int, h: int}|null}>
     */
    public static function zones(): iterable
    {
        yield 'landscape original, landscape box, zoom 100, centred' => [2400, 1600, 960, 540, 50, 50, 100, ['x' => 0, 'y' => 125, 'w' => 2400, 'h' => 1350]];
        yield 'focus on the top left edge' => [2400, 1600, 960, 540, 0, 0, 100, ['x' => 0, 'y' => 0, 'w' => 2400, 'h' => 1350]];
        yield 'focus on the bottom right edge' => [2400, 1600, 960, 540, 100, 100, 100, ['x' => 0, 'y' => 250, 'w' => 2400, 'h' => 1350]];
        yield 'zoom 200 on the top left corner' => [2400, 1600, 960, 540, 0, 0, 200, ['x' => 0, 'y' => 0, 'w' => 1200, 'h' => 675]];
        yield 'zoom 200 on the bottom right corner' => [2400, 1600, 960, 540, 100, 100, 200, ['x' => 1200, 'y' => 925, 'w' => 1200, 'h' => 675]];
        yield 'zoom 150, the zone is moved back inside the picture' => [2400, 1600, 960, 540, 35, 20, 150, ['x' => 40, 'y' => 0, 'w' => 1600, 'h' => 900]];
        yield 'portrait box on a landscape original' => [2400, 1600, 600, 800, 50, 50, 100, ['x' => 600, 'y' => 0, 'w' => 1200, 'h' => 1600]];
        yield 'landscape box on a portrait original' => [1000, 1500, 800, 400, 50, 50, 100, ['x' => 0, 'y' => 500, 'w' => 1000, 'h' => 500]];
        yield 'the original is smaller than the box' => [400, 300, 960, 540, 50, 50, 100, ['x' => 0, 'y' => 38, 'w' => 400, 'h' => 225]];
        yield 'same proportions, no zoom: the whole picture' => [1600, 900, 960, 540, 50, 50, 100, null];
        yield 'same proportions, no zoom, any focus: the whole picture' => [1600, 900, 960, 540, 0, 100, 100, null];
        yield 'same proportions, zoom 400' => [1600, 900, 960, 540, 50, 50, 400, ['x' => 600, 'y' => 338, 'w' => 400, 'h' => 225]];
        yield 'a square original and box' => [1000, 1000, 500, 500, 25, 75, 200, ['x' => 0, 'y' => 500, 'w' => 500, 'h' => 500]];
        yield 'an original with no size' => [0, 0, 960, 540, 50, 50, 100, null];
    }

    /**
     * Whatever the numbers, the zone is inside the picture and has the
     * proportions of the box to a pixel: the library refuses the first and the
     * browser draws the second as a different picture.
     */
    public function testNoZoneIsOutsideThePictureOrOffTheBoxProportions(): void
    {
        $checked = 0;

        foreach ([[2400, 1600], [1600, 900], [900, 1600], [400, 300], [4000, 1000], [37, 59], [5, 3000]] as [$width, $height]) {
            foreach ([[960, 540], [600, 800], [160, 640], [2400, 600], [500, 500], [801, 79 + 1]] as [$boxWidth, $boxHeight]) {
                foreach ([0, 1, 35, 50, 99, 100] as $focusX) {
                    foreach ([0, 20, 50, 100] as $focusY) {
                        foreach ([100, 110, 150, 200, 300, 400] as $zoom) {
                            $zone = ImageFrame::zone($width, $height, $boxWidth, $boxHeight, $focusX, $focusY, $zoom);
                            $message = \sprintf('%dx%d, box %dx%d, focus %d/%d, zoom %d', $width, $height, $boxWidth, $boxHeight, $focusX, $focusY, $zoom);
                            ++$checked;

                            if (null === $zone) {
                                continue;
                            }

                            self::assertGreaterThanOrEqual(0, $zone['x'], $message);
                            self::assertGreaterThanOrEqual(0, $zone['y'], $message);
                            self::assertGreaterThanOrEqual(1, $zone['w'], $message);
                            self::assertGreaterThanOrEqual(1, $zone['h'], $message);
                            self::assertLessThanOrEqual($width, $zone['x'] + $zone['w'], $message);
                            self::assertLessThanOrEqual($height, $zone['y'] + $zone['h'], $message);

                            // Within a pixel of the box proportions, unless the picture is too small to hold one.
                            if ($zone['h'] < $height && $zone['h'] > 1) {
                                self::assertEqualsWithDelta($zone['w'] * $boxHeight / $boxWidth, $zone['h'], 1.0, $message);
                            }
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(1000, $checked);
    }

    public function testTheAttributeIsReadBackAsWritten(): void
    {
        self::assertSame(' data-cms-crop="35,20,150,960,540"', ImageFrame::attribute(35, 20, 150, 960, 540));
        self::assertSame(['focusX' => 35, 'focusY' => 20, 'zoom' => 150, 'boxWidth' => 960, 'boxHeight' => 540], ImageFrame::parse('35,20,150,960,540'));
    }

    public function testABoxOfZeroIsTheProportionsOfThePictureItself(): void
    {
        self::assertSame(['focusX' => 20, 'focusY' => 80, 'zoom' => 150, 'boxWidth' => 0, 'boxHeight' => 0], ImageFrame::parse('20,80,150,0,0'));
        self::assertNull(ImageFrame::parse('20,80,150,0,540'), 'One side alone is no box.');
        self::assertNull(ImageFrame::parse('20,80,150,960,0'));
    }

    public function testThePictureZoomedKeepsItsOwnProportions(): void
    {
        // 1600 by 1000 at 150: a zone 1066 by 666 (the proportions of the picture), centred on 30 % / 70 %.
        $zone = ImageFrame::zone(1600, 1000, 0, 0, 30, 70, 150);

        self::assertSame(ImageFrame::zone(1600, 1000, 1600, 1000, 30, 70, 150), $zone);
        self::assertSame(['x' => 0, 'y' => 334, 'w' => 1066, 'h' => 666], $zone);
        self::assertNull(ImageFrame::zone(1600, 1000, 0, 0, 30, 70, 100), 'Zoom 100 is the whole picture.');
    }

    #[DataProvider('attributesThatAreNotFromTheSection')]
    public function testAnAttributeTheSectionCouldNotHaveWrittenIsIgnored(string $value): void
    {
        self::assertNull(ImageFrame::parse($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attributesThatAreNotFromTheSection(): iterable
    {
        yield 'four numbers' => ['35,20,150,960'];
        yield 'six numbers' => ['35,20,150,960,540,1'];
        yield 'text' => ['a,b,c,d,e'];
        yield 'a focus over 100' => ['101,20,150,960,540'];
        yield 'a zoom under 100' => ['35,20,90,960,540'];
        yield 'a zoom over 400' => ['35,20,410,960,540'];
        yield 'a zoom off the steps' => ['35,20,105,960,540'];
        yield 'a width under 160' => ['35,20,150,159,540'];
        yield 'a height over 2000' => ['35,20,150,960,2001'];
        yield 'a box more stretched than 4:1' => ['35,20,150,2400,500'];
        yield 'a negative number' => ['-1,20,150,960,540'];
        yield 'empty' => [''];
    }

    /**
     * @return iterable<string, array{string, array{x: int, y: int, w: int, h: int}|null}>
     */
    public static function addresses(): iterable
    {
        yield 'a zone' => ['/image-library/12/160,0,800,450/max/0/default.jpg', ['x' => 160, 'y' => 0, 'w' => 800, 'h' => 450]];
        yield 'a zone and a size' => ['/image-library/12/1,2,3,4/480,270/0/default.webp', ['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4]];
        yield 'the whole picture' => ['/image-library/12/full/max/0/default.jpg', null];
        yield 'a square' => ['/image-library/12/square/max/0/default.jpg', null];
        yield 'a percentage' => ['/image-library/12/pct:10,10,50,50/max/0/default.jpg', null];
        yield 'three numbers' => ['/image-library/12/1,2,3/max/0/default.jpg', null];
        yield 'five numbers' => ['/image-library/12/1,2,3,4,5/max/0/default.jpg', null];
        yield 'a decimal' => ['/image-library/12/1.5,2,3,4/max/0/default.jpg', null];
        yield 'a negative number' => ['/image-library/12/-1,2,3,4/max/0/default.jpg', null];
        yield 'too long a number' => ['/image-library/12/1,2,3,444444/max/0/default.jpg', null];
        yield 'another route' => ['/elsewhere/12/1,2,3,4/max/0/default.jpg', null];
        yield 'no region' => ['/image-library/12', null];
    }

    /**
     * @param array{x: int, y: int, w: int, h: int}|null $expected
     */
    #[DataProvider('addresses')]
    public function testTheRegionOfAnAddressOfTheLibraryIsFourIntegersOrNothing(string $address, ?array $expected): void
    {
        self::assertSame($expected, ImageFrame::region($address));
    }
}
