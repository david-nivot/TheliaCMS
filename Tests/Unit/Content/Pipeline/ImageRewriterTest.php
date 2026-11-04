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
use TheliaCMS\Content\Pipeline\ImageRewriter;
use TheliaCMS\Media\MediaFile;
use TheliaCMS\Media\MediaResolver;

final class ImageRewriterTest extends TestCase
{
    public function testWrapsALibraryImageInAPictureOfferingWebp(): void
    {
        $html = $this->rewrite('<p><img src="/image-library/7/full/max/0/default.jpg" alt="A photo"></p>');

        self::assertStringContainsString('<picture>', $html);
        self::assertStringContainsString('<source type="image/webp"', $html);
        self::assertStringContainsString('/image-library/7/full/480,270/0/default.webp 480w', $html);
        self::assertStringContainsString('alt="A photo"', $html);
    }

    public function testStatesTheIntrinsicSizeSoTheLayoutDoesNotJump(): void
    {
        $html = $this->rewrite('<img src="/image-library/7/full/max/0/default.jpg" alt="">');

        self::assertStringContainsString('width="1600"', $html);
        self::assertStringContainsString('height="900"', $html);
    }

    /**
     * The visitor is waiting for the first image; the others can arrive when
     * they scroll into view.
     */
    public function testPrioritisesTheFirstImageAndDefersTheRest(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/7/full/max/0/default.jpg" alt="one">'
            .'<img src="/image-library/7/full/max/0/default.jpg" alt="two">'
        );

        [$first, $second] = $this->imageTags($html);

