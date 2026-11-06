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

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\ColumnsSection;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\Pipeline\ImageRewriter;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Media\MediaDescriptions;
use TheliaCMS\Media\MediaFile;
use TheliaCMS\Media\MediaResolver;

/**
 * What a section declares is read at the end of the publication, after the
 * sanitizer: the two have to agree on the attribute.
 */
final class ImageSlotPipelineTest extends TestCase
{
    public function testAnImageSectionIsPublishedWithTheSizesOfItsWidth(): void
    {
        $html = $this->publish((new ImageSection())->render(['image' => 'https://example.com/a.jpg', 'width' => 'narrow'], 'fr_FR'));

        self::assertSame(2, substr_count($html, 'sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"'));
        self::assertStringNotContainsString('data-cms-sizes', $html);
    }

    public function testColumnsArePublishedWithTheSizesOfOneColumn(): void
    {
        $html = $this->publish((new ColumnsSection())->render(['columns' => array_fill(0, 3, ['image' => 'https://example.com/a.jpg'])], 'fr_FR'));

        self::assertSame(6, substr_count($html, '(min-width: 77rem) min(23.67rem, calc(5rem * 1.7778)), (min-width: 48rem) min(calc((100vw - 2rem - 4rem) / 3), calc(5rem * 1.7778)), min(calc(100vw - 2rem), calc(5rem * 1.7778))'), 'Three images, each with its WebP source, held to the band they are drawn in.');
        self::assertStringNotContainsString('data-cms-', $html);
    }

    public function testAComponentOfTheSiteDeclaresItsOwnWidth(): void
    {
        $html = $this->publish('<div class="cms-site cms-site--logo-wall" data-cms-sizes="(min-width: 64rem) 20vw, 50vw"><img src="/image-library/7/full/max/0/default.jpg" alt=""></div>');

        self::assertSame(2, substr_count($html, 'sizes="(min-width: 64rem) 20vw, 50vw"'));
        self::assertStringNotContainsString('data-cms-sizes', $html);
    }

    public function testAnSvgIsPublishedWithoutAPictureOrAWidthSetWhateverTheSectionDeclares(): void
    {
        $html = $this->publish('<div class="cms-site cms-site--logo-wall" data-cms-sizes="(min-width: 64rem) 20vw, 50vw"><img src="/image-library/7/full/max/0/default.svg" alt=""></div>', new MediaFile(id: 7, format: 'svg', width: 120, height: 40));

        self::assertStringNotContainsString('<picture', $html);
        self::assertStringNotContainsString('srcset', $html);
        self::assertStringNotContainsString('sizes=', $html);
        self::assertStringNotContainsString('data-cms-sizes', $html);
        self::assertStringContainsString('src="/image-library/7/full/max/0/default.svg"', $html);
        self::assertStringContainsString('width="120" height="40"', $html);
    }

    private function publish(string $html, ?MediaFile $media = null): string
    {
        // Every picture is taken for one of the library, which is what the
        // sections draw with an id: they read the database for it.
        $media ??= new MediaFile(id: 7, format: 'jpg', width: 1600, height: 900);
        $resolver = new class($media) implements MediaResolver {
            public function __construct(private readonly MediaFile $media)
            {
            }

            public function fromUrl(string $url): ?MediaFile
            {
                return '' === $url ? null : $this->media;
            }
        };

        $descriptions = new class implements MediaDescriptions {
            public function statesOf(array $urls, string $locale): array
            {
                return [];
            }

            public function alternativeTextsOf(array $urls, string $locale): array
            {
                return [];
            }
        };

        return (string) (new ImageRewriter($resolver, $descriptions))->rewrite((new PublishedContentSanitizer())->html($html), 'fr_FR');
    }
}
