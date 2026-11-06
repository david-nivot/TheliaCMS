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
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\PageDate;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Page\Admin\PageListPresenter;
use TheliaCMS\Page\Admin\PageSort;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;

/**
 * What the page listing costs, counted rather than assumed.
 *
 * The listing used to ask the database for the state and the address of every page
 * one page at a time: 637 pages cost 1963 statements and 1.65 MB of HTML, and it
 * got worse with every page written. What matters is not a number of statements, it
 * is that the number does not depend on how many pages the site has. So the same
 * read is measured on a small tree and on one four times the size, and the two have
 * to come out equal.
 */
final class PageListingCostTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    public function testReadingTheTreeCostsTheSameOnASmallSiteAndOnABigOne(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();

        $small = $this->treeOf(2, 3, $locale);
        $smallCost = $this->statementsFor(static fn () => $repository->branch($locale, new PageFilters(open: $small)));

        // Four times the pages, same shape.
        $big = [...$small, ...$this->treeOf(6, 3, $locale)];
        $bigCost = $this->statementsFor(static fn () => $repository->branch($locale, new PageFilters(open: $big)));

        self::assertGreaterThan(0, $smallCost, 'nothing was measured');
        self::assertSame(
            $smallCost,
            $bigCost,
            'reading the tree costs more on a bigger site, so something is asked once per page',
        );
    }

    public function testSearchingCostsTheSameWhateverTheNumberOfMatches(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();
        $filters = new PageFilters(search: 'Costly');

        $this->treeOf(2, 3, $locale, 'Costly');
        $fewCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $few = $repository->results($locale, $filters)->total;

        $this->treeOf(6, 3, $locale, 'Costly');
        $manyCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $many = $repository->results($locale, $filters)->total;

        self::assertGreaterThan($few, $many, 'the second tree added no match, so this compares nothing');
        self::assertSame($fewCost, $manyCost, 'searching costs more when it finds more, so something is asked per row');
    }

    public function testFilteringByTagAndTypeCostsTheSameWhateverTheNumberOfMatches(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();
        $tag = (new CmsTag())->setName('Costly tag');
        $tag->save();
        $filters = new PageFilters(tag: (int) $tag->getId(), type: 'default');

        $this->tagAll($this->treeOf(1, 2, $locale, 'Tagged'), $tag);
        $fewCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $few = $repository->results($locale, $filters)->total;

        $this->tagAll($this->treeOf(4, 2, $locale, 'Tagged'), $tag);
        $manyCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $many = $repository->results($locale, $filters)->total;

        self::assertGreaterThan($few, $many, 'the second tree added no match, so this compares nothing');
        self::assertSame($fewCost, $manyCost, 'filtering by tag and type costs more when it finds more, so something is asked per row');
    }

    public function testSortingByTheLastChangeCostsTheSameWhateverTheNumberOfMatches(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();
        $filters = new PageFilters(search: 'Sorted', sort: PageSort::Modified);

        $this->treeOf(1, 2, $locale, 'Sorted');
        $fewCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $few = $repository->results($locale, $filters)->total;

        $this->treeOf(4, 2, $locale, 'Sorted');
        $manyCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $many = $repository->results($locale, $filters)->total;

        self::assertGreaterThan($few, $many, 'the second tree added no match, so this compares nothing');
        self::assertSame($fewCost, $manyCost, 'sorting by the last change costs more when it finds more, so something is asked per row');
    }

    public function testFilteringByPeriodAndAuthorCostsTheSameWhateverTheNumberOfMatches(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();
        $author = (int) (new FixtureFactory($this->getPropelConnection()))->admin()->getId();
        $today = new \DateTimeImmutable('today');
        $filters = new PageFilters(search: 'Dated', date: PageDate::Published, from: $today, to: $today, author: $author);

        $this->treeOf(1, 2, $locale, 'Dated');
        CmsPageQuery::create()->update(['UpdatedBy' => $author]);
        $fewCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $few = $repository->results($locale, $filters)->total;

        $this->treeOf(4, 2, $locale, 'Dated');
        CmsPageQuery::create()->update(['UpdatedBy' => $author]);
        $manyCost = $this->statementsFor(static fn () => $repository->results($locale, $filters));
        $many = $repository->results($locale, $filters)->total;

        self::assertGreaterThan($few, $many, 'the second tree added no match, so this compares nothing');
        self::assertSame($fewCost, $manyCost, 'filtering by period and author costs more when it finds more, so something is asked per row');
    }

    public function testListingTheAuthorsCostsOneStatementWhateverTheirNumber(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $fixtures = new FixtureFactory($this->getPropelConnection());

        [$root] = $this->treeOf(1, 2, $this->locale(), 'Authored');
        CmsPageQuery::create()->filterById($root)->update(['UpdatedBy' => (int) $fixtures->admin()->getId()]);
        $fewCost = $this->statementsFor(static fn () => $repository->authors());

        foreach ($this->treeOf(3, 0, $this->locale(), 'Authored') as $id) {
            CmsPageQuery::create()->filterById($id)->update(['UpdatedBy' => (int) $fixtures->admin()->getId()]);
        }

        $manyCost = $this->statementsFor(static fn () => $repository->authors());

        self::assertSame(1, $fewCost, 'the authors are not read in one statement');
        self::assertSame($fewCost, $manyCost, 'listing the authors costs more when there are more');
    }

    public function testReadingASelectionOfTheListCostsOneStatementWhateverItsSize(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();

        $few = $this->treeOf(2, 0, $locale, 'Selected');
        $fewCost = $this->statementsFor(static fn () => $repository->selected($few, $locale));

        $many = [...$few, ...$this->treeOf(6, 0, $locale, 'Selected')];
        $manyCost = $this->statementsFor(static fn () => $repository->selected($many, $locale));

        self::assertSame(1, $fewCost, 'the selection is not read in one statement');
        self::assertSame($fewCost, $manyCost, 'reading a selection costs more when it is larger');
        self::assertCount(8, $repository->selected($many, $locale));
    }

    public function testCountingTheStatesCostsTheSameOnASmallSiteAndOnABigOne(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();

        $this->treeOf(1, 2, $locale, 'Counted');
        $smallCost = $this->statementsFor(static fn () => $repository->statusCounts($locale));

        $this->treeOf(4, 2, $locale, 'Counted');
        $bigCost = $this->statementsFor(static fn () => $repository->statusCounts($locale));

        self::assertSame(1, $smallCost, 'the five counters are not read in one statement');
        self::assertSame($smallCost, $bigCost, 'counting the states costs more on a bigger site');
    }

    public function testAFoldedBranchIsNotRead(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();
        $roots = $this->treeOf(3, 4, $locale);

        $folded = $repository->branch($locale, new PageFilters());
        $unfolded = $repository->branch($locale, new PageFilters(open: $roots));

        $foldedIds = array_map(static fn ($row): int => $row->id(), $folded);

        foreach ($roots as $root) {
            self::assertContains($root, $foldedIds, 'a page at the root of the site is missing from the folded tree');
        }

        self::assertGreaterThan(\count($folded), \count($unfolded), 'unfolding the branches showed nothing new');
    }

    public function testAFoldedRowSaysHowManyPagesItHides(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();
        $roots = $this->treeOf(1, 3, $locale);

        $rows = $repository->branch($locale, new PageFilters());
        $root = null;

        foreach ($rows as $row) {
            if ($row->id() === $roots[0]) {
                $root = $row;
            }
        }

        self::assertNotNull($root);
        self::assertSame(3, $root->childCount);
        self::assertFalse($root->isOpen);
    }

    public function testASearchResultNamesThePagesItSitsUnder(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();

        $grandparent = $this->createPage('Deep grandparent', $locale);
        $parent = $this->createPage('Deep parent', $locale, parent: (int) $grandparent->getId());
        $this->createPage('Deep needle', $locale, parent: (int) $parent->getId());

        $results = $repository->results($locale, new PageFilters(search: 'Deep needle'));

        self::assertSame(1, $results->total);
        self::assertSame(['Deep grandparent', 'Deep parent'], $results->rows[0]->ancestorTitles);
        self::assertSame('Deep grandparent / Deep parent', $results->rows[0]->ancestorPath());
    }

    public function testABranchUnderAFoldedParentIsNotShown(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $locale = $this->locale();

        $root = $this->createPage('Orphan root', $locale);
        $middle = $this->createPage('Orphan middle', $locale, parent: (int) $root->getId());
        $leaf = $this->createPage('Orphan leaf', $locale, parent: (int) $middle->getId());

        // Asking for the deepest branch without the one above it, which is what an
        // address kept in a bookmark does after the tree has been reorganised.
        $rows = $repository->branch($locale, new PageFilters(open: [(int) $middle->getId()]));
        $shown = array_map(static fn ($row): int => $row->id(), $rows);

        self::assertContains((int) $root->getId(), $shown);
        self::assertNotContains((int) $middle->getId(), $shown, 'a page under a folded parent came out of the walk');
        self::assertNotContains((int) $leaf->getId(), $shown, 'a row was indented under a page that is not on screen');
    }

    public function testASmallSiteIsShownWholeWithoutBeingAskedTo(): void
    {
        $repository = $this->getService(CmsPageAdminRepository::class);
        $presenter = $this->getService(PageListPresenter::class);
        $locale = $this->locale();

        $root = $this->createPage('Small site root', $locale);
        $child = $this->createPage('Small site child', $locale, parent: (int) $root->getId());

        if ($repository->countLive() > PageFilters::OPEN_EVERYTHING_UNDER) {
            self::markTestSkipped(\sprintf(
                'this database holds %d pages, more than the %d under which the tree opens by itself',
                $repository->countLive(),
                PageFilters::OPEN_EVERYTHING_UNDER,
            ));
        }

        $context = $presenter->present(new PageFilters(), $this->language($locale));
        $shown = array_column($context['rows'], 'id');

        self::assertContains((int) $child->getId(), $shown, 'a small site is not shown whole');
        self::assertFalse($context['is_filtering']);
    }

    private function language(string $locale): Lang
    {
        $lang = LangQuery::create()->findOneByLocale($locale);

        self::assertNotNull($lang, \sprintf('the shop has no language for "%s"', $locale));

        return $lang;
    }

    /**
     * Tags the pages at the root and every page under them.
     *
     * @param list<int> $roots
     */
    private function tagAll(array $roots, CmsTag $tag): void
    {
        CmsPageQuery::create()->filterById($roots, Criteria::IN)->_or()->filterByParent($roots, Criteria::IN)
            ->update(['TagId' => (int) $tag->getId()]);
    }

    /**
     * A tree of `$roots` pages, each with `$children` pages under it.
     *
     * @return list<int> the identifiers of the pages at the root
     */
    private function treeOf(int $roots, int $children, string $locale, string $prefix = 'Cost'): array
    {
        static $run = 0;
        ++$run;
        $ids = [];

        for ($root = 1; $root <= $roots; ++$root) {
            $page = $this->createPage(\sprintf('%s root %d-%d', $prefix, $run, $root), $locale);
            $ids[] = (int) $page->getId();

            for ($child = 1; $child <= $children; ++$child) {
                $this->createPage(
                    \sprintf('%s child %d-%d-%d', $prefix, $run, $root, $child),
                    $locale,
                    parent: (int) $page->getId(),
                );
            }
        }

        return $ids;
    }
}
