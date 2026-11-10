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
        yield 'a gradient and its stops' => ['<defs><linearGradient id="a"><stop offset="0"/></linearGradient></defs><path d="M0 0" fill="url(#a)"/>', ['a block of definitions (defs)', 'a gradient or a pattern as paint (fill)']];
        yield 'an element out of the list' => ['<path d="M0 0"/><text x="1">Hi</text>', ['some text (text)']];
        yield 'a mask' => ['<mask id="m"><rect width="1" height="1"/></mask>', ['a mask (mask)']];
        yield 'a use' => ['<use href="#a"/>', ['a reused shape (use)']];
        yield 'an element of another namespace' => ['<sodipodi:namedview xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd"/>', ['an element a page does not keep (sodipodi:namedview)']];
        yield 'a style attribute' => ['<path d="M0 0" style="fill:red"/>', ['CSS style (style)']];
        yield 'a class attribute' => ['<path d="M0 0" class="a"/>', ['CSS classes (class)']];
        yield 'a clip-path attribute' => ['<g clip-path="url(#c)"><path d="M0 0"/></g>', ['a clipping (clip-path)']];
        yield 'an element written with a prefix' => ['<svg:path xmlns:svg="http://www.w3.org/2000/svg" d="M0 0"/>', ['an element a page does not keep (svg:path)']];
        yield 'an attribute the element does not take' => ['<path d="M0 0" x="3"/>', ['an attribute a page does not keep (x)']];
        yield 'text in a group' => ['<g>hello<path d="M0 0"/></g>', ['some text (text)']];
        yield 'each obstacle once' => ['<path d="M0 0" class="a"/><path d="M1 1" class="b"/>', ['CSS classes (class)']];
        yield 'a style element is not read inside' => ['<style>.a{fill:red}</style>', ['CSS style (style)']];
    }

    public function testAFileThatIsNotXmlCannotBeRead(): void
    {
        self::assertSame(['a file that cannot be read'], array_map(strval(...), (new IconEligibility())->obstacles('<svg')));
    }
}
