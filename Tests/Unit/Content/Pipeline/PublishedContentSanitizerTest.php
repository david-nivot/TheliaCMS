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
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\Theme\ComponentPropsReader;
use TheliaCMS\Content\Theme\ThemeComponentSections;
use Twig\Environment;

/**
 * The editor profile is a client, not a trusted administrator: whatever the
 * browser sends is treated as hostile and filtered on the server before it can
 * reach a published page.
 */
final class PublishedContentSanitizerTest extends TestCase
{
    private PublishedContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new PublishedContentSanitizer();
    }

    /**
     * The sanitizer runs before the rewriter in the publication: an attribute
     * it dropped would never reach the one that reads it.
     */
    public function testKeepsTheWidthASectionDeclaresForItsImages(): void
    {
        $html = $this->sanitizer->html('<figure class="cms-image" data-cms-sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"><img src="/image-library/7/full/max/0/default.jpg" alt=""></figure>');

        self::assertStringContainsString('data-cms-sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"', (string) $html);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hostileHtml(): iterable
    {
        yield 'script element' => ['<p>Hello</p><script>alert(1)</script>', 'alert(1)'];
        yield 'inline event handler' => ['<p onclick="alert(1)">Hello</p>', 'onclick'];
        yield 'error handler on a broken image' => ['<img src="x" onerror="alert(1)">', 'onerror'];
        yield 'javascript scheme in a link' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'];
        yield 'javascript scheme with mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>', 'avascript'];
        yield 'data url document in a link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', 'text/html'];
        yield 'form posting elsewhere' => ['<form action="https://evil.test"><input name="p"></form>', 'evil.test'];
        yield 'script in an svg' => ['<svg><script>alert(1)</script></svg>', 'alert(1)'];
        yield 'event handler on an svg' => ['<svg onload="alert(1)"><path d="M0 0"/></svg>', 'onload'];
        yield 'use of an svg of another host' => ['<svg><use href="https://evil.test/x.svg#a"/></svg>', 'evil.test'];
        yield 'use with the legacy link attribute' => ['<svg><use xlink:href="https://evil.test/x.svg#a"/></svg>', 'evil.test'];
        yield 'foreignObject in an svg' => ['<svg><foreignObject><p>x</p></foreignObject></svg>', 'foreignobject'];
        yield 'image in an svg' => ['<svg><image href="https://evil.test/x.png"/></svg>', 'evil.test'];
        yield 'link in an svg' => ['<svg><a href="javascript:alert(1)"><path d="M0 0"/></a></svg>', 'javascript:'];
        yield 'animation setting a link in an svg' => ['<svg><a href="/"><animate attributeName="href" values="javascript:alert(1)"/></a></svg>', 'javascript:'];
        yield 'paint loaded from another host' => ['<svg><path d="M0 0" fill="url(https://evil.test/p.svg#a)"/></svg>', 'evil.test'];
        yield 'stroke loaded from another host' => ['<svg><path d="M0 0" stroke="url(//evil.test/p.svg#a)"/></svg>', 'evil.test'];
        yield 'style attribute of an svg loading from another host' => ['<svg><path d="M0 0" style="fill:url(https://evil.test/p.svg#a)"/></svg>', 'evil.test'];
        yield 'object element' => ['<object data="https://evil.test/x.swf"></object>', '<object'];
        yield 'embed element' => ['<embed src="https://evil.test/x">', '<embed'];
        yield 'base tag rewriting every link' => ['<base href="https://evil.test/">', '<base'];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test">', 'evil.test'];
        yield 'style element' => ['<style>body{background:url(https://evil.test/x)}</style>', 'evil.test'];
        yield 'srcdoc iframe' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>', 'srcdoc'];
        yield 'style attribute loading a picture from another host' => ['<p style="background:url(https://evil.test/t.png)">x</p>', 'evil.test'];
        yield 'style attribute loading from another host without a scheme' => ['<p style="background-image:url(&quot;//evil.test/t.png&quot;)">x</p>', 'evil.test'];
        yield 'style attribute with the legacy expression vector' => ['<p style="width:expression(alert(1))">x</p>', 'expression('];
        yield 'style attribute with the legacy behaviour vector' => ['<p style="behavior:url(x.htc)">x</p>', 'behavior'];
        yield 'style attribute with a javascript url' => ['<p style="background:url(javascript:alert(1))">x</p>', 'javascript'];
        yield 'style attribute with a remote picture set' => ['<p style="background-image:image-set(&quot;https://evil.test/t.png&quot; 1x)">x</p>', 'evil.test'];
        yield 'style attribute with an escaped url' => ['<p style="background:u\72l(https\3a //evil.test/t.png)">x</p>', 'evil.test'];
        yield 'style attribute opening a rule' => ['<p style="color:red}p{background:url(https:evil.test/t.png)">x</p>', 'evil.test'];
    }

    #[DataProvider('hostileHtml')]
    public function testStripsHostileMarkup(string $html, string $forbidden): void
    {
        self::assertStringNotContainsStringIgnoringCase(
            $forbidden,
            (string) $this->sanitizer->html($html),
        );
    }

    /**
     * The icons of the components of the theme are inline svg (`ux_icon()`),
     * which the filter once removed: a button arrow came out empty. The
     * sanitizer writes `viewBox` in lowercase, which a browser reads back as
     * `viewBox` inside an svg. The `xmlns` declaration is kept by the native
     * parser of PHP 8.4 and dropped by the one used on 8.3: an inline svg takes
     * its namespace from the HTML parser either way, so it is left out of the
     * comparison.
     */
    public function testKeepsTheSvgIconsOfTheComponents(): void
    {
        $arrow = '<svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M27.6792 13.2258L14.5 1.5" fill="currentColor"></path></svg>';
        $stroked = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><g transform="translate(1 1)"><path d="M5 12h14" fill-rule="evenodd" clip-rule="evenodd"></path><circle cx="12" cy="12" r="3"></circle><rect x="1" y="1" width="2" height="2" rx="1"></rect><polyline points="1,2 3,4"></polyline></g></svg>';

        self::assertSame('<span class="button-icon">'.str_replace('viewBox', 'viewbox', $arrow).'</span>', $this->sanitizer->html('<span class="button-icon">'.$arrow.'</span>'));
        $withoutNamespace = static fn (?string $html): string => str_replace(' xmlns="http://www.w3.org/2000/svg"', '', (string) $html);

        self::assertSame($withoutNamespace(str_replace('viewBox', 'viewbox', $stroked)), $withoutNamespace($this->sanitizer->html($stroked)));
    }

    public function testKeepsTheMarkupAPageIsMadeOf(): void
    {
        $html = '<div class="cms-page-content"><h1 id="title">Hello</h1>'
            .'<p style="color:#333">A <strong>bold</strong> <em>claim</em>, with a '
            .'<a href="/contact" title="Contact">link</a>.</p>'
            .'<ul><li>One</li></ul>'
            .'<img src="/image-library/1/full/max/0/default.jpg" alt="A picture" '
            .'width="800" height="600" loading="lazy" decoding="async" fetchpriority="high">'
            .'</div>';

        $sanitized = (string) $this->sanitizer->html($html);

        foreach ([
            'class="cms-page-content"', 'id="title"', '<h1', '<strong>', '<em>',
            'href="/contact"', 'title="Contact"', '<li>One</li>', 'alt="A picture"',
            'width="800"', 'height="600"', 'loading="lazy"', 'decoding="async"',
            'fetchpriority="high"', 'style="color:#333"',
        ] as $expected) {
            self::assertStringContainsString($expected, $sanitized);
        }
    }

    /**
     * The catalogue names each section by its own heading and hides decorative
     * icons; dropping those attributes on the way out would undo, silently, the
     * one thing the blocks are careful about.
     */
    public function testKeepsWhatMakesAPageAccessible(): void
    {
        $html = '<section aria-labelledby="cms-hero-title" role="region">'
            .'<h2 id="cms-hero-title">Hello</h2>'
            .'<i class="icon" aria-hidden="true"></i>'
            .'<a href="/" aria-current="page">Home</a>'
            .'<details open><summary>A question</summary><p>An answer</p></details>'
            .'<p lang="en" dir="ltr">In English</p>'
            .'</section>';

        $sanitized = (string) $this->sanitizer->html($html);

        foreach ([
            'aria-labelledby="cms-hero-title"', 'role="region"', 'aria-hidden="true"',
            'aria-current="page"', '<details open', 'lang="en"', 'dir="ltr"',
        ] as $expected) {
            self::assertStringContainsString($expected, $sanitized);
        }
    }

    public function testKeepsThePartialMarkersTheRendererSubstitutes(): void
    {
        $html = '<div data-cms-partial="cms-form" data-props=\'{"code":"contact"}\'></div>';

        $sanitized = (string) $this->sanitizer->html($html);

        self::assertStringContainsString('data-cms-partial="cms-form"', $sanitized);
        self::assertStringContainsString('data-cms-section="hero"', $this->sanitizer->html('<section data-cms-section="hero" data-other="x"><p>Hi</p></section>'), 'The anchor of a section survives the filter; any other data attribute does not.');
        self::assertStringNotContainsString('data-other', $this->sanitizer->html('<section data-cms-section="hero" data-other="x"><p>Hi</p></section>'));
        self::assertStringContainsString('data-props', $sanitized);
    }

    /**
     * A component of the theme wires its behaviour with Stimulus: published
     * without its controller, a carousel is a strip of pictures whose arrows
     * do nothing. The theme names the controllers its components use; those
     * are kept, any other is dropped, so that written text cannot wire one
     * the theme never opened to pages.
     */
    public function testKeepsTheStimulusAttributesOfTheControllersTheThemeDeclares(): void
    {
        $directory = sys_get_temp_dir().'/theliacms-theme-'.bin2hex(random_bytes(4));
        mkdir($directory.'/config', 0o777, true);

        try {
            file_put_contents($directory.'/config/theliacms.yaml', "sections:\n    components: []\n    controllers:\n        - Organisms--Carousel--base\n");
            $sanitizer = new PublishedContentSanitizer(theme: $this->themeIn($directory));

            $sanitized = (string) $sanitizer->html(
                '<section class="Carousel" data-controller="Organisms--Carousel--base" data-organisms--carousel--base-interval-value="5">'
                .'<div tabindex="0" data-organisms--carousel--base-target="viewport" data-action="keydown->Organisms--Carousel--base#keydown"></div>'
                .'<div hidden data-organisms--carousel--base-target="controls">'
                .'<button type="button" data-action="Organisms--Carousel--base#goTo" data-organisms--carousel--base-index-param="1">2</button>'
                .'</div></section>'
                .'<div data-controller="Organisms--Carousel--base hijack" data-action="click->hijack#run Organisms--Carousel--base#next" data-hijack-url-value="https://evil.test" data-hijack-target="x">Written text</div>'
                .'<div data-controller="hijack" data-other="x">Written text</div>',
            );

            foreach ([
                'data-controller="Organisms--Carousel--base"', 'data-organisms--carousel--base-interval-value="5"',
                'data-organisms--carousel--base-target="viewport"', 'data-action="keydown-&gt;Organisms--Carousel--base#keydown"',
                'data-organisms--carousel--base-target="controls"', 'data-action="Organisms--Carousel--base#goTo"',
                'data-organisms--carousel--base-index-param="1"', 'data-action="Organisms--Carousel--base#next"',
            ] as $expected) {
                self::assertStringContainsString($expected, $sanitized);
            }

            foreach (['hijack', 'evil.test', 'data-other'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $sanitized, 'A controller the theme does not name is dropped.');
            }

            self::assertSame(
                '<div>Text</div>',
                (new PublishedContentSanitizer())->html('<div data-controller="Organisms--Carousel--base" data-action="Organisms--Carousel--base#next">Text</div>'),
                'Without a theme naming it, no controller is kept.',
            );
        } finally {
            @unlink($directory.'/config/theliacms.yaml');
            @rmdir($directory.'/config');
            @rmdir($directory);
        }
    }

    /**
     * Controls a script reveals start hidden, and a carousel says what it is
     * to a screen reader: neither carries script nor an address.
     */
    public function testKeepsHiddenControlsAndTheRoleDescriptionOfAWidget(): void
    {
        $sanitized = (string) $this->sanitizer->html('<section role="group" aria-roledescription="carousel"><div hidden>Controls</div></section>');

        self::assertStringContainsString('aria-roledescription="carousel"', $sanitized);
        self::assertStringContainsString('<div hidden', $sanitized);
    }

    public function testDropsAnIframeUnlessCustomCodeIsAllowed(): void
    {
        $html = '<iframe src="https://player.test/embed/1" title="A video"></iframe>';

        self::assertStringNotContainsString('<iframe', (string) $this->sanitizer->html($html));
        self::assertStringContainsString('<iframe', (string) $this->sanitizer->html($html, allowCustomCode: true));
    }

    public function testKeepsALongPageWhole(): void
    {
        $html = str_repeat('<p class="wysiwyg">Les conditions générales de vente.</p>', 2_000).'<p>La fin de la page.</p>';

        self::assertGreaterThan(100_000, \strlen($html));
        self::assertTrue(str_contains((string) $this->sanitizer->html($html), 'La fin de la page.'), 'The end of the page was cut off.');
    }

    public function testReadsNoMoreMarkupThanItIsGiven(): void
    {
        $sanitizer = new PublishedContentSanitizer(maxLength: 40);

        $sanitized = (string) $sanitizer->html('<p>Kept</p><p>Past the length it is given</p>');

        self::assertStringStartsWith('<p>Kept</p>', $sanitized);
        self::assertStringNotContainsString('given', $sanitized);
    }

    public function testLeavesEmptyContentAlone(): void
    {
        self::assertNull($this->sanitizer->html(null));
        self::assertSame('', $this->sanitizer->html(''));
        self::assertSame('   ', $this->sanitizer->html('   '));
        self::assertNull($this->sanitizer->css(null));
        self::assertSame('', $this->sanitizer->css(''));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hostileCss(): iterable
    {
        yield 'import of a remote sheet' => ['@import url("https://evil.test/x.css"); p{color:red}', '@import'];
        yield 'import without url()' => ["@import 'https://evil.test/x.css';", '@import'];
        yield 'legacy expression' => ['p{width:expression(alert(1))}', 'expression('];
        yield 'behavior property' => ['p{behavior:url(#default#time2)}', 'behavior'];
        yield 'remote background' => ['p{background:url(https://evil.test/track.png)}', 'evil.test'];
        yield 'protocol relative background' => ['p{background:url(//evil.test/track.png)}', 'evil.test'];
        yield 'scheme without slashes' => ['p{background:url(https:evil.test/track.png)}', 'evil.test'];
        yield 'document in a data url' => ['p{background:url(data:text/html;base64,PHNjcmlwdD4=)}', 'text/html'];
        yield 'svg in a data url' => ['p{background:url("data:image/svg+xml;utf8,%3Csvg%3E%3C/svg%3E")}', 'svg+xml'];
        yield 'javascript url' => ['p{background:url(javascript:alert(1))}', 'javascript'];
        yield 'picture set with a remote string' => ['p{background-image:image-set("https://evil.test/p.png" 1x)}', 'evil.test'];
        yield 'prefixed picture set' => ['p{background-image:-webkit-image-set(url(https://evil.test/p.png) 1x)}', 'evil.test'];
        yield 'src function' => ['p{background-image:src("https://evil.test/p.png")}', 'evil.test'];
        yield 'cross fade with a remote string' => ['p{background-image:cross-fade(url(/a.png) 50%, "https://evil.test/p.png")}', 'evil.test'];
        yield 'escaped url' => ['p{background:u\72l(https\3a //evil.test/p.png)}', 'evil.test'];
        yield 'escaped import' => ['\@import url(https://evil.test/x.css);p{color:red}', 'evil.test'];
        yield 'escaped property' => ['p{beh\61vior:url(/x.htc)}', 'x.htc'];
        yield 'mozilla binding' => ['p{-moz-binding:url(/x.xml#xss)}', '-moz-binding'];
        yield 'attribute value' => ['p{background-image:attr(data-x type(<url>))}', 'attr('];
        yield 'font loaded from another host' => ['@font-face{font-family:x;src:local(x),url(https:evil.test/f.woff2)}', 'evil.test'];
        yield 'remote picture in a nested rule' => ['.a{color:red;&:hover{background:url(https:evil.test/p.png)}}', 'evil.test'];
        yield 'remote picture in a media query' => ['@media (min-width:1px){p{background:url(https:evil.test/p.png)}}', 'evil.test'];
        yield 'end of a style element' => ['p{content:"</style><script>alert(1)</script>"}', '</style>'];
    }

    /**
     * What is refused goes with its declaration, and nothing else: the rule
     * keeps what it set besides.
     */
    public function testDropsTheDeclarationItRefusesAndKeepsTheRest(): void
    {
        self::assertSame('p{color:red;margin:0}', $this->sanitizer->css('p{color:red;background:url(javascript:x);margin:0}'));
        self::assertSame('p{color:red}', $this->sanitizer->css('@import url(/x.css);p{color:red}'));
        self::assertSame('.a{color:red}', $this->sanitizer->css('.a{color:red}\31 0{color:blue}'));
    }

    /**
     * The stylesheets of the components are printed one after the other:
     * what one leaves open must not take in the rules of the next.
     */
    public function testLeavesNothingOpenForTheNextStylesheet(): void
    {
        self::assertSame('.a{color:red}', $this->sanitizer->css('.a{color:red'));
        self::assertSame('@media (min-width:1px){.a{color:red}}', $this->sanitizer->css('@media (min-width:1px){.a{color:red'));
        self::assertSame('.a{color:red}', $this->sanitizer->css('.a{color:red}/* never closed'));
        self::assertSame('.a{color:red;}', $this->sanitizer->css('.a{color:red;width:calc(1px + 2px}'));
        self::assertSame('.a{color:red;}', $this->sanitizer->css('.a{color:red;width:1px /* open}'));
        self::assertSame('', $this->sanitizer->css('[title="open{color:red}'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function cssAPageIsStyledWith(): iterable
    {
        yield 'colours' => ['.a{color:rgb(1 2 3 / 50%);background:hsl(10 20% 30%);border-color:oklch(70% 0.1 200);outline-color:color-mix(in srgb, red 40%, blue);caret-color:light-dark(#000,#fff)}'];
        yield 'arithmetic and custom properties' => ['.a{width:calc(100% - 2rem);margin:min(1rem,2vw) max(1px,2px);font-size:clamp(1rem, 2vw + 1rem, 3rem);color:var(--cms-color-accent, #333);--gap:1rem}'];
        yield 'gradients' => ['.a{background:linear-gradient(90deg, #fff, rgb(0 0 0 / 0%)),radial-gradient(circle at 50% 50%, red, transparent),repeating-conic-gradient(red 0 10deg, blue 10deg 20deg)}'];
        yield 'motion' => ['.a{transform:translate(-50%, 0) rotate(3deg) scale(1.1);transition:opacity .2s cubic-bezier(.2,.8,.2,1);animation:fade 1s steps(4, end)}@keyframes fade{from{opacity:0}50%{opacity:.5}to{opacity:1}}'];
        yield 'filters and shapes' => ['.a{filter:drop-shadow(0 1px 2px rgb(0 0 0 / 20%)) blur(2px);clip-path:polygon(0 0,100% 0,100% 80%,0 100%)}'];
        yield 'grid and counters' => ['.a{grid-template-columns:repeat(auto-fit, minmax(12rem, 1fr));counter-reset:step}.a li::before{content:counter(step) ". ";font-family:"Helvetica Neue", serif}'];
        yield 'pictures of this site' => ['.a{background-image:url("/image-library/2/full/max/0/x.webp")}.b{background:url( /x.png ) no-repeat}.c{background:url(data:image/jpeg;base64,/9j/4AAQ)}'];
        yield 'conditions and nesting' => ['@supports (display:grid){@container card (min-width:20rem){.a{display:grid}}}.a{color:red;&:hover{color:blue!important}}@layer base{.a{margin:0}}'];
        yield 'comments' => ['/* the hero */.a{color:red /* brand */}'];
        yield 'selectors' => ['.a:not(.b) > li:nth-child(2n + 1), [data-kind="x"]:is(:hover,:focus-visible){color:red}'];
    }

    #[DataProvider('cssAPageIsStyledWith')]
    public function testKeepsWhatAStylesheetIsMadeOf(string $css): void
    {
        self::assertSame($css, $this->sanitizer->css($css));
    }

    #[DataProvider('hostileCss')]
    public function testFiltersHostileCss(string $css, string $forbidden): void
    {
        self::assertStringNotContainsStringIgnoringCase($forbidden, (string) $this->sanitizer->css($css));
    }

    public function testKeepsTheCssAPageIsStyledWith(): void
    {
        $css = '.cms-page-content h1{color:#333;font-size:2rem}'
            .'.cms-page-content .hero{background-image:url(/image-library/1/full/max/0/default.jpg)}'
            .'.cms-page-content .logo{background-image:url(data:image/png;base64,iVBORw0KGgo=)}'
            .'@media (min-width:768px){.cms-page-content h1{font-size:3rem}}';

        self::assertSame($css, $this->sanitizer->css($css));
    }

    private function themeIn(string $directory): ThemeComponentSections
    {
        $theme = $this->createStub(TemplateDefinition::class);
        $theme->method('getAbsolutePath')->willReturn($directory);
        $theme->method('getParentList')->willReturn([]);

        $templates = $this->createStub(TemplateHelperInterface::class);
        $templates->method('getActiveFrontTemplate')->willReturn($theme);

        return new ThemeComponentSections(
            $templates,
            new ComponentPropsReader($this->createStub(Environment::class), $this->createStub(UrlGeneratorInterface::class)),
            $this->createStub(ComponentRendererInterface::class),
            new NullLogger(),
        );
    }
}
