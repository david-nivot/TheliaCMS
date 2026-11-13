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

namespace TheliaCMS\Tests\Unit\Content\Pipeline;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\Pipeline\InlineSvgPolicy;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;

/**
 * What a published page keeps of an inline SVG: the definitions that paint
 * and cut it, under ids of its own, and no reference that leaves it.
 */
final class InlineSvgPolicyTest extends TestCase
{
    /** A round flag, as the theme draws it: a disc masks three bands. */
    private const string FLAG = '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 512 512"><mask id="flagFr0"><circle cx="256" cy="256" r="256" fill="#fff"/></mask><g mask="url(#flagFr0)"><path fill="#eee" d="M167 0h178v512H167z"/><path fill="#0052b4" d="M0 0h167v512H0z"/><path fill="#d80027" d="M345 0h167v512H345z"/></g></svg>';

    /** Two gradients (the second inherits the first), a clipping path. */
    private const string GRADIENT = '<svg viewBox="0 0 24 24"><defs><linearGradient id="a" x1="0" y1="0" x2="1" y2="1" gradientUnits="userSpaceOnUse" gradientTransform="rotate(45)" spreadMethod="pad"><stop offset="0" stop-color="#f00" stop-opacity="1"/><stop offset="1" stop-color="#00f"/></linearGradient><radialGradient id="b" href="#a" cx="12" cy="12" r="10" fx="12" fy="12"/><clipPath id="c" clipPathUnits="userSpaceOnUse"><rect x="0" y="0" width="12" height="24"/></clipPath></defs><path d="M0 0h24v24H0z" fill="url(#a)" clip-path="url(#c)"/><circle cx="12" cy="12" r="4" fill="url(&quot;#b&quot;)" stroke="url(\'#a\')"/></svg>';

