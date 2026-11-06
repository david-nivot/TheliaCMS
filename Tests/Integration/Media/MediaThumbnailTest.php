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

namespace TheliaCMS\Tests\Integration\Media;

use TheliaCMS\Media\Admin\MediaCatalog;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * A thumbnail is a resized copy for a raster image; an SVG cannot be resized
 * and the library answers 400 to any size or format asked of it.
 */
final class MediaThumbnailTest extends CmsIntegrationTestCase
{
    public function testAnSvgIsItsOwnThumbnailAndARasterImageIsResized(): void
    {
        $catalog = $this->getService(MediaCatalog::class);

        $svg = $catalog->item($this->image('thumb-vector.svg', 1600, 900), $this->locale());
        $raster = $catalog->item($this->image('thumb-raster.jpg', 1600, 900), $this->locale());

        self::assertNotNull($svg);
        self::assertNotNull($raster);
        self::assertStringEndsWith('.svg', (string) $svg->thumbnailUrl);
        self::assertSame($svg->url, $svg->thumbnailUrl);
        self::assertStringEndsNotWith('.webp', (string) $svg->url);
        self::assertStringContainsString('/320,!/0/default.jpg', (string) $raster->thumbnailUrl);
    }

    private function image(string $fileName, int $width, int $height): LibraryImage
    {
        $image = new LibraryImage();
        $image->setLocale($this->locale())
            ->setFileName($fileName)
            ->setTitle($fileName)
            ->setWidth($width)
            ->setHeight($height)
            ->setFileSize(1000);
        $image->save();

        return $image;
    }
}
