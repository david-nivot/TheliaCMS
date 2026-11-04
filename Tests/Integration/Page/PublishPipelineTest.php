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

use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Page\Admin\EmptyPageContentException;
use TheliaCMS\Page\Admin\HeadingStructureException;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * Publishing writes to four tables and has to leave the four of them agreeing
 * with each other: the snapshot a visitor is served, the text the search page
 * looks through, the revision to fall back on, and the address the page answers
 * at.
 */
final class PublishPipelineTest extends CmsIntegrationTestCase
{
    public function testPublishingWritesTheSnapshotTheSearchTextAndARevision(): void
    {
        $page = $this->createPage('Conseil et accompagnement', html: '<h2>Conseil</h2><p>Un accompagnement sur mesure.</p>');
        $id = (int) $page->getId();

        $content = CmsPageContentQuery::create()->filterByPageId($id)->filterByLocale($this->locale())->findOne();

        self::assertNotNull($content);
        self::assertNotNull($content->getPublishedAt());
        self::assertStringContainsString('Un accompagnement sur mesure.', (string) $content->getPublishedHtml());

        $index = CmsPageSearchQuery::create()->filterByPageId($id)->filterByLocale($this->locale())->findOne();

        self::assertNotNull($index, 'A published page the search page cannot find.');
        self::assertStringContainsString('accompagnement', (string) $index->getContent());

        self::assertSame(
            1,
            CmsPageRevisionQuery::create()->filterByPageId($id)->filterByLocale($this->locale())->count(),
            'Publishing takes a snapshot to fall back on.',
        );

        self::assertSame(
            1,
            RewritingUrlQuery::create()->filterByView('cmspage')->filterByViewId($id)->filterByViewLocale($this->locale())->count(),
        );
    }

    public function testPublishingKeepsTheDocumentNextToItsRendering(): void
    {
        $page = $this->createPage('Document publié', html: '<h2>Titre</h2><p>Texte</p>');

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

        self::assertNotNull($content);
        self::assertSame($content->getDraftDocument(), $content->getPublishedDocument(), 'What went online is the draft as it stood.');

        $document = Document::fromJson($content->getPublishedDocument());

        self::assertNotNull($document);
        self::assertSame(RichTextSection::NAME, $document->sections[0]->component);
        self::assertStringContainsString(\sprintf('<div class="cms-page-content"><div data-cms-section="content" class="%s"><h2>Titre</h2><p>Texte</p></div></div>', $this->richTextClass()), (string) $content->getPublishedHtml());

        $revision = CmsPageRevisionQuery::create()->filterByPageId($page->getId())->findOne();

        self::assertSame($content->getPublishedDocument(), $revision?->getDocument(), 'The revision holds the document, not only its rendering.');
    }

    public function testUnpublishingDropsTheSnapshotAndKeepsTheDraft(): void
    {
        $page = $this->createPage('Page retirée');

        $this->writer()->unpublish($page, $this->locale());

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

        self::assertNotNull($content);
        self::assertNull($content->getPublishedAt());
        self::assertNull($content->getPublishedHtml());
        self::assertNull($content->getPublishedDocument());
        self::assertNotNull($content->getDraftDocument(), 'The draft is what the page is published again from.');
    }

    public function testADocumentNamingAComponentTheCatalogueLacksIsRefused(): void
    {
        $page = $this->pageWithoutContent('Page cassée');

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('"Nope:Missing", which is not a component of the catalogue');

        $this->writer()->saveContent($page, $this->locale(), new Document([new Section('a', 'Nope:Missing', [])]));
    }

    /**
     * The check used to live in the editor alone, so a page published from
     * the settings screen or from the command skipped it.
     */
    public function testABrokenHeadingStructureKeepsThePageOfflineWhenTheSiteAsksForIt(): void
    {
        $previous = TheliaCMS::getConfigValue(CmsSettings::HEADING_CHECK_MODE, 'warn');
        TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, 'block');

        try {
            $page = $this->pageWithoutContent('Titres cassés');
            $this->writer()->saveContent($page, $this->locale(), Document::richText('<h2>Un</h2><h4>Deux</h4><h6>Saut</h6>'));

            try {
                $this->writer()->publish($page, $this->locale());
                self::fail('The page should have been refused.');
            } catch (HeadingStructureException $exception) {
                self::assertCount(2, $exception->problems, 'Two jumps: from 2 to 4, and from 4 to 6.');
            }

            $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();

            self::assertNotNull($content);
            self::assertNull($content->getPublishedAt());

            TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, 'warn');
            $this->writer()->publish($page, $this->locale());
            $content->reload();

            self::assertNotNull($content->getPublishedAt(), 'In warn mode the page goes online and its author is told.');
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, (string) $previous);
        }
    }

    public function testAPageWithNothingOnItIsNotPublished(): void
    {
        $page = $this->pageWithoutContent('Page en chantier');

        $this->expectException(EmptyPageContentException::class);

        try {
            $this->writer()->publish($page, $this->locale());
        } finally {
            $content = CmsPageContentQuery::create()
                ->filterByPageId($page->getId())
                ->filterByLocale($this->locale())
                ->findOne();

            // Were the date written without the HTML, the back office would
            // read "published" and the visitor would get a 404.
            self::assertNull($content?->getPublishedAt());
            self::assertNull($content?->getPublishedHtml());
        }
    }

    public function testRefusingToPublishLeavesNoTraceBehind(): void
    {
        $page = $this->pageWithoutContent('Page en chantier');
        $id = (int) $page->getId();

        try {
            $this->writer()->publish($page, $this->locale());
        } catch (EmptyPageContentException) {
            // The point of the test is what the rolled back transaction left.
        }

        self::assertSame(0, CmsPageRevisionQuery::create()->filterByPageId($id)->count());
        self::assertSame(0, CmsPageSearchQuery::create()->filterByPageId($id)->count());
    }

    public function testAPageHoldingOnlyABlankParagraphIsNotPublished(): void
    {
        $page = $this->createPage('Page vide', html: '<div class="cms-page-content"><p>&nbsp;</p></div>', published: false);

        $this->expectException(EmptyPageContentException::class);

        $this->writer()->publish($page, $this->locale());
    }

    /**
     * A page created and saved, whose editor was never opened: the row in
     * cms_page_content exists, with no draft in it.
     */
    private function pageWithoutContent(string $title): CmsPage
    {
        $page = new CmsPage();
        $page->setParent(0)
            ->setPosition(0)
            ->setVisible(1)
            ->setPageType('default');

        $this->writer()->saveDraft($page, $this->locale(), new PageDraft(title: $title));

        return $page;
    }
}
