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

namespace TheliaCMS\Tests\Unit\Media\Svg\Optimization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Optimization\FigmaClipRemover;
use TheliaCMS\Media\Svg\Optimization\GeometryEnvelope;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizer;
use TheliaCMS\Media\Svg\Optimization\SvgReferences;
use TheliaCMS\Media\Svg\Optimization\UnreferencedIdRemover;

final class SvgOptimizerTest extends TestCase
{
    private const string FIGMA = <<<'SVG'
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <title>Document</title>
        <g clip-path="url(#clip0_4102_1187)">
        <path fill-rule="evenodd" clip-rule="evenodd" d="M6 2h8l4 4v14H6z" fill="#1C1C1C"/>
        <circle cx="17" cy="17" r="5.15" fill="#22A06B"/>
        </g>
        <defs>
        <clipPath id="clip0_4102_1187">
        <rect width="24" height="24" fill="white"/>
        </clipPath>
        </defs>
        </svg>
        SVG;

    public function testAFigmaExportLosesItsTitleGroupDefsAndClip(): void
    {
        $output = $this->optimize(self::FIGMA);

        foreach (['<title', '<g', '<defs', 'clipPath', 'clip-path', 'clip0_4102_1187'] as $gone) {
            self::assertStringNotContainsString($gone, $output);
        }

        self::assertStringContainsString('<path', $output);
        self::assertStringContainsString('<circle', $output);
        self::assertStringContainsStringIgnoringCase('#1C1C1C', $output);
        self::assertStringContainsStringIgnoringCase('#22A06B', $output);
    }

