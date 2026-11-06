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

namespace TheliaCMS\Tests\Integration\Page;

use TheliaCMS\Media\Admin\MediaUsageFinder;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;

/**
 * The image of a page is a library image the page points at by id, with no
 * foreign key: it has to reach the front office, follow the page when it is
 * copied, and keep the media screen from deleting it.
 */
final class PageImageTest extends CmsIntegrationTestCase
{
    public function testThePublishedPageCarriesItsImage(): void
    {
        $locale = $this->locale();
        $image = $this->cmsImage('page-image.jpg', alt: 'Une façade au soleil');
        $page = $this->createPage('Page illustrée');
        $page->setImageId((int) $image->getId())->save();

        $published = $this->getService(PublishedPageRepository::class)->find((int) $page->getId(), $locale);

        self::assertNotNull($published?->image);
        self::assertSame((int) $image->getId(), $published->image->id);
        self::assertStringStartsWith('/image-library/'.$image->getId().'/', $published->image->url);
        self::assertSame('Une façade au soleil', $published->image->alt);
    }

    public function testAVideoLeftAsTheImageOfAPageIsNoImageOfThePublishedPage(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $page = $this->createPage('Page à vidéo');
        $page->setImageId((int) $video->getId())->save();

        $published = $this->getService(PublishedPageRepository::class)->find((int) $page->getId(), $this->locale());

        self::assertNotNull($published);
        self::assertNull($published->image, 'No /video-library address in an img, a card or og:image.');
    }

    public function testADecorativeImageHasAnEmptyAlternativeText(): void
    {
        $image = $this->cmsImage('decorative.jpg', alt: 'Ignored', decorative: true);
        $page = $this->createPage('Page décorée');
        $page->setImageId((int) $image->getId())->save();

        $published = $this->getService(PublishedPageRepository::class)->find((int) $page->getId(), $this->locale());

        self::assertSame('', $published?->image?->alt);
    }

    public function testAnImageDeletedFromTheLibraryLeavesThePageWithoutImage(): void
    {
        $image = $this->cmsImage('gone.jpg');
        $page = $this->createPage('Page orpheline');
        $page->setImageId((int) $image->getId())->save();
        $image->delete();

        $published = $this->getService(PublishedPageRepository::class)->find((int) $page->getId(), $this->locale());

        self::assertNotNull($published);
        self::assertNull($published->image);
    }

    public function testADuplicatedPageKeepsItsImage(): void
    {
        $image = $this->cmsImage('copied.jpg');
        $page = $this->createPage('Page illustrée à copier');
        $page->setImageId((int) $image->getId())->save();

        $copy = $this->writer()->duplicate($page, $this->locale(), '(copie)');

        self::assertSame((int) $image->getId(), $copy->getImageId());
    }

    public function testTheImageOfAPageCountsAsAUseOnce(): void
    {
        $image = $this->cmsImage('used.jpg');
        $imageId = (int) $image->getId();
        $page = $this->createPage(
            'Page qui montre deux fois',
            html: \sprintf('<img src="/image-library/%d/full/max/0/default.webp" alt="">', $imageId),
        );
        $page->setImageId($imageId)->save();

        $usages = $this->getService(MediaUsageFinder::class);

        self::assertSame([$imageId => 1], $usages->countsFor([$imageId]));
        self::assertSame(1, $usages->useCount($imageId));
    }

    public function testAnImageOnlyUsedAsThePageImageIsInUse(): void
    {
        $image = $this->cmsImage('page-only.jpg');
        $imageId = (int) $image->getId();
        $page = $this->createPage('Page à image seule');
        $page->setImageId($imageId)->save();

        $finder = $this->getService(MediaUsageFinder::class);
        $usages = $finder->pagesUsing($imageId);

        self::assertCount(1, $usages);
        self::assertSame((int) $page->getId(), (int) $usages[0]['page']->getId());
        self::assertSame([$imageId => 1], $finder->countsFor([$imageId]), 'The media grid would show the image as unused.');
    }

    private function cmsImage(string $fileName, ?string $alt = null, bool $decorative = false): LibraryImage
    {
        $image = new LibraryImage();
        $image->setDecorative($decorative ? 1 : 0);
        $image->setLocale($this->locale())->setFileName($fileName)->setTitle($fileName)->setAlt($alt);
        $image->save();
        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
