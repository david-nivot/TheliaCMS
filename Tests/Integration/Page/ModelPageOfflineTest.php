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

use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Search\PageSearch;
use TheliaCMS\Search\SearchTerms;
use TheliaCMS\Sitemap\SitemapCache;
use TheliaCMS\Sitemap\SitemapThemeHook;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The model of a page type is a reference new pages start from, never a page
 * of the site.
 *
 * The back office never publishes it, but nothing in the row says so: its
 * visibility is a setting of its edit screen, and a published version can
 * come in from a file or a script. Every way the front reads pages leaves it
 * out on its own account, so a model holding a published version, set
 * visible, is still offered to no visitor.
 */
final class ModelPageOfflineTest extends CmsIntegrationTestCase
{
    /** The full-text search reads committed rows only. */
    protected bool $useTransaction = false;

    protected function tearDown(): void
    {
        parent::tearDown();

        // The rows are committed here, and the sitemap kept with them.
        $this->getService(SitemapCache::class)->invalidate();
    }

    public function testAModelHoldingAPublishedVersionIsOfferedToNoVisitor(): void
    {
        $tag = $this->getService(PageTagWriter::class)->add('Modèle du test hors ligne');
        $page = $this->createPage('Gabarit lithographie', html: '<h1>Gabarit lithographie</h1><p>Une chromolithographie de démonstration.</p>');
        $page->setTagId($tag->getId())->save();

        $repository = $this->getService(PublishedPageRepository::class);

        // The same page, online, is found everywhere: what follows is the
        // model flag at work and nothing else.
        self::assertNotNull($repository->find((int) $page->getId(), $this->locale()));
        self::assertSame(1, $this->searchTotal('chromolithographie'));
        self::assertCount(1, $repository->withTag('Modèle du test hors ligne', $this->locale()));
        self::assertStringContainsString((string) $page->getRewrittenUrl($this->locale()), $this->sitemap());

        $this->makeModel($page);

        self::assertNull($repository->find((int) $page->getId(), $this->locale()), 'The page of a model answers no visitor.');
        self::assertFalse($repository->isReachable((int) $page->getId(), $this->locale()), 'A menu or a breadcrumb does not link to a model.');
        self::assertSame([], $repository->withTag('Modèle du test hors ligne', $this->locale()), 'A list of the pages of a tag leaves the model out.');
        self::assertSame(0, $this->searchTotal('chromolithographie'), 'The search of the site does not find the model.');
        self::assertStringNotContainsString(
            (string) $page->getRewrittenUrl($this->locale()),
            $this->sitemap(),
            'The sitemap does not announce the model.',
        );
    }

    private function makeModel(CmsPage $page): void
    {
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET model = 1, visible = 1 WHERE id = %d', $page->getId()));
        CmsPageTableMap::clearInstancePool();
        // Written without the writer: nothing else empties the sitemap kept.
        $this->getService(SitemapCache::class)->invalidate();
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
