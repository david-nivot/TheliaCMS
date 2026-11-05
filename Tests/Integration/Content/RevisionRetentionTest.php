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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Content;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Model\CmsItemRevisionQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The versions kept of a page and of a catalogue item: the five latest drafts
 * a generation replaced, the twenty latest publications, counted apart, per
 * content and per language. What one content prunes, another keeps.
 */
final class RevisionRetentionTest extends CmsIntegrationTestCase
{
    private const string OTHER_LOCALE = 'fr_FR';

    public function testSevenDraftsKeepTheFiveLatestOfAPage(): void
    {
        $page = $this->createPage('Drafts', published: false);

        $kept = $this->keepPageDrafts($page, 7);

        self::assertSame(\array_slice($kept, 2), $this->pageRevisionIds($page, 'draft'), 'The two oldest are gone, the five latest stay.');
    }

    public function testSevenDraftsKeepTheFiveLatestOfAnItem(): void
    {
        $content = $this->content();

        $kept = $this->keepItemDrafts($content, 7);

        self::assertSame(\array_slice($kept, 2), $this->itemRevisionIds($content, 'draft'));
    }

    public function testTwentyOnePublicationsKeepTheTwentyLatestOfAPage(): void
    {
        $page = $this->createPage('Publications', published: false);
        $writer = $this->getService(CmsPageWriter::class);
        $afterTwenty = [];

        for ($i = 1; $i <= 21; ++$i) {
            $writer->saveContent($page, $this->locale(), Document::richText(\sprintf('<h2>Version %d</h2>', $i)));
            $writer->publish($page, $this->locale());

            if (20 === $i) {
                $afterTwenty = $this->pageRevisionIds($page, null);
            }
        }

        $kept = $this->pageRevisionIds($page, null);
        self::assertCount(20, $afterTwenty);
        self::assertCount(20, $kept);
        self::assertNotContains($afterTwenty[0], $kept, 'The oldest one went with the twenty-first.');
        self::assertSame(\array_slice($afterTwenty, 1), \array_slice($kept, 0, 19), 'The nineteen next stay.');
    }

    public function testTwentyOnePublicationsKeepTheTwentyLatestOfAnItem(): void
    {
        $content = $this->content();
        $writer = $this->getService(ContentWriter::class);
        $id = (int) $content->getId();
        $afterTwenty = [];

        for ($i = 1; $i <= 21; ++$i) {
            $writer->saveContent($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()), Document::richText(\sprintf('<h2>Version %d</h2>', $i)));
            $writer->publish($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()));