    public function testWidthHeightViewBoxAndNamespaceStay(): void
    {
        $output = $this->optimize(self::FIGMA);

        self::assertStringContainsString('width="24"', $output);
        self::assertStringContainsString('height="24"', $output);
        self::assertStringContainsString('viewBox="0 0 24 24"', $output);
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $output);
    }

    public function testTheOutputHasNoXmlDeclaration(): void
    {
        $output = $this->optimize('<?xml version="1.0" encoding="UTF-8"?>'.self::FIGMA);

        self::assertStringStartsWith('<svg', $output);
    }

    public function testTheClipStaysWhenItCutsTheDrawing(): void
    {
        $svg = str_replace('<rect width="24" height="24" fill="white"/>', '<rect x="2" y="2" width="10" height="10"/>', self::FIGMA);

        $output = $this->optimize($svg);

        self::assertStringContainsString('clipPath', $output);
        self::assertStringContainsString('clip-path="url(#clip0_4102_1187)"', $output);
    }

    public function testTitleAndDescriptionAreRemoved(): void
    {
        $output = $this->optimize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><title>A</title><desc>B</desc><path d="M0 0h8v8z"/></svg>');

        self::assertStringNotContainsString('<title', $output);
        self::assertStringNotContainsString('<desc', $output);
        self::assertStringContainsString('<path', $output);
    }

    public function testAReferencedIdStaysAndAnUnreferencedOneGoes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 8 8">'
            .'<defs><linearGradient id="paint0"><stop offset="0" stop-color="#000"/><stop offset="1" stop-color="#fff"/></linearGradient></defs>'
            .'<path id="shape" d="M0 0h8v8z" fill="url(#paint0)"/><path id="loose" d="M1 1h2v2z"/>'
            .'<use xlink:href="#shape"/></svg>';

        $output = $this->optimize($svg);

        self::assertStringContainsString('id="paint0"', $output);
        self::assertStringContainsString('id="shape"', $output);
        self::assertStringNotContainsString('id="loose"', $output);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function idsCitedFromOutside(): iterable
    {
        yield 'the root' => ['<svg xmlns="http://www.w3.org/2000/svg" id="logo" viewBox="0 0 8 8"><path d="M0 0h8v8z"/></svg>', 'id="logo"'];
        yield 'a symbol of a sprite' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><symbol id="icon-cart" viewBox="0 0 8 8"><path d="M0 0h8v8z"/></symbol></svg>', 'id="icon-cart"'];
        yield 'a view' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 8"><view id="left" viewBox="0 0 8 8"/><path d="M0 0h16v8z"/></svg>', 'id="left"'];
    }

    /**
     * A sprite is used by `file.svg#icon-cart`, a view by `file.svg#left`:
     * the reference is outside the file, nothing inside cites the id.
     */
    #[DataProvider('idsCitedFromOutside')]
    public function testAnIdOtherFilesPointAtStays(string $svg, string $kept): void
    {
        self::assertStringContainsString($kept, $this->optimize($svg));
    }

    public function testAnIdThatIsOnlyPartOfAWordOfTheStyleSheetGoes(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><style>.accent{fill:red}</style><path class="accent" id="a" d="M0 0h8v8z"/></svg>';

        self::assertStringNotContainsString('id="a"', $this->optimize($svg));
    }

    public function testAnIdCitedByAStyleSheetStays(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><style>#accent{fill:red}</style><path id="accent" d="M0 0h8v8z"/></svg>';

        self::assertStringContainsString('id="accent"', $this->optimize($svg));
    }

    public function testATransformIsNeverRewritten(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><path transform="translate(-17.781 -109.607)" d="M0 0h8v8z"/></svg>';

        self::assertStringContainsString('transform="translate(-17.781 -109.607)"', $this->optimize($svg));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'figma' => [self::FIGMA];
        yield 'cut by the clip' => [str_replace('<rect width="24" height="24" fill="white"/>', '<rect x="2" y="2" width="10" height="10"/>', self::FIGMA)];
        yield 'ids and titles' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><title>A</title><g id="a"><path id="b" d="M0 0h8v8z" transform="translate(1 2)"/></g></svg>'];
        yield 'id orphaned by the library' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><defs><linearGradient id="g"/></defs><path d="M0 0h8v8z" display="none" fill="url(#g)"/><path id="k" d="M0 0h8v8z"/></svg>'];
        yield 'clip around the drawing' => ['<svg width="48" height="24" viewBox="0 0 48 24" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#c)"><path d="M2 2h8v8z"/></g><clipPath id="c"><rect width="20" height="20"/></clipPath></svg>'];
    }

    #[DataProvider('documents')]
    public function testOptimizingTwiceChangesNothing(string $svg): void
    {
        $once = $this->optimize($svg);

        self::assertSame($once, $this->optimize($once));
    }

    public function testWhatTheLibraryCannotReadComesBackAsItWas(): void
    {
        self::assertSame('not an svg', $this->optimize('not an svg'));
    }

    /**
     * @return iterable<string, array{string, string, bool}> drawing, clip rect, whether the clip goes
     */
    public static function envelopes(): iterable
    {
        $rect = '<rect width="20" height="20"/>';

        yield 'inside' => ['<path d="M2 2h8v8z"/>', $rect, true];
        yield 'relative path outside' => ['<path d="M2 2h30v8z"/>', $rect, false];
        yield 'bezier control point outside' => ['<path d="M2 2C4 40 6 4 8 2"/>', $rect, false];
        yield 'smooth bezier reflected point outside' => ['<path d="M2 2C2 2 10 10 18 10S18 10 18 10"/>', $rect, false];
        yield 'quadratic control point outside' => ['<path d="M2 2Q10 60 18 2"/>', $rect, false];
        yield 'arc inside' => ['<path d="M10 10a1 1 0 0 1 1 1"/>', $rect, true];
        yield 'arc radius reaches outside' => ['<path d="M10 10A9 9 0 0 1 11 11"/>', $rect, false];
        yield 'arc radius enlarged to reach its end' => ['<path d="M10 3A1 1 0 0 1 10 17"/>', $rect, false];
        yield 'arc flags glued' => ['<path d="M10 10a9 9 0 0011 1"/>', $rect, false];
        yield 'circle inside' => ['<circle cx="10" cy="10" r="5"/>', $rect, true];
        yield 'circle outside' => ['<circle cx="10" cy="10" r="12"/>', $rect, false];
        yield 'stroke reaches outside' => ['<path d="M2 2H18" stroke="#000" stroke-width="4"/>', $rect, false];
        yield 'stroke inside' => ['<path d="M8 8H12" stroke="#000" stroke-width="2"/>', $rect, true];
        yield 'transform under the clip' => ['<path transform="translate(40 0)" d="M2 2h8v8z"/>', $rect, false];
        yield 'text under the clip' => ['<text x="2" y="2">A</text>', $rect, false];
        yield 'unreadable path' => ['<path d="M2 2 Z Z q"/>', $rect, false];
        yield 'unit in a length' => ['<rect width="5mm" height="5"/>', $rect, false];
        yield 'rounded clip' => ['<path d="M2 2h8v8z"/>', '<rect width="20" height="20" rx="4"/>', false];
        yield 'transformed clip rect' => ['<path d="M2 2h8v8z"/>', '<rect width="20" height="20" transform="scale(0.1)"/>', false];
        yield 'path data past the length read' => ['<path d="M2 2'.str_repeat('l0 0', 30_000).'z"/>', $rect, false];
    }

    #[DataProvider('envelopes')]
    public function testTheClipGoesOnlyWhenItContainsWhatItClips(string $drawing, string $rect, bool $goes): void
    {
        $svg = '<svg width="48" height="48" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#c)">'.$drawing
            .'</g><clipPath id="c">'.$rect.'</clipPath></svg>';

        self::assertSame($goes, !str_contains($this->optimize($svg), 'clipPath'));
    }

    public function testTheClipStaysWhenTheClippedUnitsAreTheBoundingBox(): void
    {
        $svg = '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#c)"><path d="M2 2h8v8z"/></g>'
            .'<clipPath id="c" clipPathUnits="objectBoundingBox"><rect width="30" height="30"/></clipPath></svg>';

        self::assertStringContainsString('clipPath', $this->optimize($svg));
    }

    public function testTheClipStaysOverATextEvenWhenItCoversTheViewBox(): void
    {
        $svg = '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#c)"><text x="2" y="2">A</text></g>'
            .'<clipPath id="c"><rect width="24" height="24"/></clipPath></svg>';

        self::assertStringContainsString('clipPath', $this->optimize($svg));
    }

    /**
     * Drawn in other proportions than its viewBox, an image shows what lies
     * beyond it: the clip that covers the viewBox is what hides it.
     */
    public function testAClipCoveringTheViewBoxStaysOverADrawingThatGoesBeyondIt(): void
    {
        $svg = '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#c)"><path d="M2 2h80v8z"/></g>'
            .'<clipPath id="c"><rect width="24" height="24"/></clipPath></svg>';

        self::assertStringContainsString('clipPath', $this->optimize($svg));
    }

    public function testTheClipStaysUnderATransform(): void
    {
        $svg = '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g transform="translate(30 0)"><g clip-path="url(#c)"><path d="M2 2h80v8z"/></g></g>'
            .'<clipPath id="c"><rect width="24" height="24"/></clipPath></svg>';

        self::assertStringContainsString('clipPath', $this->optimize($svg));
    }

    public function testASharedClipGoesOnlyWhenAllItsUsersAreFree(): void
    {
        $shared = '<svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#c)"><path d="M2 2h8v8z"/></g>'
            .'<g clip-path="url(#c)"><path d="M4 4h8v8z"/></g><clipPath id="c"><rect width="10" height="12"/></clipPath></svg>';

        $output = $this->optimize($shared);

        // The first user is inside the rect, the second one reaches x = 12.
        self::assertStringContainsString('clipPath', $output);
        self::assertSame(1, substr_count($output, 'clip-path="url(#c)"'));
    }

    private function optimize(string $svg): string
    {
        $references = new SvgReferences();

        return (new SvgOptimizer(new FigmaClipRemover(new GeometryEnvelope(), $references), new UnreferencedIdRemover($references)))->optimize($svg);
    }
}
