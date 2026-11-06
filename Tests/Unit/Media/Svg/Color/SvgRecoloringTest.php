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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\Color\SvgColorPreset;
use TheliaCMS\Media\Svg\Color\SvgPaints;
use TheliaCMS\Media\Svg\Color\SvgPaletteReader;
use TheliaCMS\Media\Svg\Color\SvgRecoloring;
use TheliaLibrary\Service\Svg\SvgChangeDetector;
use TheliaLibrary\Service\Svg\SvgSanitizer;

final class SvgRecoloringTest extends TestCase
{
    public function testKeepingTheColorsLeavesTheFileAsItIs(): void
    {
        self::assertSame(SvgPaletteReaderTest::ICON, $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring()));
    }

    public function testMonochromeMakesEveryColorFollowTheTextTheWhiteIncluded(): void
    {
        $result = $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring(SvgColorPreset::Monochrome));

        self::assertSame(['currentColor'], $this->paints($result));
        self::assertStringNotContainsString('opacity', $result);
    }

    public function testMonochromeCanKeepTheWhiteWhite(): void
    {
        $result = $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring(SvgColorPreset::Monochrome, keepWhite: true));

        self::assertSame(['#fff', 'currentColor'], $this->paints($result));
    }

    public function testTheMainColorFollowsTheTextAndTheOthersStay(): void
    {
        $result = $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring(SvgColorPreset::Main));

        self::assertSame(['#22A06B', '#fff', 'currentColor'], $this->paints($result));
        self::assertSame(4, substr_count($result, 'fill="currentColor"'), 'The four dark shapes, whatever their spelling.');
    }

    public function testTheMainColorIsTheOneTheEditorChoseWhenTheDrawingPaintsWithIt(): void
    {
        $chosen = $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring(SvgColorPreset::Main, main: '#22a06b'));
        $whiteKept = $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring(SvgColorPreset::Main, keepWhite: true, softenOthers: true));
        $unknown = $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring(SvgColorPreset::Main, main: '#123456'));

        self::assertSame(1, substr_count($chosen, 'fill="currentColor"'));
        self::assertStringContainsString('<circle cx="17" cy="17" r="5" fill="currentColor"/>', $chosen);
        self::assertSame(4, substr_count($unknown, 'fill="currentColor"'), 'A color the drawing does not paint with falls back on its most used one.');
        self::assertStringContainsString('stroke="#fff"', $whiteKept, 'The others lighter, a white can still stay white.');
    }

    public function testTheOthersCanFollowTheTextLighterTheMainOneInFull(): void
    {
        $result = $this->recolored(SvgPaletteReaderTest::ICON, new SvgColoring(SvgColorPreset::Main, softenOthers: true));

        self::assertSame(['currentColor'], $this->paints($result));
        self::assertStringContainsString('<circle cx="17" cy="17" r="5" fill="currentColor" fill-opacity="0.4"/>', $result);
        self::assertStringContainsString('stroke="currentColor" stroke-width="1.5" stroke-opacity="0.4"', $result);
        self::assertSame(2, substr_count($result, 'opacity'), 'The four main shapes keep a full opacity.');
    }

    public function testAnOpacityAlreadyWrittenIsLoweredRatherThanReplaced(): void
    {
        $result = $this->recolored(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M0 0h2v2H0z" fill="#000"/><path d="M0 0h1v1H0z" fill="#000"/><path d="M2 2h1v1H2z" fill="#f00" fill-opacity="50%"/><path d="M3 3h1v1H3z" style="fill:#f00;fill-opacity:.5"/></svg>',
            new SvgColoring(SvgColorPreset::Main, softenOthers: true),
        );

        self::assertStringContainsString('fill="currentColor" fill-opacity="0.2"', $result);
        self::assertStringContainsString('style="fill:currentColor;fill-opacity:0.2"', $result);
    }

    public function testAShapeThatSetsNoFillGetsOneWhenItsBlackFollowsTheText(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M0 0h1v1H0z"/><path d="M1 1h1v1H1z"/><path d="M2 2h1v1H2z" fill="#0f0"/></svg>';

        $monochrome = $this->recolored($svg, new SvgColoring(SvgColorPreset::Monochrome));
        $softened = $this->recolored($svg, new SvgColoring(SvgColorPreset::Main, main: '#00ff00', softenOthers: true));

        self::assertSame(3, substr_count($monochrome, 'fill="currentColor"'));
        self::assertSame(2, substr_count($softened, 'fill="currentColor" fill-opacity="0.4"'));
        self::assertStringNotContainsString('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4" fill', $monochrome, 'The fill is set on the shapes, not on the root a mask would inherit from.');
    }

    public function testAShapeAUseFillsKeepsWhatItHasAndAShapeNothingFillsGetsOne(): void
    {
        $result = $this->recolored(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><defs><path id="a" d="M0 0h1v1H0z"/><path id="b" d="M1 1h1v1H1z"/></defs>'
            .'<use href="#a" fill="#f00"/><use href="#b"/></svg>',
            new SvgColoring(SvgColorPreset::Main, main: '#000000'),
        );

        self::assertStringContainsString('<path id="a" d="M0 0h1v1H0z"/>', $result, 'Its use fills it red: the shape keeps no fill of its own.');
        self::assertStringContainsString('<path id="b" d="M1 1h1v1H1z" fill="currentColor"/>', $result);
        self::assertStringContainsString('fill="#f00"', $result);
    }

    public function testTheRulesOfTheFileAndTheGradientStopsFollowTooAndAMaskNever(): void
    {
        $result = $this->recolored(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><style>.st0{fill:#85CCD3}.st1{stroke:#000 !important}</style>'
            .'<linearGradient id="g"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient>'
            .'<mask id="m"><rect width="4" height="4" fill="white"/></mask>'
            .'<g mask="url(#m)"><path class="st0 st1" d="M0 0h1v1H0z"/><rect width="2" height="2" fill="url(#g)"/></g></svg>',
            new SvgColoring(SvgColorPreset::Monochrome),
        );

        self::assertStringContainsString('.st0{fill:currentColor}.st1{stroke:currentColor !important}', $result);
        self::assertSame(2, substr_count($result, 'stop-color="currentColor"'));
        self::assertStringContainsString('<rect width="4" height="4" fill="white"/>', $result, 'The white of a mask is a luminance: it stays.');
        self::assertStringContainsString('fill="url(#g)"', $result);
    }

    public function testATransparentColorKeepsItsTransparencyOnceItFollowsTheText(): void
    {
        $result = $this->recolored(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M0 0h4v4H0z" fill="rgba(0,0,0,.5)"/><path d="M0 0h1v1H0z" style="stroke:#ff000080"/></svg>',
            new SvgColoring(SvgColorPreset::Monochrome),
        );

        self::assertStringContainsString('fill="currentColor" fill-opacity="0.502"', $result);
        self::assertStringContainsString('style="stroke:currentColor;stroke-opacity:0.502"', $result);
    }

    public function testARuleAMaskReadsStaysAndTheVisibleShapesItPaintsFollowByTheirOwnStyle(): void
    {
        $result = $this->recolored(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><style>.w{fill:#fff}.r{fill:#f00}</style>'
            .'<linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient>'
            .'<mask id="m"><rect class="w" width="4" height="4"/><circle r="1" fill="url(#g)"/></mask>'
            .'<rect class="w" width="2" height="2"/><rect class="r" width="4" height="4" mask="url(#m)"/></svg>',
            new SvgColoring(SvgColorPreset::Monochrome),
        );

        self::assertStringContainsString('<style>.w{fill:#fff}.r{fill:currentColor}</style>', $result, 'The rule the mask reads stays.');
        self::assertStringContainsString('<rect class="w" width="2" height="2" style="fill:currentColor"/>', $result, 'The visible shape it paints follows by its own style.');
        self::assertStringContainsString('<stop offset="0" stop-color="#fff"/>', $result, 'A gradient the mask draws with stays.');
    }

    public function testTheRewritingLeavesStringsAndCommentsAsTheyAre(): void
    {
        $result = $this->recolored(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><style>.a{fill:red;background:url("data:a;b")}.b{stroke:blue}.c{fill:lime;/*;stroke:navy;*/}</style>'
            .'<path class="a b c" d="M0 0h4v4H0z"/></svg>',
            new SvgColoring(SvgColorPreset::Monochrome),
        );

        self::assertStringContainsString('.a{fill:currentColor;background:url("data:a;b")}.b{stroke:currentColor}.c{fill:currentColor;/*;stroke:navy;*/}', $result);
    }

    public function testAnOpacityWrittenAsAnAttributeIsLoweredWhenTheStyleSetsTheColor(): void
    {
        $result = $this->recolored(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M0 0h2v2H0z" fill="#000"/><path d="M0 0h2v2H0z" fill="#000"/><path d="M2 2h1v1H2z" fill-opacity=".5" style="fill:#f00"/></svg>',
            new SvgColoring(SvgColorPreset::Main, softenOthers: true),
        );

        self::assertStringContainsString('style="fill:currentColor;fill-opacity:0.2"', $result);
    }

    public function testADrawingTooComplexToReadIsLeftAsItIs(): void
    {
        $group = str_repeat('<path d="M0 0h1v1H0z" fill="#f00"/>', 1000);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><defs><g id="g">'.$group.'</g></defs>'.str_repeat('<use href="#g"/>', 100).'</svg>';

        $palette = (new SvgPaletteReader())->read($svg);

        self::assertFalse($palette->readable, 'A hundred thousand shapes drawn: too many to read.');
        self::assertSame($svg, (new SvgRecoloring())->apply($svg, new SvgColoring(SvgColorPreset::Monochrome), $palette));
    }

    public function testADrawingWithTooManyRulesIsNotRead(): void
    {
        $rules = '';

        for ($index = 0; $index <= SvgPaints::MAXIMUM_RULES; ++$index) {
            $rules .= '.c'.$index.'{fill:red}';
        }

        self::assertFalse((new SvgPaletteReader())->read('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><style>'.$rules.'</style><path class="c1" d="M0 0h1v1H0z"/></svg>')->readable);
    }

    /**
     * @return iterable<string, array{SvgColoring}>
     */
    public static function presets(): iterable
    {
        foreach (SvgColorPreset::cases() as $preset) {
            yield $preset->value => [new SvgColoring($preset)];
            yield $preset->value.', white kept' => [new SvgColoring($preset, keepWhite: true)];
        }

        yield 'main, the others lighter' => [new SvgColoring(SvgColorPreset::Main, softenOthers: true)];
        yield 'main, the others lighter, white kept' => [new SvgColoring(SvgColorPreset::Main, keepWhite: true, softenOthers: true)];
    }

    #[DataProvider('presets')]
    public function testWhatAPresetWritesGoesThroughTheCleaningUnchanged(SvgColoring $coloring): void
    {
        $sanitizer = new SvgSanitizer(new SvgChangeDetector());
        $result = $this->recolored($sanitizer->sanitize(SvgPaletteReaderTest::ICON)->content, $coloring);

        $cleaned = $sanitizer->sanitize($result);

        self::assertSame([], $cleaned->changes);
        self::assertSame(self::canonical($result), self::canonical($cleaned->content));
    }

    private function recolored(string $svg, SvgColoring $coloring): string
    {
        return (new SvgRecoloring())->apply($svg, $coloring, (new SvgPaletteReader())->read($svg));
    }

    /**
     * The paints left in a file, sorted, `none` left out.
     *
     * @return list<string>
     */
    private function paints(string $svg): array
    {
        preg_match_all('/(?:fill|stroke)="([^"]+)"/', $svg, $matches);
        $paints = array_values(array_unique(array_filter($matches[1], static fn (string $paint): bool => 'none' !== $paint)));
        sort($paints);

        return $paints;
    }

    private static function canonical(string $svg): string
    {
        $document = new \DOMDocument();
        $document->loadXML($svg);

        return (string) $document->C14N();
    }
}
