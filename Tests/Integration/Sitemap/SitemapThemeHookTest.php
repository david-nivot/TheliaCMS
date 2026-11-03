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

namespace TheliaCMS\Tests\Integration\Sitemap;

use Thelia\Model\LangQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Sitemap\SitemapCache;
use TheliaCMS\Sitemap\SitemapThemeHook;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * What the module adds to the sitemap of the theme. The rules it has to hold —
 * only reachable pages, `lastmod` from the publication date — are decisions
 * about what a crawler is told, and they are only observable against real rows.
 */
final class SitemapThemeHookTest extends CmsIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The cache lives outside the transaction that takes the rows back, and
        // these cases write rows directly: nothing else would empty it.
        $this->emptyTheCache();
    }

    protected function tearDown(): void
    {
        $this->emptyTheCache();

        parent::tearDown();
    }

    public function testAPublishedPageIsListedWithItsPublicationDate(): void
    {
        $page = $this->createPage('Atelier de gravure');
        $publishedAt = $this->publishedAt((int) $page->getId());

        $xml = $this->render();

        self::assertStringContainsString('<loc>', $xml);
        self::assertStringContainsString('atelier-de-gravure', $xml);
        self::assertStringContainsString('<lastmod>'.$publishedAt->format('c').'</lastmod>', $xml);
    }

    public function testADraftIsNotListed(): void
    {
        $this->createPage('Page en préparation', published: false);

        self::assertStringNotContainsString('page-en-preparation', $this->render());
    }

    public function testAPageMarkedNoindexIsNotListed(): void
    {
        $page = $this->createPage('Page discrète');
        $page->setLocale($this->locale())->setNoindex(1)->save();
        $this->emptyTheCache();

        self::assertStringNotContainsString('page-discrete', $this->render());
    }

    public function testAHiddenPageIsNotListed(): void
    {
        $page = $this->createPage('Page hors ligne');
        $page->setVisible(0)->save();
        $this->emptyTheCache();

        self::assertStringNotContainsString('page-hors-ligne', $this->render());
    }

    public function testAPageWithADateButNoHtmlToServeIsNotListed(): void
    {
        $page = $this->createPage('Page sans rendu');
        $this->createPage('Page avec rendu');
        // An import that sets the date alone: the page would answer 404.
        CmsPageContentQuery::create()->filterByPageId($page->getId())->update(['PublishedHtml' => null]);
        $this->emptyTheCache();

        $xml = $this->render();

        self::assertStringNotContainsString('page-sans-rendu', $xml);
        self::assertStringContainsString('page-avec-rendu', $xml);
    }

    public function testAnInactiveLanguageIsNotAnAlternate(): void
    {
        $others = $this->otherActiveLocales();
        $other = $others[0] ?? null;

        if (null === $other) {
            self::markTestSkipped('this host has a single active language: nothing to deactivate');
        }

        $page = $this->createPage('Page en deux langues');
        $this->writer()->saveDraft($page, $other, new PageDraft(title: 'Page in two languages'));
        $this->writer()->saveContent($page, $other, Document::richText('<h1>Page in two languages</h1>'));
        $this->writer()->publish($page, $other);

        self::assertStringContainsString('hreflang="'.str_replace('_', '-', $other).'"', $this->render(), 'while the language is active, it is an alternate');

        LangQuery::create()->filterByLocale($other)->update(['Active' => 0]);
        $this->emptyTheCache();
        $xml = $this->render();

        self::assertStringContainsString('page-en-deux-langues', $xml);
        self::assertStringNotContainsString('hreflang="'.str_replace('_', '-', $other).'"', $xml);
        self::assertStringNotContainsString('page-in-two-languages', $xml);
    }

    public function testTheHookStaysOutOfTheCatalogueSections(): void
    {
        $this->createPage('Atelier de moulage');

        self::assertSame('', $this->render(context: 'product'));
    }

    /**
     * @return list<string> the active languages other than the one the module answers in
     */
    private function otherActiveLocales(): array
    {
        $others = [];

        foreach (LangQuery::create()->filterByActive(true)->find() as $lang) {
            if ($lang->getLocale() !== $this->locale()) {
                $others[] = (string) $lang->getLocale();
            }
        }

        return $others;
    }

    private function emptyTheCache(): void
    {
        $this->getService(SitemapCache::class)->invalidate();
    }

    private function render(string $context = 'content'): string
    {
        return $this->getService(SitemapThemeHook::class)
            ->render('sitemap.urls', ['context' => $context, 'lang' => $this->locale()]);
    }

    private function publishedAt(int $pageId): \DateTimeInterface
    {
        $content = CmsPageContentQuery::create()
            ->filterByPageId($pageId)
            ->filterByLocale($this->locale())
            ->findOne();

        self::assertNotNull($content);
        $publishedAt = $content->getPublishedAt();
        self::assertNotNull($publishedAt);

        return $publishedAt;
    }
}
