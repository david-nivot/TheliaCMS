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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Dashboard\ShowcaseStats;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\PageDraft;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Page\Admin\PageListPresenter;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;

/**
 * What telling the translations that are out of date costs, counted rather
 * than assumed: the list of pages and the dashboard read the source draft of
 * every translated page, in one query, whatever their number. Same read on
 * 5 translated pages and on 50, the two must cost the same statements.
 */
final class StaleTranslationCostTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    private const array MAPS = [CmsPageTableMap::class, CmsPageContentTableMap::class];

    public function testTheDashboardCostsTheSameWithFivePagesAndWithFifty(): void
    {
        $stats = $this->getService(ShowcaseStats::class);
        $locale = $this->locale();

        $this->translatedPages(5);
        $stats->collect($locale);
        $this->forgetWhatWasRead(self::MAPS);
        $few = $this->statementsFor(static fn () => $stats->collect($locale));
        $fewStale = $this->staleOf($stats->collect($locale));

        $this->translatedPages(45);
        $stats->collect($locale);
        $this->forgetWhatWasRead(self::MAPS);
        $many = $this->statementsFor(static fn () => $stats->collect($locale));
        $manyStale = $this->staleOf($stats->collect($locale));

        self::assertGreaterThan(0, $few, 'nothing was measured');
        self::assertGreaterThanOrEqual($fewStale + 45, $manyStale, 'the 45 pages added are stale, so this compares something');
        self::assertSame($few, $many, 'the dashboard costs more statements with more translated pages: something is asked once per page');
    }

    public function testTheListCostsTheSameWithFivePagesAndWithFifty(): void
    {
        $presenter = $this->getService(PageListPresenter::class);
        $lang = $this->otherLanguage();

        $this->translatedPages(5);
        $presenter->present(new PageFilters(foldChosen: true), $lang);
        $this->forgetWhatWasRead(self::MAPS);
        $few = $this->statementsFor(static fn () => $presenter->present(new PageFilters(foldChosen: true), $lang));
        $fewBadges = $this->badges($presenter->present(new PageFilters(foldChosen: true), $lang));

        $this->translatedPages(45);
        $presenter->present(new PageFilters(foldChosen: true), $lang);
        $this->forgetWhatWasRead(self::MAPS);
        $many = $this->statementsFor(static fn () => $presenter->present(new PageFilters(foldChosen: true), $lang));
        $manyBadges = $this->badges($presenter->present(new PageFilters(foldChosen: true), $lang));

        self::assertGreaterThan(0, $few, 'nothing was measured');
        self::assertGreaterThanOrEqual($fewBadges + 45, $manyBadges, 'the 45 pages added are on the list and stale, so this compares something');
        self::assertSame($few, $many, 'the list costs more statements with more translated pages: something is asked once per row');
    }

    /**
     * Pages written in the language of the site, translated into the other
     * one, and changed since: each is to be translated again.
     */
    private function translatedPages(int $count): void
    {
        $writer = $this->getService(CmsPageWriter::class);
        $freshness = $this->getService(TranslationFreshness::class);
        $targets = $this->getService(ContentTargets::class);
        $other = $this->otherLanguage()->getLocale();
        static $run = 0;
        ++$run;

        for ($index = 1; $index <= $count; ++$index) {
            $page = $this->createPage(\sprintf('Translated %d-%d', $run, $index), published: false);
            $source = $targets->forPage($page, $this->locale());
            $read = $freshness->read($source);
            // Named in the other language too: a page that is not costs the list a query of its own to
            // find its name, which has nothing to do with what is measured here.
            $writer->saveDraft($page, $other, new PageDraft(title: \sprintf('Traduite %d-%d', $run, $index)));
            $writer->saveContent($page, $other, Document::richText('<h2>Written for the test</h2>'));
            $freshness->record($targets->forPage($page, $other), $this->locale(), $read ?? throw new \LogicException('The page holds a source.'));
            $writer->saveContent($page, $this->locale(), Document::richText(\sprintf('<h2>Changed %d-%d</h2>', $run, $index)));
        }
    }

    private function otherLanguage(): Lang
    {
        $other = LangQuery::create()->filterByActive(true)->filterById(Lang::getDefaultLanguage()->getId(), Criteria::NOT_EQUAL)->orderById()->findOne();
        self::assertNotNull($other, 'The shop of the tests has a second active language.');

        return $other;
    }

    /**
     * @param array<string, mixed> $figures
     */
    private function staleOf(array $figures): int
    {
        foreach ($figures['completeness'] as $row) {
            if ($row['locale'] === $this->otherLanguage()->getLocale()) {
                return $row['stale'];
            }
        }

        self::fail('A row for the other language.');
    }

    /**
     * @param array<string, mixed> $list
     */
    private function badges(array $list): int
    {
        return \count(array_filter($list['rows'], static fn (array $row): bool => $row['is_stale_translation']));
    }
}