        self::assertStringContainsString('fetchpriority="high"', $first);
        self::assertStringNotContainsString('loading=', $first);
        self::assertStringContainsString('loading="lazy"', $second);
        self::assertStringNotContainsString('fetchpriority', $second);
        self::assertStringContainsString('decoding="async"', $second);
    }

    public function testDropsTheLazyHintTheEditorPutOnTheFirstImage(): void
    {
        $html = $this->rewrite('<img src="/image-library/7/full/max/0/default.jpg" alt="" loading="lazy">');

        self::assertStringNotContainsString('loading="lazy"', $html);
        self::assertStringContainsString('fetchpriority="high"', $html);
    }

    /**
     * Serving a 480 wide copy of a 300 wide file is a bigger download for a
     * blurrier result: the file itself is the only candidate.
     */
    public function testOffersOnlyTheOriginalOfAnImageSmallerThanTheFirstWidth(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.png" alt="">',
            new MediaFile(id: 8, format: 'png', width: 300, height: 200),
        );

        self::assertSame(
            ['/image-library/8/full/max/0/default.png 300w'],
            $this->candidates($this->imageSrcset($html)),
        );
        self::assertSame(
            ['/image-library/8/full/max/0/default.webp 300w'],
            $this->candidates($this->sourceSrcset($html)),
        );
    }

    /**
     * A 900 wide image shown at 1440 used to be offered at 480 only, and came
     * out blurred: the original width is a candidate too.
     */
    public function testOffersTheOriginalWidthOfAnImageBetweenTwoWidths(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 900, height: 600),
        );

        self::assertSame(
            ['/image-library/8/full/480,320/0/default.jpg 480w', '/image-library/8/full/max/0/default.jpg 900w'],
            $this->candidates($this->imageSrcset($html)),
        );
        self::assertSame(
            ['/image-library/8/full/480,320/0/default.webp 480w', '/image-library/8/full/max/0/default.webp 900w'],
            $this->candidates($this->sourceSrcset($html)),
        );
    }

    public function testDoesNotRepeatAWidthWhenTheOriginalIsOneOfThem(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 960, height: 640),
        );

        self::assertSame(
            ['/image-library/8/full/480,320/0/default.jpg 480w', '/image-library/8/full/max/0/default.jpg 960w'],
            $this->candidates($this->imageSrcset($html)),
        );
        self::assertSame(
            ['/image-library/8/full/480,320/0/default.webp 480w', '/image-library/8/full/max/0/default.webp 960w'],
            $this->candidates($this->sourceSrcset($html)),
        );
    }

    /**
     * No candidate is wider than the file: nothing is ever enlarged.
     */
    public function testOffersTheOriginalAfterEveryWidthOfAnImageLargerThanTheLastOne(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 2400, height: 1600),
        );

        self::assertSame(
            [
                '/image-library/8/full/480,320/0/default.jpg 480w',
                '/image-library/8/full/960,640/0/default.jpg 960w',
                '/image-library/8/full/1440,960/0/default.jpg 1440w',
                '/image-library/8/full/max/0/default.jpg 2400w',
            ],
            $this->candidates($this->imageSrcset($html)),
        );
        self::assertSame(
            [
                '/image-library/8/full/480,320/0/default.webp 480w',
                '/image-library/8/full/960,640/0/default.webp 960w',
                '/image-library/8/full/1440,960/0/default.webp 1440w',
                '/image-library/8/full/max/0/default.webp 2400w',
            ],
            $this->candidates($this->sourceSrcset($html)),
        );
    }

    /**
     * A candidate announced at 480w is 480 wide: a portrait asked as `480,!`
     * came back fitted in a 480 square, 339 wide for a 1920 by 2715 file.
     */
    public function testAsksACandidateOfAPortraitAtItsWidthAndTheHeightOfItsProportions(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 1920, height: 2715),
        );

        self::assertSame(
            [
                '/image-library/8/full/480,679/0/default.jpg 480w',
                '/image-library/8/full/960,1358/0/default.jpg 960w',
                '/image-library/8/full/1440,2036/0/default.jpg 1440w',
                '/image-library/8/full/max/0/default.jpg 1920w',
            ],
            $this->candidates($this->imageSrcset($html)),
        );
    }

    public function testKeepsTheSquareOfTheImageRouteWhenTheHeightIsUnknown(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 900),
        );

        self::assertSame(
            ['/image-library/8/full/480,!/0/default.jpg 480w', '/image-library/8/full/max/0/default.jpg 900w'],
            $this->candidates($this->imageSrcset($html)),
        );
    }

    public function testKeepsTheSizesAndTheFallbackConsistentWithTheOriginalCandidate(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 900, height: 600),
        );

        self::assertStringContainsString('src="/image-library/8/full/max/0/default.jpg"', $html);
        self::assertStringContainsString('width="900"', $html);
        self::assertStringContainsString('height="600"', $html);
        self::assertSame(2, substr_count($html, 'sizes="(max-width: 900px) 100vw, 900px"'));
    }

    /**
     * A slot wider than the file makes the browser read the file at the width
     * of the viewport: a 308px pack shot left at `width: auto` came out at
     * 1440px, blurred.
     */
    public function testAnnouncesNoSlotWiderThanTheFile(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 308, height: 420),
        );

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 308px) 100vw, 308px"'));
    }

    public function testAnnouncesTheWidestSlotForAFileWiderThanIt(): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: 2400, height: 1600),
        );

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 1440px) 100vw, 1440px"'));
    }

    #[DataProvider('unknownWidths')]
    public function testOffersNoWidthWhenTheLibraryDoesNotKnowTheIntrinsicOne(?int $width): void
    {
        $html = $this->rewrite(
            '<img src="/image-library/8/full/max/0/default.jpg" alt="">',
            new MediaFile(id: 8, format: 'jpg', width: $width),
        );

        self::assertStringNotContainsString('sizes=', $html);
        self::assertStringNotContainsString('w,', $html);
        self::assertStringContainsString('<source type="image/webp" srcset="/image-library/8/full/max/0/default.webp"', $html);
    }

    public function testLeavesAnImageTheLibraryDoesNotOwnUntouchedApartFromLoadingHints(): void
    {
        $html = $this->rewrite(
            '<img src="https://cdn.test/photo.jpg" alt="Remote">'
            .'<img src="https://cdn.test/other.jpg" alt="Also remote">',
            null,
        );

        self::assertStringNotContainsString('<picture>', $html);
        self::assertStringNotContainsString('srcset', $html);
        self::assertStringContainsString('src="https://cdn.test/photo.jpg"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
    }

    public function testDoesNotNestAPictureInsideAPicture(): void
    {
        $html = $this->rewrite(
            '<picture><source type="image/webp" srcset="/image-library/7/full/max/0/default.webp">'
            .'<img src="/image-library/7/full/max/0/default.jpg" alt=""></picture>'
        );

        self::assertSame(1, substr_count($html, '<picture>'));
    }

    public function testKeepsTheSurroundingMarkup(): void
    {
        $html = $this->rewrite(
            '<section class="hero"><h2>Title</h2>'
            .'<img src="/image-library/7/full/max/0/default.jpg" alt=""></section>'
        );

        self::assertStringStartsWith('<section class="hero">', $html);
        self::assertStringContainsString('<h2>Title</h2>', $html);
        self::assertStringEndsWith('</section>', $html);
    }

    /**
     * `<source>` has no closing tag in HTML; DOMDocument writes one anyway.
     */
    public function testDoesNotEmitAClosingSourceTag(): void
    {
        $html = $this->rewrite('<img src="/image-library/7/full/max/0/default.jpg" alt="">');

        self::assertStringNotContainsString('</source>', $html);
    }

    public function testKeepsAccentedTextIntact(): void
    {
        $html = $this->rewrite('<p>Été à Genève</p><img src="/image-library/7/full/max/0/default.jpg" alt="Forêt">');

        self::assertStringContainsString('Été à Genève', $html);
        self::assertStringContainsString('alt="Forêt"', $html);
    }

    public function testLeavesEmptyContentAlone(): void
    {
        $rewriter = new ImageRewriter($this->resolver(null));

        self::assertNull($rewriter->rewrite(null));
        self::assertSame('', $rewriter->rewrite(''));
    }

    private function rewrite(string $html, ?MediaFile $media = new MediaFile(id: 7, format: 'jpg', width: 1600, height: 900)): string
    {
        return (string) (new ImageRewriter($this->resolver($media)))->rewrite($html);
    }

    /**
     * @return iterable<string, array{?int}>
     */
    public static function unknownWidths(): iterable
    {
        yield 'never measured' => [null];
        yield 'measured as zero' => [0];
    }

    private function imageSrcset(string $html): string
    {
        self::assertSame(1, preg_match('#<img\b[^>]*\ssrcset="([^"]*)"#', $html, $matches));

        return $matches[1];
    }

    private function sourceSrcset(string $html): string
    {
        self::assertSame(1, preg_match('#<source\b[^>]*\ssrcset="([^"]*)"#', $html, $matches));

        return $matches[1];
    }

    public function testWritesTheSizesASectionDeclaresAndTakesTheAttributeOut(): void
    {
        $html = $this->rewrite(
            '<figure data-cms-sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"><img src="/image-library/7/full/max/0/default.jpg" alt=""></figure>',
        );

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"'), 'The image and its WebP source.');
        self::assertStringNotContainsString('data-cms-sizes', $html);
    }

    public function testTakesTheDeclarationOfTheNearestElement(): void
    {
        $html = $this->rewrite(
            '<div data-cms-sizes="100vw"><p data-cms-sizes="calc(100vw - 2rem)"><img src="/image-library/7/full/max/0/default.jpg" alt="inner"></p>'
            .'<img src="/image-library/7/full/max/0/default.jpg" alt="outer"></div>',
        );

        [$inner, $outer] = $this->imageTags($html);

        self::assertStringContainsString('sizes="calc(100vw - 2rem)"', $inner);
        self::assertStringContainsString('sizes="100vw"', $outer);
    }

    public function testAnInvalidDeclarationIsNotReplacedByOneFurtherUp(): void
    {
        $html = $this->rewrite(
            '<div data-cms-sizes="100vw"><p data-cms-sizes="url(x)"><img src="/image-library/7/full/max/0/default.jpg" alt=""></p></div>',
        );

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 1440px) 100vw, 1440px"'), 'The nearest element is the one that knows.');
    }

    public function testAFixedHeightHoldsEachWidthToWhatTheProportionsOfTheFileGive(): void
    {
        // 1600 by 900 (the default of the helper): 5rem high is 8.8889rem wide.
        $html = $this->rewrite(
            '<div data-cms-sizes="(min-width: 48rem) 20rem, 100vw" data-cms-height="5rem"><img src="/image-library/7/full/max/0/default.jpg" alt=""></div>',
        );

        self::assertSame(2, substr_count($html, 'sizes="(min-width: 48rem) min(20rem, calc(5rem * 1.7778)), min(100vw, calc(5rem * 1.7778))"'));
        self::assertStringNotContainsString('data-cms-', $html);
    }

    public function testAFixedHeightAloneHoldsTheDefaultToo(): void
    {
        $html = $this->rewrite('<div data-cms-height="80px"><img src="/image-library/7/full/max/0/default.jpg" alt=""></div>');

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 1440px) min(100vw, calc(80px * 1.7778)), min(1440px, calc(80px * 1.7778))"'));
    }

    public function testAHeightThatIsNotALengthIsIgnored(): void
    {
        $html = $this->rewrite('<div data-cms-height="5rem; color: red"><img src="/image-library/7/full/max/0/default.jpg" alt=""></div>');

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 1440px) 100vw, 1440px"'));
        self::assertStringNotContainsString('data-cms-height', $html);
    }

    public function testAHeightIsLeftOutWhenTheLibraryDoesNotKnowTheProportions(): void
    {
        $html = $this->rewrite(
            '<div data-cms-sizes="100vw" data-cms-height="5rem"><img src="/image-library/8/full/max/0/default.jpg" alt=""></div>',
            new MediaFile(id: 8, format: 'jpg', width: 900),
        );

        self::assertSame(2, substr_count($html, 'sizes="100vw"'));
    }

    public function testAnImageOutsideEveryDeclarationKeepsTheDefault(): void
    {
        $html = $this->rewrite('<div data-cms-sizes="100vw"></div><img src="/image-library/7/full/max/0/default.jpg" alt="">');

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 1440px) 100vw, 1440px"'));
        self::assertStringNotContainsString('data-cms-sizes', $html);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function declarationsThatAreNotWidths(): iterable
    {
        yield 'a function that loads' => ['url(https://evil.test/x)'];
        yield 'a second attribute' => ['100vw" onerror="alert(1)'];
        yield 'an unbalanced calc' => ['calc(100vw - 2rem'];
        yield 'empty' => [''];
    }

    #[DataProvider('declarationsThatAreNotWidths')]
    public function testIgnoresADeclarationThatIsNotAListOfWidths(string $declared): void
    {
        $html = $this->rewrite(\sprintf('<div data-cms-sizes="%s"><img src="/image-library/7/full/max/0/default.jpg" alt=""></div>', htmlspecialchars($declared)));

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 1440px) 100vw, 1440px"'));
        self::assertStringNotContainsString('data-cms-sizes', $html);
        self::assertStringNotContainsString('evil.test', $html);
        self::assertStringNotContainsString('onerror', $html);
    }

    public function testTakesTheAttributeOutOfASectionWithoutALibraryImage(): void
    {
        $html = $this->rewrite(
            '<section data-cms-sizes="100vw"><img src="https://cdn.test/photo.jpg" alt="Remote"></section><p data-cms-sizes="100vw">Text</p>',
            null,
        );

        self::assertStringNotContainsString('data-cms-sizes', $html);
        self::assertStringNotContainsString('sizes=', $html);
    }

    /**
     * @return list<string>
     */
    private function candidates(string $srcset): array
    {
        // Candidates are separated by ", "; the "," inside a URL has no space.
        return explode(', ', $srcset);
    }

    /**
     * @return list<string>
     */
    private function imageTags(string $html): array
    {
        preg_match_all('#<img\b[^>]*>#', $html, $matches);

        return $matches[0];
    }

    private function resolver(?MediaFile $media): MediaResolver
    {
        return new class($media) implements MediaResolver {
            public function __construct(private readonly ?MediaFile $media)
            {
            }

            public function fromUrl(string $url): ?MediaFile
            {
                return str_starts_with($url, '/image-library/') ? $this->media : null;
            }
        };
    }
}
