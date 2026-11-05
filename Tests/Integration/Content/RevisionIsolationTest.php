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

namespace TheliaCMS\Tests\Integration\Content;

use Thelia\Model\Content;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsItemRevisionQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * A revision is put back only on the content it was kept for: the restore
 * looks the posted id up within the page, or within the item type, the id and
 * the language of the screen. A page and a content that share an id, which
 * happens all the time since their ids come from two counters, must never
 * restore each other's drafts.
 */
final class RevisionIsolationTest extends AdminScreenTestCase
{
    private const string OTHER_LOCALE = 'fr_FR';

    public function testTheDraftOfAPageIsNotPutBackOnAContentOfTheSameId(): void
    {
        [$page, $content] = $this->pageAndContentOfTheSameId();
        $foreign = $this->keepPageDraft($page, '<h2>Page draft</h2>');
        $this->writeItemDraft($content, '<h2>Item draft</h2>');

        $this->restoreOnItem($content, $foreign);

        self::assertStringContainsString('Item draft', $this->itemDraft($content));
        self::assertSame(0, $this->keptItemDrafts($content), 'The draft of the item was not kept to be replaced: nothing was restored.');
    }

    public function testTheDraftOfAnItemIsNotPutBackOnAPageOfTheSameId(): void
    {
        [$page, $content] = $this->pageAndContentOfTheSameId();
        $foreign = $this->keepItemDraft($content, '<h2>Item draft</h2>');
        $this->writePageDraft($page, '<h2>Page draft</h2>');

        $this->restoreOnPage($page, $foreign);

        self::assertStringContainsString('Page draft', $this->pageDraft($page));
        self::assertSame(0, $this->keptPageDrafts($page));
    }

    public function testTheDraftOfAnotherPageIsNotPutBack(): void
    {
        $page = $this->createPage('Restored on', published: false);
        $other = $this->createPage('Restored from', published: false);
        $foreign = $this->keepPageDraft($other, '<h2>Another page</h2>');
        $this->writePageDraft($page, '<h2>Page draft</h2>');

        $this->restoreOnPage($page, $foreign);

        self::assertStringContainsString('Page draft', $this->pageDraft($page));
        self::assertSame(0, $this->keptPageDrafts($page));
    }

    public function testTheDraftOfAnotherItemIsNotPutBack(): void
    {
        $content = $this->content();
        $other = $this->content();
        $foreign = $this->keepItemDraft($other, '<h2>Another item</h2>');
        $this->writeItemDraft($content, '<h2>Item draft</h2>');

        $this->restoreOnItem($content, $foreign);

        self::assertStringContainsString('Item draft', $this->itemDraft($content));
        self::assertSame(0, $this->keptItemDrafts($content));
    }

    public function testTheDraftOfAnotherLanguageIsNotPutBack(): void
    {
        [$page, $content] = $this->pageAndContentOfTheSameId();
        $foreignPage = $this->keepPageDraft($page, '<h2>Écrit en français</h2>', self::OTHER_LOCALE);
        $foreignItem = $this->keepItemDraft($content, '<h2>Écrit en français</h2>', self::OTHER_LOCALE);
        $this->writePageDraft($page, '<h2>Page draft</h2>');
        $this->writeItemDraft($content, '<h2>Item draft</h2>');

        $this->restoreOnPage($page, $foreignPage);
        $this->restoreOnItem($content, $foreignItem);

        self::assertStringContainsString('Page draft', $this->pageDraft($page));
        self::assertStringContainsString('Item draft', $this->itemDraft($content));
        self::assertSame(0, $this->keptPageDrafts($page));
        self::assertSame(0, $this->keptItemDrafts($content));
    }

    public function testAPublicationIsNotPutBackAsADraft(): void
    {
        [$page, $content] = $this->pageAndContentOfTheSameId();
        $this->writePageDraft($page, '<h2>Published page</h2>');
        $this->writeItemDraft($content, '<h2>Published item</h2>');
        $this->getService(CmsPageWriter::class)->publish($page, $this->locale());
        $this->getService(ContentWriter::class)->publish($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale()));
        $this->writePageDraft($page, '<h2>Page draft</h2>');
        $this->writeItemDraft($content, '<h2>Item draft</h2>');

        $publicationOfThePage = (int) CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLabel(null)->findOne()?->getId();
        $publicationOfTheItem = (int) CmsItemRevisionQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLabel(null)->findOne()?->getId();
        self::assertGreaterThan(0, $publicationOfThePage);
        self::assertGreaterThan(0, $publicationOfTheItem);

        $this->restoreOnPage($page, $publicationOfThePage);
        $this->restoreOnItem($content, $publicationOfTheItem);

