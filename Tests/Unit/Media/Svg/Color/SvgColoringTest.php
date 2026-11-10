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

namespace TheliaCMS\Tests\Unit\Media\Svg\Color;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\Color\SvgColorPreset;
use TheliaCMS\Media\Svg\Color\SvgPaletteReader;

final class SvgColoringTest extends TestCase
{
    public function testWhatTheAddressDoesNotSayKeepsTheColors(): void
    {
        $coloring = SvgColoring::fromValues(['colors' => 'duotone', 'white' => ['keep'], 'main' => '<script>', 'others' => 'soft']);
        self::assertNull(SvgColoring::fromValues(['colors' => 'main', 'main' => "red\n"])->main, 'A line break after a color is no color.');

        self::assertSame(SvgColorPreset::Keep, $coloring->preset);
        self::assertFalse($coloring->keepWhite);
        self::assertNull($coloring->main);
        self::assertSame([], $coloring->values());
        self::assertSame('', $coloring->key());
    }

    public function testTheSettingGoesBackAndForthThroughTheAddress(): void
    {
        $coloring = new SvgColoring(SvgColorPreset::Main, keepWhite: true, main: '#22a06b', softenOthers: true);

        self::assertSame(['colors' => 'main', 'others' => 'soft', 'white' => 'keep', 'main' => '#22a06b'], $coloring->values());
        self::assertSame('main-soft-white-h22a06b', $coloring->key());
        self::assertEquals($coloring, SvgColoring::fromValues($coloring->values()));
    }

    public function testOnlyWhatThePresetReadsIsWrittenAndKeyed(): void
    {
        $coloring = new SvgColoring(SvgColorPreset::Main, keepWhite: true, main: 'crimson');

        self::assertSame(['colors' => 'main', 'main' => 'crimson'], $coloring->values(), 'The others kept as they are: the white is no question.');
        self::assertSame('main-ncrimson', $coloring->key());
        self::assertSame('monochrome-white', (new SvgColoring(SvgColorPreset::Monochrome, true, '#22a06b'))->key());
        self::assertSame('monochrome', (new SvgColoring(SvgColorPreset::Monochrome, softenOthers: true))->key(), 'Lighter others mean nothing in monochrome.');
        self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', (new SvgColoring(SvgColorPreset::Main, true, '#22a06b80', true))->key());
    }

    public function testAWhiteChosenAsTheMainColorIsNoQuestion(): void
    {
        $whiteMain = new SvgColoring(SvgColorPreset::Main, keepWhite: true, main: '#ffffff', softenOthers: true);

        self::assertFalse($whiteMain->asksAboutWhite(), 'The main color follows the text whatever the answer.');
        self::assertSame(['colors' => 'main', 'others' => 'soft', 'main' => '#ffffff'], $whiteMain->values());
        self::assertTrue((new SvgColoring(SvgColorPreset::Main, main: '#ff4422', softenOthers: true))->asksAboutWhite());
        self::assertTrue((new SvgColoring(SvgColorPreset::Monochrome, main: '#ffffff'))->asksAboutWhite(), 'In monochrome, the white may still stay white.');
    }

    public function testTheMainColorCarriedToTheNextFilesIsTheirOwn(): void
    {
        $palette = (new SvgPaletteReader())->read(SvgPaletteReaderTest::ICON);
        $carried = (new SvgColoring(SvgColorPreset::Main, main: '#22a06b', softenOthers: true))->withoutMain();

        self::assertNull($carried->main);
        self::assertTrue($carried->softens(), 'The option goes on with the preset.');
        self::assertSame('#1c1c1c', $carried->forPalette($palette)->main);
        self::assertSame('#22a06b', (new SvgColoring(SvgColorPreset::Main, main: '#22a06b'))->forPalette($palette)->main);
    }
}
