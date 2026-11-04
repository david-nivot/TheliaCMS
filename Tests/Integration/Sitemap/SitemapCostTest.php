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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageI18nTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Sitemap\SitemapCache;
use TheliaCMS\Sitemap\SitemapEntry;
use TheliaCMS\Sitemap\SitemapThemeHook;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaCMS\TheliaCMS;

/**
 * What the sitemap costs, and what keeps it right while it is kept.
 *
 * A crawler asks for it on every visit, so the answer is read in a handful of
 * statements whatever the number of pages, and kept. Keeping it is only
 * acceptable if every change a visitor could see empties it, which is what the
 * second half of the cases pins, one change each, through the writer the
 * screens use.
 */
final class SitemapCostTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    private const MAPS = [
        CmsPageTableMap::class,
        CmsPageI18nTableMap::class,
        CmsPageContentTableMap::class,
    ];

    /** @var array{0: string, 1: string} the two languages the pages are published in */
    private array $languages;

    protected function setUp(): void
    {
        parent::setUp();

        $active = $this->activeLocales();

        if (\count($active) < 2) {
            self::markTestSkipped('this host has fewer than two active languages: no page can be published in two of them');
        }

        $this->languages = [$active[0], $active[1]];
        $this->emptyTheCache();
    }

    protected function tearDown(): void
    {
        // The cache lives outside the transaction that takes the rows back.
        $this->emptyTheCache();

        parent::tearDown();
    }

    public function testReadingTheSitemapCostsTheSameWhateverTheNumberOfPages(): void
    {
        $this->pages(3);
        // What is read once for the whole process (a setting, a language) is not the cost of a page.
        $this->coldCost();
        $few = $this->coldCost();

        $this->pages(9);
        $many = $this->coldCost();

        self::assertSame(24, substr_count($this->render(), '<url>'), 'twelve pages in two languages: twenty-four entries');
        self::assertSame($few, $many, \sprintf('3 pages cost %d statements and 12 cost %d: something is asked once per page', $few, $many));
    }

    public function testAnAnswerAlreadyKeptCostsNothing(): void
    {
        $this->pages(3);
        $this->render();
        $this->forgetWhatWasRead(self::MAPS);

        self::assertSame(0, $this->statementsFor(fn (): string => $this->render()));
    }

    public function testTheSitemapIsTheSameAsTheOneBuiltEntryByEntry(): void
    {
        $pages = $this->pages(3);

        self::assertSame($this->expectedXml($pages), $this->render());
        self::assertSame($this->expectedXml($pages), $this->render(), 'the answer kept is the one computed');
    }

    public function testPublishingAPageAddsItToTheKeptSitemap(): void
    {
        $this->createPage('Page déjà en ligne', $this->languages[0]);
        $this->render($this->languages[0]);

        $this->createPage('Page mise en ligne ensuite', $this->languages[0]);

        self::assertStringContainsString('page-mise-en-ligne-ensuite', $this->render($this->languages[0]));
    }

    public function testPublishingAgainChangesTheDateOfTheKeptSitemap(): void
    {
        $page = $this->createPage('Page republiée', $this->languages[0]);
        // To the second: MySQL rounds a fraction of a second where MariaDB cuts it.
        $yesterday = new \DateTimeImmutable(date('Y-m-d H:i:s', strtotime('-1 day')));
        CmsPageContentQuery::create()->filterByPageId($page->getId())->update(['PublishedAt' => $yesterday]);
        $this->emptyTheCache();
        self::assertStringContainsString('<lastmod>'.$yesterday->format('c').'</lastmod>', $this->render($this->languages[0]));

        $this->writer()->publish($page, $this->languages[0]);

        self::assertStringNotContainsString($yesterday->format('c'), $this->render($this->languages[0]));
    }

    public function testUnpublishingAPageTakesItOutOfTheKeptSitemap(): void
    {
        $page = $this->createPage('Page dépubliée', $this->languages[0]);
        self::assertStringContainsString('page-depubliee', $this->render($this->languages[0]));

        $this->writer()->unpublish($page, $this->languages[0]);

        self::assertStringNotContainsString('page-depubliee', $this->render($this->languages[0]));
    }

    public function testHidingAPageTakesItOutOfTheKeptSitemap(): void
    {
        $page = $this->createPage('Page masquée', $this->languages[0]);
        self::assertStringContainsString('page-masquee', $this->render($this->languages[0]));

        $this->writer()->toggleVisibility($page);

        self::assertStringNotContainsString('page-masquee', $this->render($this->languages[0]));
    }

    public function testBinningAPageTakesItOutOfTheKeptSitemap(): void
    {
        $page = $this->createPage('Page à la corbeille', $this->languages[0]);
        self::assertStringContainsString('page-a-la-corbeille', $this->render($this->languages[0]));

        $this->writer()->moveToTrash($page);

        self::assertStringNotContainsString('page-a-la-corbeille', $this->render($this->languages[0]));
    }

    public function testMarkingAPageNoindexInItsSettingsTakesItOutOfTheKeptSitemap(): void
    {
        $page = $this->createPage('Page à ne pas indexer', $this->languages[0]);
        self::assertStringContainsString('page-a-ne-pas-indexer', $this->render($this->languages[0]));

        $page->setLocale($this->languages[0])->setNoindex(1);
        $this->writer()->saveDraft($page, $this->languages[0], new PageDraft(title: 'Page à ne pas indexer'));

        self::assertStringNotContainsString('page-a-ne-pas-indexer', $this->render($this->languages[0]));
    }

    public function testChangingTheAddressOfAPageChangesTheKeptSitemap(): void
    {
        $page = $this->createPage('Page qui déménage', $this->languages[0]);
        self::assertStringContainsString('page-qui-demenage', $this->render($this->languages[0]));

        $this->writer()->saveDraft($page, $this->languages[0], new PageDraft(title: 'Page qui déménage', slug: 'nouvelle-adresse-du-sitemap'));

        $xml = $this->render($this->languages[0]);
        self::assertStringContainsString('nouvelle-adresse-du-sitemap', $xml);
        self::assertStringNotContainsString('page-qui-demenage', $xml);
    }

    public function testAScheduledPublicationAndAScheduledRemovalShowWithoutAnyWrite(): void
    {
        $leaving = $this->createPage('Page qui part', $this->languages[0]);
        $arriving = $this->createPage('Page qui arrive', $this->languages[0]);
        $soon = new \DateTimeImmutable('+2 seconds');
        $leaving->setUnpublishAt($soon)->save();
        $arriving->setPublishAt($soon)->save();
        $this->emptyTheCache();

        $before = $this->render($this->languages[0]);
        self::assertStringContainsString('page-qui-part', $before);
        self::assertStringNotContainsString('page-qui-arrive', $before);

        // Nothing is written and nothing is invalidated: the date alone does it.
        sleep(3);

        $after = $this->render($this->languages[0]);
        self::assertStringNotContainsString('page-qui-part', $after);
        self::assertStringContainsString('page-qui-arrive', $after);
    }

    public function testEachLanguageAndEachHostHasItsOwnEntry(): void
    {
        $this->pages(2);
        $requests = $this->getService(RequestStack::class);

        // The host is the one of the main request: put the others aside.
        $aside = [];

        while (null !== ($former = $requests->pop())) {
            array_unshift($aside, $former);
        }

        try {
            foreach (['one.test', 'two.test'] as $host) {
                $requests->push(Request::create('http://'.$host.'/'));

                foreach (['', $this->languages[0], $this->languages[1]] as $lang) {
                    $first = $this->statementsFor(fn (): string => $this->render($lang));
                    $second = $this->statementsFor(fn (): string => $this->render($lang));

                    self::assertGreaterThan(0, $first, \sprintf('"%s" on %s was answered from the entry of another', $lang, $host));
                    self::assertSame(0, $second, \sprintf('"%s" on %s was not kept', $lang, $host));
                }

                $requests->pop();
            }
        } finally {
            while (null !== $requests->pop()) {
                // Whatever the case left.
            }

            foreach ($aside as $former) {
                $requests->push($former);
            }
        }
    }

    public function testALanguageThatIsNotActiveIsNeverKept(): void
    {
        $this->pages(1);

        foreach (['zz', 'zz_ZZ'] as $lang) {
            self::assertSame('', $this->render($lang));
            self::assertGreaterThan(0, $this->statementsFor(fn (): string => $this->render($lang)), \sprintf('"%s" got an entry of its own', $lang));
        }

        // Not even shaped like a language: refused before anything is read or kept.
        foreach (['../../x', str_repeat('a', 300)] as $lang) {
            self::assertSame(0, $this->statementsFor(fn (): string => $this->render($lang)));
            self::assertSame('', $this->render($lang));
        }
    }

    private function emptyTheCache(): void
    {
        $this->getService(SitemapCache::class)->invalidate();
    }

    private function coldCost(): int
    {
        $this->emptyTheCache();
        $this->forgetWhatWasRead(self::MAPS);

        return $this->statementsFor(fn (): string => $this->render());
    }

    private function render(string $lang = ''): string
    {
        return $this->getService(SitemapThemeHook::class)->render('sitemap.urls', ['context' => 'content', 'lang' => $lang]);
    }

    /**
     * @return list<string> the active languages, in the order the shop lists them
     */
    private function activeLocales(): array
    {
        $locales = [];

        foreach (LangQuery::create()->filterByActive(true)->find() as $lang) {
            $locales[] = (string) $lang->getLocale();
        }

        return $locales;
    }

    /**
     * Pages published in both of the languages, one after the other.
     *
     * @return list<CmsPage>
     */
    private function pages(int $count): array
    {
        static $run = 0;
        $pages = [];

        for ($index = 1; $index <= $count; ++$index) {
            $label = \sprintf('Page du sitemap %d-%d', ++$run, $index);
            $page = $this->createPage($label, $this->languages[0]);

            $title = $label.' (second language)';
            $this->writer()->saveDraft($page, $this->languages[1], new PageDraft(title: $title));
            $this->writer()->saveContent($page, $this->languages[1], Document::richText('<h2>'.$title.'</h2>'));
            $this->writer()->publish($page, $this->languages[1]);

            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * What the sitemap said before it read in one go: an entry for each page and
     * each active language it is published in, each carrying every language of the page.
     *
     * @param list<CmsPage> $pages
     */
    private function expectedXml(array $pages): string
    {
        $entries = [];

        foreach ($pages as $page) {
            $published = [];

            foreach ([$this->languages[0], $this->languages[1]] as $locale) {
                $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($locale)->findOne();
                self::assertNotNull($content?->getPublishedAt());
                $published[$locale] = [$this->addressOf((int) $page->getId(), $locale), $content->getPublishedAt()];
            }

            $alternates = array_map(static fn (array $entry): string => $entry[0], $published);
            // The languages of a page came in the order of the index of its rows.
            ksort($alternates);

            foreach ($this->activeLocales() as $locale) {
                if (isset($published[$locale])) {
                    $entries[] = SitemapEntry::toXml($published[$locale][0], $published[$locale][1], $alternates);
                }
            }
        }

        return implode("\n", $entries);
    }

    private function addressOf(int $pageId, string $locale): string
    {
        return URL::getInstance()->retrieve(TheliaCMS::PAGE_VIEW, $pageId, $locale)->toString();
    }
}
