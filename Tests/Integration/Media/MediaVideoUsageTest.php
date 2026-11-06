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

use TheliaCMS\Media\Admin\MediaUsageFinder;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\Model\Map\LibraryImageTableMap;

/**
 * A video a page prints, from the address of its own route, is a video in
 * use: the sheet of the video names the page, and deleting it is refused as
 * it is for an image.
 */
final class MediaVideoUsageTest extends AdminScreenTestCase
{
    public function testAVideoAPagePrintsIsInUseAndTheNextOneIsNot(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $used = Mp4Videos::row($library, $this->locale());
        $unused = Mp4Videos::row($library, $this->locale());
        $page = $this->createPage('Page with a video');
        $this->printOn($page->getId(), \sprintf('<video preload="none" src="/video-library/%d.mp4"></video>', $used->getId()));

        $finder = $this->getService(MediaUsageFinder::class);
        $usages = $finder->pagesUsing((int) $used->getId());

        self::assertCount(1, $usages);
        self::assertSame((int) $page->getId(), (int) $usages[0]['page']->getId());
        self::assertSame([(int) $used->getId() => 1, (int) $unused->getId() => 0], $finder->countsFor([(int) $used->getId(), (int) $unused->getId()]));
    }

    public function testAnIdThatOnlyBeginsLikeTheOneOfAVideoIsNotThatVideo(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $video = Mp4Videos::row($library, $this->locale());
        $page = $this->createPage('Page with another video');
        $this->printOn($page->getId(), \sprintf('<video src="/video-library/%d0.mp4"></video>', $video->getId()));

        self::assertSame(0, $this->getService(MediaUsageFinder::class)->useCount((int) $video->getId()));
    }

    public function testAVideoAPagePrintsIsKeptWhenItsDeletionIsAsked(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $page = $this->createPage('Page with a video to keep');
        $this->printOn($page->getId(), \sprintf('<video src="/video-library/%d.mp4"></video>', $video->getId()));

        $this->deleteFromTheScreen((int) $video->getId());

        LibraryImageTableMap::clearInstancePool();
        self::assertNotNull(LibraryImageQuery::create()->findPk($video->getId()), 'The page still plays the video.');

        $unused = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $this->deleteFromTheScreen((int) $unused->getId());

        LibraryImageTableMap::clearInstancePool();
        self::assertNull(LibraryImageQuery::create()->findPk($unused->getId()), 'A video nobody uses is deleted.');
    }

    private function printOn(int $pageId, string $html): void
    {
        CmsPageContentQuery::create()
            ->filterByPageId($pageId)
            ->filterByLocale($this->locale())
            ->findOneOrCreate()
            ->setDraftHtml($html)
            ->save();
    }

    private function deleteFromTheScreen(int $id): void
    {
        $action = \sprintf('/admin/cms/media/%d/delete', $id);
        $token = $this->tokenOfTheForm($this->screen(\sprintf('/admin/cms/media/%d', $id)), $action);

        self::assertSame(302, $this->send('POST', $action, ['_token' => $token]));
    }
}
