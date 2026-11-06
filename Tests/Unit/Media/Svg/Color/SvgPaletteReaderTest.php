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
use TheliaCMS\Media\Svg\Color\PaletteColor;
use TheliaCMS\Media\Svg\Color\SvgPalette;
use TheliaCMS\Media\Svg\Color\SvgPaletteReader;

final class SvgPaletteReaderTest extends TestCase
{
    /** Four dark shapes, a green circle and a white check: the icon of the plan. */
    public const string ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">'
        .'<path d="M2 2h10v10H2z" fill="#1C1C1C"/><path d="M4 14h6v6H4z" fill="#1c1c1c"/>'
        .'<path d="M14 4h6v4h-6z" fill="#1C1C1C"/><path d="M2 21h8v1H2z" fill="rgb(28,28,28)"/>'
        .'<circle cx="17" cy="17" r="5" fill="#22A06B"/>'
        .'<path d="M15 17l1.5 1.5L19 16" fill="none" stroke="#fff" stroke-width="1.5"/></svg>';

    public function testEachColorIsCountedOncePerElementItPaintsTheMostUsedFirst(): void
    {
        $palette = (new SvgPaletteReader())->read(self::ICON);

        self::assertSame([['#1c1c1c', 4, 0], ['#22a06b', 1, 0], ['#ffffff', 0, 1]], self::summary($palette));
        self::assertSame('#1c1c1c', $palette->main());
        self::assertTrue($palette->hasWhite());
    }

    public function testAShapeThatSetsNoFillIsPaintedBlackAndALineIsNot(): void
    {
        $palette = (new SvgPaletteReader())->read('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><path d="M0 0h1v1H0z"/><g fill="red"><rect width="1" height="1"/></g><line x2="4" stroke="blue"/></svg>');

        self::assertSame([['#000000', 1, 0], ['#ff0000', 1, 0], ['#0000ff', 0, 1]], self::summary($palette));
    }

    public function testTheStyleAttributeAndTheRulesOfTheFileArePaintsToo(): void
    {
        $palette = (new SvgPaletteReader())->read('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4"><style>.st0{fill:#85CCD3}.st1{fill:#1B939E;stroke:#000}</style>'
            .'<path class="st0" d="M0 0h1v1H0z"/><path class="st0" d="M1 0h1v1H1z"/><path class="st1" d="M2 0h1v1H2z"/>'
            .'<path class="st0" style="fill:#fff" d="M3 0h1v1H3z"/></svg>');

        self::assertSame([['#85ccd3', 2, 0], ['#1b939e', 1, 0], ['#000000', 0, 1], ['#ffffff', 1, 0]], self::summary($palette), 'The style attribute wins over a rule.');
    }

    public function testAMaskAClipAndAShapeNoUseDrawsAreLeftOut(): void
    {
        $palette = (new SvgPaletteReader())->read('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4">'
            .'<mask id="m"><rect width="4" height="4" fill="white"/></mask><clipPath id="c"><rect width="4" height="4" fill="#ff0"/></clipPath>'
            .'<defs><path id="kept" d="M0 0h1v1H0z" fill="#f00"/><path id="drawn" d="M0 0h1v1H0z" fill="#00f"/></defs>'
            .'<use href="#drawn"/><g mask="url(#m)" clip-path="url(#c)"><circle r="1" fill="#0f0"/></g></svg>');

        self::assertSame([['#00ff00', 1, 0], ['#0000ff', 1, 0]], self::summary($palette), 'Between equals, the shapes drawn where they stand come before the copies of a use.');
    }

    public function testAShapeDrawnByAUseTakesFromItWhatItDoesNotSet(): void
    {
        // The flag of Nepal: its outline is the stroke of a use that draws the red shape again.
        $palette = (new SvgPaletteReader())->read('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 4 4">'
            .'<use stroke="#003893" stroke-width="5" xlink:href="#a"/><path id="a" fill="#DC143C" d="M0 0h4v4H0z"/>'
            .'<defs><g id="b"><circle r="1" stroke="#0f0"/></g></defs><use href="#b" fill="#ff0" stroke="#f00"/></svg>');

        self::assertSame([['#dc143c', 2, 0], ['#003893', 0, 1], ['#ffff00', 1, 0], ['#00ff00', 0, 1]], self::summary($palette), 'What the shape sets wins over the use.');
    }

    public function testTheCandidatesForTheMainColorAreTheColorsThatPaintAnElement(): void
    {
        $palette = (new SvgPaletteReader())->read('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4">'
            .'<linearGradient id="g"><stop offset="0" stop-color="#ff0"/></linearGradient>'
            .'<path d="M0 0h1v1H0z" fill="url(#g)" stroke="#00f"/><path d="M1 1h1v1H1z" fill="#0f0"/><path d="M2 2h1v1H2z" fill="#0f0"/></svg>');

        self::assertSame(['#00ff00', '#0000ff'], array_map(static fn (PaletteColor $color): string => $color->key, $palette->candidates()), 'A gradient stop is no candidate.');
    }

    public function testGradientsAndWhatAlreadyFollowsTheTextAreToldApart(): void
    {
        $palette = (new SvgPaletteReader())->read('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 4">'
            .'<linearGradient id="g"><stop offset="0" stop-color="#fff"/><stop offset="1" style="stop-color:#000"/></linearGradient>'
            .'<rect width="4" height="4" fill="url(#g)"/><path d="M0 0h1v1H0z" fill="currentColor"/></svg>');

        self::assertSame(1, $palette->gradients);
        self::assertSame(1, $palette->followsText);
        self::assertSame([['#ffffff', 0, 0], ['#000000', 0, 0]], self::summary($palette));
        self::assertSame([1, 1], array_map(static fn (PaletteColor $color): int => $color->stops, $palette->colors));
        self::assertNull($palette->main(), 'A stop paints no element alone: there is no main color.');
    }

    public function testAFileThatIsNoPlainDocumentHasNoColor(): void
    {
        self::assertTrue((new SvgPaletteReader())->read('<!DOCTYPE svg><svg/>')->isEmpty());
    }

    /**
     * @return list<array{string, int, int}>
     */
    private static function summary(SvgPalette $palette): array
    {
        return array_map(static fn (PaletteColor $color): array => [$color->key, $color->fills, $color->strokes], $palette->colors);
    }
}
