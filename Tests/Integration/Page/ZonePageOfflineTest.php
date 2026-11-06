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

use Symfony\Component\Console\Tester\CommandTester;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Page\PublishPagesCommand;
use TheliaCMS\Search\PageSearch;
use TheliaCMS\Search\SearchTerms;
use TheliaCMS\Sitemap\SitemapCache;
use TheliaCMS\Sitemap\SitemapThemeHook;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * Twin of `ModelPageOfflineTest` for the header zone (`model = 2`).
 *
 * The zone is drawn by its own reader, in the header of the theme, and by
 * nothing else. Held visible and carrying a published version (a file can
 * bring both in), it is still no page of the site: no address, no search,
 * no sitemap, no list, no menu.
 */
final class ZonePageOfflineTest extends CmsIntegrationTestCase
{
    /** The full-text search reads committed rows only. */
    protected bool $useTransaction = false;

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->getService(SitemapCache::class)->invalidate();
    }

    public function testAZoneHoldingAPublishedVersionIsOfferedToNoVisitor(): void
    {
        $tag = $this->getService(PageTagWriter::class)->add('Étiquette de la zone');
        $page = $this->createPage('Bandeau cyanotype', html: '<h1>Bandeau cyanotype</h1><p>Un tirage cyanotype de démonstration.</p>');
        $page->setTagId($tag->getId())->save();

        $repository = $this->getService(PublishedPageRepository::class);

        self::assertNotNull($repository->find((int) $page->getId(), $this->locale()));
        self::assertSame(1, $this->searchTotal('cyanotype'));
        self::assertCount(1, $repository->withTag('Étiquette de la zone', $this->locale()));
        self::assertStringContainsString((string) $page->getRewrittenUrl($this->locale()), $this->sitemap());

        $this->makeZone($page);

        self::assertNull($repository->find((int) $page->getId(), $this->locale()), 'The page of the zone answers no visitor.');
        self::assertFalse($repository->isReachable((int) $page->getId(), $this->locale()), 'A menu or a breadcrumb does not link to the zone.');
        self::assertSame([], $repository->withTag('Étiquette de la zone', $this->locale()), 'A list of the pages of a tag leaves the zone out.');
        self::assertSame(0, $this->searchTotal('cyanotype'), 'The search of the site does not find the zone.');
        self::assertStringNotContainsString((string) $page->getRewrittenUrl($this->locale()), $this->sitemap(), 'The sitemap does not announce the zone.');
    }

    public function testTheZoneIsNotInTheListsOfTheBackOffice(): void
    {
        $page = $this->createPage('Liste sans zone', published: false);
        $before = $this->livePageCount();

        $this->makeZone($page);

        self::assertSame($before - 1, $this->livePageCount(), 'The count of the pages of the site (dashboard) leaves the zone out.');
    }

    public function testPublishingEverythingLeavesTheDraftOfTheZoneAlone(): void
    {
        $page = $this->createPage('Zone en brouillon', published: false);
        $listing = \sprintf('  #%d %s', $page->getId(), $this->locale());

        // A dry run: the same selection as the real one, and nothing written
        // in a database this test does not roll back.
        self::assertStringContainsString($listing, $this->dryRunOfPublishingEverything(), 'Without the flag, the draft is among the pages the command reaches.');

        $this->makeZone($page);

        self::assertStringNotContainsString($listing, $this->dryRunOfPublishingEverything(), '`thelia_cms:publish --all` does not reach the zone.');
    }

    public function testTheTntSearchIndexLeavesTheZoneOut(): void
    {
        // TntSearch is a soft dependency, absent from most hosts: the query is
        // read as the text it is, the one place the filter lives.
        $source = (string) file_get_contents(__DIR__.'/../../../Search/Tnt/CmsPageIndex.php');

        self::assertStringContainsString('AND p.model = 0', $source);
    }

    private function makeZone(CmsPage $page): void
    {
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET model = 2, visible = 1 WHERE id = %d', $page->getId()));
        CmsPageTableMap::clearInstancePool();
        $this->getService(SitemapCache::class)->invalidate();
    }

    private function dryRunOfPublishingEverything(): string
    {
        $tester = new CommandTester($this->getService(PublishPagesCommand::class));
        $tester->execute(['--all' => true, '--dry-run' => true, '--url' => 'https://example.test/']);

        return $tester->getDisplay();
    }

    private function livePageCount(): int
    {
        return $this->getService(CmsPageAdminRepository::class)->countLive();
    }

    private function sitemap(): string
    {
        return $this->getService(SitemapThemeHook::class)->render('sitemap.urls', ['context' => 'content', 'lang' => $this->locale()]);
    }

    private function searchTotal(string $words): int
    {
        return $this->getService(PageSearch::class)->find(SearchTerms::fromInput($words), $this->locale())['total'];
    }
}
