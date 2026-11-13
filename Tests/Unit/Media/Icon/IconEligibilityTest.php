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

namespace TheliaCMS\Tests\Unit\Media\Icon;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Icon\IconEligibility;

final class IconEligibilityTest extends TestCase
{
    private const string OPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';

    public function testADrawingOfPlainShapesIsFit(): void
    {
        $svg = self::OPEN.'<g transform="translate(1 1)"><path d="M0 0h24v24H0z" stroke-linecap="round"/><circle cx="12" cy="12" r="5"/><rect x="1" y="1" width="3" height="3" rx="1"/><line x1="0" y1="0" x2="1" y2="1"/><polyline points="0,0 1,1"/><polygon points="0,0 1,1 2,0"/><ellipse cx="1" cy="1" rx="2" ry="3"/></g></svg>';

        self::assertSame([], (new IconEligibility())->obstacles($svg));
        self::assertTrue((new IconEligibility())->isFit($svg));
    }

    /**
     * What a page keeps since the filter takes definitions: gradients, masks,
     * clipping paths and the references to them, classes, ids and a style a
     * page keeps.
     */
    #[DataProvider('kept')]
    public function testADrawingAPageKeepsWholeIsFit(string $body): void
    {
        self::assertSame([], array_map(strval(...), (new IconEligibility())->obstacles(self::OPEN.$body.'</svg>')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kept(): iterable
    {
        yield 'a gradient and its stops' => ['<defs><linearGradient id="a" gradientTransform="rotate(45)"><stop offset="0" stop-color="#000"/></linearGradient><radialGradient id="b" href="#a"/></defs><path d="M0 0" fill="url(#a)" stroke="url(#b)"/>'];
        yield 'a mask, as a round flag of the theme draws it' => ['<mask id="m" style="mask-type:luminance"><circle cx="1" cy="1" r="1" fill="#fff"/></mask><g mask="url(#m)"><path d="M0 0"/></g>'];
        yield 'a clipping path' => ['<clipPath id="c"><rect width="1" height="1"/></clipPath><g clip-path="url(#c)"><path d="M0 0"/></g>'];
        yield 'a class, an id and a style a page keeps' => ['<path d="M0 0" class="a" id="b" style="fill:red"/>'];
        yield 'an accessibility attribute' => ['<g aria-hidden="true" role="presentation"><path d="M0 0"/></g>'];
    }

    public function testNamespaceDeclarationsAreNotAttributesOfTheDrawing(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" viewBox="0 0 8 8"><path d="M0 0h8v8z"/></svg>';

        self::assertSame([], (new IconEligibility())->obstacles($svg));
    }

    /**
     * @param list<string> $expected what the editor reads, in English
     */
    #[DataProvider('refused')]
    public function testWhatAPageWouldNotKeepStandsInTheWay(string $body, array $expected): void
    {
        $obstacles = (new IconEligibility())->obstacles(self::OPEN.$body.'</svg>');

        self::assertSame($expected, array_map(strval(...), $obstacles));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function refused(): iterable
    {
        yield 'an element out of the list' => ['<path d="M0 0"/><text x="1">Hi</text>', ['some text (text)']];
        yield 'a use' => ['<use href="#a"/>', ['a reused shape (use)']];
        yield 'an element of another namespace' => ['<sodipodi:namedview xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"/>', ['an element a page does not keep (sodipodi:namedview)']];
        yield 'an element written with a prefix' => ['<svg:path xmlns:svg="http://www.w3.org/2000/svg" d="M0 0"/>', ['an element a page does not keep (svg:path)']];
        yield 'an attribute the element does not take' => ['<path d="M0 0" x="3"/>', ['an attribute a page does not keep (x)']];
        yield 'text in a group' => ['<g>hello<path d="M0 0"/></g>', ['some text (text)']];
        yield 'each obstacle once' => ['<use href="#a"/><use href="#b"/>', ['a reused shape (use)']];
        yield 'a style element is not read inside' => ['<style>.a{fill:red}</style>', ['CSS style (style)']];
        yield 'a reference to an id the drawing does not hold' => ['<g clip-path="url(#c)"><path d="M0 0"/></g>', ['a reference to a shape a page does not keep (clip-path)']];
        yield 'a reference to an id on an element a page drops' => ['<pattern id="p"><path d="M0 0"/></pattern><path d="M0 0" fill="url(#p)"/>', ['a pattern (pattern)', 'a reference to a shape a page does not keep (fill)']];
        yield 'a paint from another file' => ['<path d="M0 0" fill="url(other.svg#a)"/>', ['a color a page does not keep (fill)']];
        yield 'a gradient that inherits one by the old xlink:href' => ['<radialGradient xmlns:xlink="http://www.w3.org/1999/xlink" id="r" xlink:href="#r"/>', ['an old way of pointing to a shape (xlink:href)']];
        yield 'a gradient transform that is not one' => ['<linearGradient id="l" gradientTransform="url(#l)"/>', ['a value a page does not keep (gradientTransform)']];
        yield 'a mask asked for inside a mask' => ['<mask id="a"><rect width="1" height="1" mask="url(#b)"/></mask><mask id="b"><rect width="1" height="1"/></mask><g mask="url(#a)"><path d="M0 0"/></g>', ['a mask or a clipping inside another one (mask)']];
        yield 'a clipping path asked for inside a clipping path' => ['<clipPath id="a"><rect width="1" height="1" clip-path="url(#b)"/></clipPath><clipPath id="b"><rect width="1" height="1"/></clipPath><g clip-path="url(#a)"><path d="M0 0"/></g>', ['a mask or a clipping inside another one (clip-path)']];
        yield 'a style element and a style cut, said once' => ['<style>.a{fill:red}</style><path d="M0 0" style="fill:url(#a)"/>', ['CSS style (style)']];
        yield 'a style a page cuts' => ['<path d="M0 0" style="fill:url(#a);stroke:red"/>', ['CSS style (style)']];
    }

    public function testAFileThatIsNotXmlCannotBeRead(): void
    {
        self::assertSame(['a file that cannot be read'], array_map(strval(...), (new IconEligibility())->obstacles('<svg')));
    }
}