        self::assertStringContainsString('Page draft', $this->pageDraft($page));
        self::assertStringContainsString('Item draft', $this->itemDraft($content));
        self::assertSame(0, $this->keptPageDrafts($page));
        self::assertSame(0, $this->keptItemDrafts($content));
    }

    public function testTheDraftOfTheSameContentIsPutBack(): void
    {
        [$page, $content] = $this->pageAndContentOfTheSameId();
        $keptOfThePage = $this->keepPageDraft($page, '<h2>Kept page</h2>');
        $keptOfTheItem = $this->keepItemDraft($content, '<h2>Kept item</h2>');
        $this->writePageDraft($page, '<h2>Page draft</h2>');
        $this->writeItemDraft($content, '<h2>Item draft</h2>');

        $this->restoreOnPage($page, $keptOfThePage);
        $this->restoreOnItem($content, $keptOfTheItem);

        self::assertStringContainsString('Kept page', $this->pageDraft($page), 'The control: the same requests, on their own content, do restore.');
        self::assertStringContainsString('Kept item', $this->itemDraft($content));
    }

    /**
     * A page and a content carrying the same id.
     *
     * @return array{CmsPage, Content}
     */
    private function pageAndContentOfTheSameId(): array
    {
        // Contents are created until one has an id no page holds: ids only grow,
        // so the loop ends once they pass the largest id of cms_page, whatever
        // the counters of the database were when the test started.
        $largestPage = (int) $this->getPropelConnection()->query('SELECT COALESCE(MAX(id), 0) FROM cms_page')->fetchColumn();
        $content = null;

        for ($attempt = 0; $attempt <= $largestPage + 1; ++$attempt) {
            $candidate = $this->content();

            if (0 === CmsPageQuery::create()->filterById((int) $candidate->getId())->count()) {
                $content = $candidate;

                break;
            }
        }

        self::assertNotNull($content, 'A content id that no page holds was found.');
        $id = (int) $content->getId();

        // Propel refuses an explicit value for an auto-increment key.
        $this->getPropelConnection()->exec(\sprintf('INSERT INTO cms_page (id, created_at, updated_at) VALUES (%d, NOW(), NOW())', $id));
        $page = CmsPageQuery::create()->filterById($id)->findOne() ?? self::fail('The page was not inserted.');
        $this->getService(CmsPageWriter::class)->saveDraft($page, $this->locale(), new PageDraft(title: 'Same id'));

        self::assertSame($id, (int) $page->getId());

        return [$page, $content];
    }

    private function content(): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return $factory->content($factory->folder(), ['title' => 'Isolation', 'locale' => $this->locale()]);
    }

    private function writePageDraft(CmsPage $page, string $html, ?string $locale = null): void
    {
        $this->getService(CmsPageWriter::class)->saveContent($page, $locale ?? $this->locale(), Document::richText($html));
    }

    private function writeItemDraft(Content $content, string $html, ?string $locale = null): void
    {
        $this->getService(ContentWriter::class)->saveContent($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $locale ?? $this->locale()), Document::richText($html));
    }

    private function keepPageDraft(CmsPage $page, string $html, ?string $locale = null): int
    {
        $locale ??= $this->locale();
        $writer = $this->getService(CmsPageWriter::class);
        $this->writePageDraft($page, $html, $locale);
        $writer->keepDraft($page, $locale);

        return (int) $writer->previousDrafts($page, $locale)[0]['id'];
    }

    private function keepItemDraft(Content $content, string $html, ?string $locale = null): int
    {
        $locale ??= $this->locale();
        $writer = $this->getService(ContentWriter::class);
        $this->writeItemDraft($content, $html, $locale);
        $writer->keepDraft($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $locale));

        return (int) $writer->previousDrafts($this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $locale))[0]['id'];
    }

    private function restoreOnPage(CmsPage $page, int $revision): void
    {
        $base = \sprintf('/admin/cms/pages/%d/content', $page->getId());

        $this->restore($base, $revision);
    }

    private function restoreOnItem(Content $content, int $revision): void
    {
        $base = \sprintf('/admin/cms/catalogue/content/%d/content', $content->getId());

        $this->restore($base, $revision);
    }

    private function restore(string $base, int $revision): void
    {
        $token = $this->tokenOfTheScripts($this->screen($base));

        self::assertSame(302, $this->send('POST', $base.'/restore', ['_token' => $token, 'revision' => $revision]));
        self::assertSame(1, $this->screen($base)->filter('[data-testid="cms-builder-flash-danger"], [data-testid="cms-builder-flash-success"]')->count());
    }

    private function pageDraft(CmsPage $page): string
    {
        return (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne()?->getDraftHtml();
    }

    private function itemDraft(Content $content): string
    {
        return (string) CmsItemContentQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($this->locale())->findOne()?->getDraftHtml();
    }

    private function keptPageDrafts(CmsPage $page): int
    {
        return CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->filterByLabel('draft')->count();
    }

    private function keptItemDrafts(Content $content): int
    {
        return CmsItemRevisionQuery::create()->filterByItemType('content')->filterByItemId($content->getId())->filterByLocale($this->locale())->filterByLabel('draft')->count();
    }
}
