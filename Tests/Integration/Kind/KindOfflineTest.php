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

namespace TheliaCMS\Tests\Integration\Kind;

use Symfony\Component\Console\Tester\CommandTester;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\PageParentCheck;
use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Page\PublishPagesCommand;
use TheliaCMS\Partial\Catalogue\PickablePages;
use TheliaCMS\Search\PageSearch;
use TheliaCMS\Search\SearchTerms;
use TheliaCMS\Sitemap\SitemapCache;
use TheliaCMS\Sitemap\SitemapThemeHook;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * Twin of `ZonePageOfflineTest` for the contents of a kind a module declares:
 * no address, not in the pages list, the search, the sitemap, the pickers of
 * pages or the command that publishes everything, whether the content was
 * made as such or was a page of the site first.
 */
final class KindOfflineTest extends CmsIntegrationTestCase
{
    use DeclaresTestKinds;

    /** The full-text search reads committed rows only. */
    protected bool $useTransaction = false;

    /** @var list<int> */
    private array $contentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareTestKinds();
    }

    protected function tearDown(): void
    {
        if ([] !== $this->contentIds) {
            $this->getPropelConnection()->exec(\sprintf('DELETE FROM cms_page WHERE id IN (%s)', implode(',', $this->contentIds)));
            $this->contentIds = [];
        }

        parent::tearDown();

        $this->getService(SitemapCache::class)->invalidate();
    }

    /**
     * Sabotage: drop `->setModel(ContentKinds::MODEL)` from
     * `KindContents::adopt()`: the page stays found, searched and announced
     * and the "after" assertions are red; the "before" ones show the search
     * and the sitemap do find the page. The deletion of the address, the
     * search line and the entries is not seen here, as those reads leave out
     * `model <> 0` anyway: `AdoptPageTest` proves the rows are gone.
     */
    public function testAPageTakenInAsAContentIsOfferedToNoVisitor(): void
    {
        $page = $this->createPage('Quadrillage des tailles', html: '<h1>Quadrillage des tailles</h1><p>Un quadrillage de mesures de démonstration.</p>');
        $id = (int) $page->getId();
        $repository = $this->getService(PublishedPageRepository::class);
        $live = $this->livePageCount();
        $address = (string) $page->getRewrittenUrl($this->locale());
        self::assertNotSame('', $address);

        self::assertNotNull($repository->find($id, $this->locale()));
        self::assertSame(1, $this->searchTotal('quadrillage'));
        self::assertStringContainsString($address, $this->sitemap());
        self::assertContains($id, array_map(static fn ($item): int => $item->id, $this->getService(PickablePages::class)->search('Quadrillage', $this->locale())));
        self::assertStringContainsString($this->listing($id), $this->dryRunOfPublishingEverything());

        $this->getService(KindContents::class)->adopt($page, 'test_guide');
        CmsPageTableMap::clearInstancePool();
        $this->getService(SitemapCache::class)->invalidate();

        self::assertNull($repository->find($id, $this->locale()), 'The page answers no visitor.');
        self::assertFalse($repository->isReachable($id, $this->locale()), 'A menu or a breadcrumb does not link to it.');
        self::assertSame(0, $this->searchTotal('quadrillage'), 'The search of the site does not find it.');
        self::assertStringNotContainsString($address, $this->sitemap(), 'The sitemap does not announce it.');
        self::assertSame($live - 1, $this->livePageCount(), 'The pages of the site are one less.');
        self::assertNotContains($id, array_map(static fn ($item): int => $item->id, $this->getService(PickablePages::class)->search('Quadrillage', $this->locale())), 'No page picker offers it.');
        self::assertNotNull($this->getService(PageParentCheck::class)->refusal(null, $id, $this->locale()), 'It cannot be chosen as the parent of a page.');
        self::assertStringNotContainsString($this->listing($id), $this->dryRunOfPublishingEverything(), '`thelia_cms:publish --all` does not reach it.');
    }

    /**
     * Sabotage: make `KindContents::create()` set `model` 0: the content is
     * counted, offered by the picker and listed by the command, and the
     * assertions of the first half are red. The second half does that by hand
     * and shows each of them can see the content when it is a page.
     */
    public function testAContentMadeAsSuchIsInNoListOfPages(): void
    {
        $live = $this->livePageCount();
        $content = $this->kindContent('test_guide', 'Pochoir des blousons', html: '<h2>Pochoir des blousons</h2><p>Un pochoir de démonstration.</p>');
        $id = (int) $content->getId();
        $this->contentIds[] = $id;

        self::assertSame($live, $this->livePageCount(), 'The pages of the site are as many as before.');
        self::assertSame(0, $this->searchTotal('pochoir'), 'Published, it is in no search.');
        self::assertSame([], $this->getService(PickablePages::class)->search('Pochoir', $this->locale()), 'No page picker offers it.');
        self::assertNotNull($this->getService(PageParentCheck::class)->refusal(null, $id, $this->locale()), 'It is not a parent.');
        self::assertNull($this->getService(PublishedPageRepository::class)->find($id, $this->locale()));
        self::assertStringNotContainsString($this->listing($id), $this->dryRunOfPublishingEverything());

        // The same row as a page: every one of these now sees it.
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET model = 0, content_kind = NULL, visible = 1 WHERE id = %d', $id));
        CmsPageTableMap::clearInstancePool();

        self::assertSame($live + 1, $this->livePageCount());
        self::assertCount(1, $this->getService(PickablePages::class)->search('Pochoir', $this->locale()));
        self::assertStringContainsString($this->listing($id), $this->dryRunOfPublishingEverything());
    }

    private function listing(int $id): string
    {
        return \sprintf('  #%d %s', $id, $this->locale());
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