            if (20 === $i) {
                $afterTwenty = $this->itemRevisionIds($content, null);
            }
        }

        $kept = $this->itemRevisionIds($content, null);
        self::assertCount(20, $afterTwenty);
        self::assertCount(20, $kept);
        self::assertNotContains($afterTwenty[0], $kept, 'The oldest one went with the twenty-first.');
        self::assertSame(\array_slice($afterTwenty, 1), \array_slice($kept, 0, 19));
    }

    public function testDraftsAndPublicationsAreCountedApart(): void
    {
        $page = $this->createPage('Apart', published: false);
        $content = $this->content();
        $pageWriter = $this->getService(CmsPageWriter::class);
        $itemWriter = $this->getService(ContentWriter::class);
        $id = (int) $content->getId();

        for ($i = 1; $i <= 3; ++$i) {
            $pageWriter->saveContent($page, $this->locale(), Document::richText(\sprintf('<h2>Version %d</h2>', $i)));
            $pageWriter->publish($page, $this->locale());
            $itemWriter->saveContent($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()), Document::richText(\sprintf('<h2>Version %d</h2>', $i)));
            $itemWriter->publish($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()));
        }

        $this->keepPageDrafts($page, 7);
        $this->keepItemDrafts($content, 7);

        self::assertCount(3, $this->pageRevisionIds($page, null), 'The drafts did not prune the publications.');
        self::assertCount(5, $this->pageRevisionIds($page, 'draft'));
        self::assertCount(3, $this->itemRevisionIds($content, null));
        self::assertCount(5, $this->itemRevisionIds($content, 'draft'));

        for ($i = 4; $i <= 22; ++$i) {
            $pageWriter->saveContent($page, $this->locale(), Document::richText(\sprintf('<h2>Version %d</h2>', $i)));
            $pageWriter->publish($page, $this->locale());
            $itemWriter->saveContent($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()), Document::richText(\sprintf('<h2>Version %d</h2>', $i)));
            $itemWriter->publish($this->itemTarget(CatalogueItemType::Content, $id, $this->locale()));
        }

        self::assertCount(20, $this->pageRevisionIds($page, null));
        self::assertCount(5, $this->pageRevisionIds($page, 'draft'), 'The publications did not prune the drafts.');
        self::assertCount(20, $this->itemRevisionIds($content, null));
        self::assertCount(5, $this->itemRevisionIds($content, 'draft'));
    }

    public function testWhatOneContentPrunesAnotherKeeps(): void
    {
        $page = $this->createPage('Pruned', published: false);
        $neighbour = $this->createPage('Kept', published: false);
        $content = $this->content();
        $otherContent = $this->content();

        $this->keepPageDrafts($neighbour, 3);
        $this->keepItemDrafts($otherContent, 3);
        $this->keepPageDrafts($page, 3, self::OTHER_LOCALE);
        $this->keepItemDrafts($content, 3, self::OTHER_LOCALE);

        $this->keepPageDrafts($page, 7);
        $this->keepItemDrafts($content, 7);

        self::assertCount(3, $this->pageRevisionIds($neighbour, 'draft'), 'Another page keeps its drafts.');
        self::assertCount(3, $this->itemRevisionIds($otherContent, 'draft'), 'Another item keeps its drafts.');
        self::assertCount(3, $this->pageRevisionIds($page, 'draft', self::OTHER_LOCALE), 'Another language keeps its drafts.');
        self::assertCount(3, $this->itemRevisionIds($content, 'draft', self::OTHER_LOCALE));
        self::assertCount(5, $this->pageRevisionIds($page, 'draft'));
        self::assertCount(5, $this->itemRevisionIds($content, 'draft'));
    }

    private function content(): Content
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return $factory->content($factory->folder(), ['title' => 'Retention', 'locale' => $this->locale()]);
    }

    /**
     * @return list<int> the ids of the revisions kept after each call, oldest first, as the last call leaves them
     */
    private function keepPageDrafts(CmsPage $page, int $times, ?string $locale = null): array
    {
        $locale ??= $this->locale();
        $writer = $this->getService(CmsPageWriter::class);
        $all = [];

        for ($i = 1; $i <= $times; ++$i) {
            $writer->saveContent($page, $locale, Document::richText(\sprintf('<h2>Draft %d</h2>', $i)));
            $writer->keepDraft($page, $locale);
            $all[] = $this->pageRevisionIds($page, 'draft', $locale);
        }

        return $this->everKept($all);
    }

    /**
     * @return list<int>
     */
    private function keepItemDrafts(Content $content, int $times, ?string $locale = null): array
    {
        $locale ??= $this->locale();
        $writer = $this->getService(ContentWriter::class);
        $id = (int) $content->getId();
        $all = [];

        for ($i = 1; $i <= $times; ++$i) {
            $writer->saveContent($this->itemTarget(CatalogueItemType::Content, $id, $locale), Document::richText(\sprintf('<h2>Draft %d</h2>', $i)));
            $writer->keepDraft($this->itemTarget(CatalogueItemType::Content, $id, $locale));
            $all[] = $this->itemRevisionIds($content, 'draft', $locale);
        }

        return $this->everKept($all);
    }

    /**
     * Every id a call kept, in the order they were created.
     *
     * @param list<list<int>> $snapshots
     *
     * @return list<int>
     */
    private function everKept(array $snapshots): array
    {
        $ids = array_unique(array_merge(...$snapshots));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int> oldest first
     */
    private function pageRevisionIds(CmsPage $page, ?string $label, ?string $locale = null): array
    {
        $ids = CmsPageRevisionQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale ?? $this->locale())
            ->filterByLabel($label, null === $label ? Criteria::ISNULL : Criteria::EQUAL)
            ->orderById()
            ->select('Id')
            ->find()
            ->getData();

        return array_map(intval(...), $ids);
    }

    /**
     * @return list<int> oldest first
     */
    private function itemRevisionIds(Content $content, ?string $label, ?string $locale = null): array
    {
        $ids = CmsItemRevisionQuery::create()
            ->filterByItemType('content')
            ->filterByItemId($content->getId())
            ->filterByLocale($locale ?? $this->locale())
            ->filterByLabel($label, null === $label ? Criteria::ISNULL : Criteria::EQUAL)
            ->orderById()
            ->select('Id')
            ->find()
            ->getData();

        return array_map(intval(...), $ids);
    }
}
