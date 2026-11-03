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

use TheliaCMS\Generation\Brief;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageI18nQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageI18nTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Model\Map\CmsPageTypeDefinitionTableMap;
use TheliaCMS\Model\Map\CmsTagTableMap;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\PageListQuery;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;

/**
 * What a list of published pages costs on the front: the pages of a type
 * (`cms_pages_of_type`, the page list section) and the pages of a tag.
 *
 * Each list is read on a few pages and on four times as many, from a cold
 * start, and the two reads have to cost the same: a statement asked once per
 * page shows as a difference.
 */
final class PublishedPageCostTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    private const TYPE = 'cost-recipe';

    public function testListingThePagesOfATypeCostsTheSameWhateverTheirNumber(): void
    {
        $this->givenTheType();
        $this->recipes(3);
        $few = $this->coldCost(fn () => $this->repository()->ofType(self::TYPE, $this->locale(), new PageListQuery(limit: 100)));

        $this->recipes(9);
        $many = $this->coldCost(fn () => $this->repository()->ofType(self::TYPE, $this->locale(), new PageListQuery(limit: 100)));

        self::assertCount(12, $this->repository()->ofType(self::TYPE, $this->locale(), new PageListQuery(limit: 100)), 'the list does not hold the pages written');
        self::assertSame($few, $many, \sprintf('3 pages cost %d statements and 12 cost %d: something is asked once per page', $few, $many));
    }

    public function testListingThePagesOfATagCostsTheSameWhateverTheirNumber(): void
    {
        $tag = $this->getService(PageTagWriter::class)->add('Étiquette du coût');
        $this->tagged($tag, 3);
        $few = $this->coldCost(fn () => $this->repository()->withTag('Étiquette du coût', $this->locale()));

        $this->tagged($tag, 9);
        $many = $this->coldCost(fn () => $this->repository()->withTag('Étiquette du coût', $this->locale()));

        self::assertCount(12, $this->repository()->withTag('Étiquette du coût', $this->locale()), 'the list does not hold the pages written');
        self::assertSame($few, $many, \sprintf('3 pages cost %d statements and 12 cost %d: something is asked once per page', $few, $many));
    }

    public function testAPageWithoutATitleInTheLanguageStaysInTheList(): void
    {
        $this->givenTheType();
        [$page] = $this->recipes(1);
        CmsPageI18nQuery::create()->filterById($page->getId())->filterByLocale($this->locale())->delete();
        $this->forgetWhatWasRead(self::MAPS);

        $cards = $this->repository()->ofType(self::TYPE, $this->locale(), new PageListQuery(limit: 100));

        self::assertSame([(int) $page->getId()], array_map(static fn ($card): int => $card->id, $cards));
        self::assertSame('', $cards[0]->title);
    }

    private const MAPS = [
        CmsPageTableMap::class,
        CmsPageI18nTableMap::class,
        CmsPageContentTableMap::class,
        CmsPageTypeDefinitionTableMap::class,
        CmsTagTableMap::class,
    ];

    private function coldCost(callable $read): int
    {
        $this->forgetWhatWasRead(self::MAPS);

        return $this->statementsFor($read);
    }

    private function givenTheType(): void
    {
        $writer = $this->getService(PageTypeWriter::class);
        $writer->add(self::TYPE);
        $writer->describe(self::TYPE, $this->locale(), 'Recette', 'Les recettes du site.', new Brief('Une recette.', 'simple'));
        $writer->saveField(self::TYPE, null, $this->locale(), ['code' => 'servings', 'kind' => 'integer', 'label' => 'Personnes']);
        $writer->saveField(self::TYPE, null, $this->locale(), ['code' => 'tip', 'kind' => 'text', 'label' => 'Astuce', 'translated' => true]);
    }

    /**
     * @return list<CmsPage>
     */
    private function recipes(int $count): array
    {
        static $run = 0;
        $pages = [];

        for ($index = 1; $index <= $count; ++$index) {
            $title = \sprintf('Recette du coût %d-%d', ++$run, $index);
            $page = $this->createPage($title, published: false);
            $page->setPageType(self::TYPE);
            $this->writer()->saveDraft($page, $this->locale(), new PageDraft(title: $title, fields: ['servings' => $index, 'tip' => 'Servir chaud.']));
            $this->writer()->publish($page, $this->locale());
            $pages[] = $page;
        }

        return $pages;
    }

    private function tagged(CmsTag $tag, int $count): void
    {
        static $run = 0;

        for ($index = 1; $index <= $count; ++$index) {
            $this->createPage(\sprintf('Page étiquetée %d-%d', ++$run, $index))->setTagId($tag->getId())->save();
        }
    }

    private function repository(): PublishedPageRepository
    {
        return $this->getService(PublishedPageRepository::class);
    }
}