    private PublishedContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new PublishedContentSanitizer();
    }

    public function testAFlagKeepsItsMaskUnderAnIdOfItsOwn(): void
    {
        $html = (string) $this->sanitizer->html('<p>'.self::FLAG.'</p>');

        self::assertMatchesRegularExpression('/<mask id="(s[0-9a-f]{8}-1-flagFr0)"><circle[^>]*><\/circle><\/mask><g mask="url\(#\1\)">/', $html);
        self::assertSame(3, substr_count($html, '<path '));
    }

    public function testGradientsAndClippingPathsAreKept(): void
    {
        $html = (string) $this->sanitizer->html(self::GRADIENT);

        preg_match('/id="(s[0-9a-f]{8}-1-)a"/', $html, $match);
        self::assertNotEmpty($match, $html);
        $prefix = $match[1];

        foreach ([
            '<defs>',
            '<lineargradient id="'.$prefix.'a" x1="0" y1="0" x2="1" y2="1" gradientunits="userSpaceOnUse" gradienttransform="rotate(45)" spreadmethod="pad">',
            '<stop offset="0" stop-color="#f00" stop-opacity="1"></stop>',
            '<radialgradient id="'.$prefix.'b" href="#'.$prefix.'a" cx="12" cy="12" r="10" fx="12" fy="12">',
            '<clippath id="'.$prefix.'c" clippathunits="userSpaceOnUse">',
            'fill="url(#'.$prefix.'a)" clip-path="url(#'.$prefix.'c)"',
            'fill="url(#'.$prefix.'b)" stroke="url(#'.$prefix.'a)"',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    /**
     * Two copies of a flag in a page: the second must not draw with the mask
     * of the first, which a closed menu may hide.
     */
    public function testTwoCopiesOfAnIconDoNotShareTheirIds(): void
    {
        $html = (string) $this->sanitizer->html('<p>'.self::FLAG.'</p><p>'.self::FLAG.'</p>');

        preg_match_all('/<mask id="([^"]+)"/', $html, $masks);
        preg_match_all('/<g mask="url\(#([^)]+)\)"/', $html, $uses);

        self::assertCount(2, array_unique($masks[1]));
        self::assertSame($masks[1], $uses[1]);
    }

    public function testTwoMarkupsGiveTheirIconsDifferentPrefixes(): void
    {
        $first = (string) $this->sanitizer->html('<p>One</p>'.self::FLAG);
        $second = (string) $this->sanitizer->html('<p>Two</p>'.self::FLAG);

        preg_match('/<mask id="([^"]+)"/', $first, $one);
        preg_match('/<mask id="([^"]+)"/', $second, $two);

        self::assertNotSame($one[1], $two[1]);
    }

    /**
     * A page saved again without a change is written the same: no history or
     * cache sees a change that is not one. And markup cleaned once (a header
     * text saved as it was published) is not prefixed twice.
     */
    public function testTheSameMarkupIsWrittenTheSameWayAndCleaningTwiceChangesNothing(): void
    {
        $once = (string) $this->sanitizer->html(self::GRADIENT.self::FLAG);

        self::assertSame($once, $this->sanitizer->html(self::GRADIENT.self::FLAG));
        self::assertSame($once, $this->sanitizer->html($once));
    }

    #[DataProvider('referencesThatLeave')]
    public function testNoReferenceLeavesTheSvg(string $body, string $notExpected): void
    {
        $html = (string) $this->sanitizer->html('<svg viewBox="0 0 8 8"><defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient><mask id="m"><rect width="8" height="8" fill="#fff"/></mask></defs>'.$body.'</svg>');

        self::assertStringNotContainsString($notExpected, $html);
        self::assertStringNotContainsString('evil', $html);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function referencesThatLeave(): iterable
    {
        yield 'a paint from another host' => ['<path d="M0 0" fill="url(https://evil.example/a.svg#g)"/>', 'M0 0" fill='];
        yield 'a mask from another host' => ['<path d="M0 0" mask="url(https://evil.example/m.svg#m)"/>', 'mask="url(https'];
        yield 'a quoted clipping path from another host' => ['<path d="M0 0" clip-path="url(&quot;https://evil.example/c.svg#c&quot;)"/>', 'clip-path='];
        yield 'a gradient that inherits one from another host' => ['<radialGradient id="r" href="https://evil.example/g.svg#x"/>', 'href='];
        yield 'a gradient that inherits one from a data address' => ['<radialGradient id="r" href="data:image/svg+xml,evil#x"/>', 'href='];
        yield 'a paint that names an id the SVG does not hold' => ['<path d="M0 0" fill="url(#elsewhere)"/>', 'elsewhere'];
        yield 'a mask that names an id the SVG does not hold' => ['<path d="M0 0" mask="url(#elsewhere)"/>', 'elsewhere'];
        yield 'a stop color that is a reference' => ['<linearGradient id="h"><stop offset="0" stop-color="url(#g)"/></linearGradient>', 'stop-color="url'];
        yield 'a gradient transform that is not a transform' => ['<linearGradient id="h" gradientTransform="rotate(1) url(evil)"/>', 'gradienttransform='];
        yield 'a reused shape' => ['<use href="#g"/>', '<use'];
        yield 'a style sheet, which styles the whole page' => ['<style>body{display:none}</style>', 'display'];
        yield 'an animation that rewrites a link' => ['<animate attributeName="href" values="javascript:alert(1)"/>', '<animate'];
        yield 'a filter' => ['<filter id="f"><feImage href="https://evil.example/i.png"/></filter>', '<filter'];
    }

    /**
     * A reference to an id written in an SVG names that SVG only: the same
     * name in another SVG of the page is not followed.
     */
    public function testAReferenceDoesNotReachAnotherSvgOfThePage(): void
    {
        $html = (string) $this->sanitizer->html('<svg viewBox="0 0 8 8"><mask id="m"><rect width="8" height="8"/></mask></svg><svg viewBox="0 0 8 8"><path d="M0 0" mask="url(#m)"/></svg>');

        self::assertStringNotContainsString('mask="', $html);
    }

    /** The `href` of a link is a link: only that of a gradient is held to the SVG. */
    public function testALinkAroundAnIconKeepsItsAddress(): void
    {
        $html = (string) $this->sanitizer->html('<a href="https://www.thelia.net/" id="home">'.self::FLAG.'</a>');

        self::assertStringContainsString('<a href="https://www.thelia.net/" id="home">', $html);
    }

    public function testMarkupWithoutSvgIsLeftAsItIs(): void
    {
        $markup = '<p id="a" class="b">Text</p>';

        self::assertSame($markup, (new InlineSvgPolicy())->clean($markup));
    }

    /**
     * The validation of the preparation screen and the filter of a page read
     * the same rules: a file the validation finds whole comes out of a page
     * the same, its ids aside, and a file it finds cut loses something there.
     */
    #[DataProvider('fixtures')]
    public function testTheValidationSaysWhatAPageKeeps(string $file, bool $kept): void
    {
        $svg = trim((string) file_get_contents($file));
        $violations = (new InlineSvgPolicy())->validate($svg, PublishedContentSanitizer::ACCESSIBILITY_ATTRIBUTES);
        $published = (string) $this->sanitizer->html($svg);

        if ($kept) {
            self::assertSame([], $violations, basename($file));
            self::assertSame(self::drawing($svg), self::drawing($published), basename($file));
        } else {
            self::assertNotSame([], $violations, basename($file));
            self::assertNotSame(self::drawing($svg), self::drawing($published), basename($file));
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function fixtures(): iterable
    {
        foreach (['kept' => true, 'cut' => false] as $folder => $kept) {
            foreach (glob(\dirname(__DIR__, 3).'/Fixtures/inline-svg/'.$folder.'/*.svg') ?: [] as $file) {
                yield $folder.'/'.basename($file) => [$file, $kept];
            }
        }
    }

    /**
     * The drawing of some markup, one line per element: its name and its
     * attributes, in lowercase as a page writes them, without the namespace
     * declarations a parser keeps or not, and with ids read back without the
     * prefix a page gives them.
     *
     * @return list<string>
     */
    private static function drawing(string $markup): array
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($markup), $markup);
        $lines = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            $attributes = [];

            foreach ($element->attributes ?? [] as $attribute) {
                $attributes[] = strtolower($attribute->nodeName).'='.preg_replace('/s[0-9a-f]{8}-[0-9]+-/', '', (string) $attribute->nodeValue);
            }

            sort($attributes);
            $lines[] = strtolower($element->localName ?? $element->nodeName).' '.implode(' ', $attributes);
        }

        return $lines;
    }

    #[DataProvider('values')]
    public function testEachValueTakesTheFormOfItsAttribute(string $element, string $attribute, string $value, bool $accepted): void
    {
        self::assertSame($accepted, InlineSvgPolicy::acceptsValue($element, $attribute, $value));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function values(): iterable
    {
        yield 'a color' => ['path', 'fill', '#0052b4', true];
        yield 'currentColor' => ['path', 'fill', 'currentColor', true];
        yield 'a local gradient' => ['path', 'fill', 'url(#a)', true];
        yield 'a gradient of another file' => ['path', 'fill', 'url(other.svg#a)', false];
        yield 'no mask' => ['g', 'mask', 'none', true];
        yield 'a mask that is a color' => ['g', 'mask', '#fff', false];
        yield 'a stop color' => ['stop', 'stop-color', 'rgb(0, 0, 0)', true];
        yield 'a stop color that is a reference' => ['stop', 'stop-color', 'url(#a)', false];
        yield 'a gradient transform' => ['lineargradient', 'gradienttransform', 'matrix(1 0 0 1 2 3)', true];
        yield 'the href of a gradient' => ['lineargradient', 'href', '#a', true];
        yield 'the href of a gradient that leaves' => ['lineargradient', 'href', 'https://example.com/#a', false];
        yield 'the href of a link' => ['a', 'href', 'https://example.com/', true];
        yield 'another namespace' => ['svg', 'xmlns', 'http://www.w3.org/1999/xhtml', false];
    }
}
