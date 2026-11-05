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

namespace TheliaCMS\Page\Admin;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageI18nQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageI18nTableMap;
use TheliaCMS\Page\TrashRetention;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\TheliaCMS;

/**
 * Reads the page tree for the back office. Kept apart from the front-office
 * repository on purpose: this one sees drafts and deleted pages, the other one
 * must never be able to.
 *
 * Everything a row needs is read for all the rows at once. The rule here is that
 * the number of statements must not depend on the number of pages on the site,
 * only on how many are being shown, so a site of a few hundred pages costs what a
 * site of seven costs.
 */
final readonly class CmsPageAdminRepository
{
    public function __construct(
        private CmsSettings $settings,
    ) {
    }

    /**
     * The rows of the tree: the pages at the root of the site, and the children of
     * every branch the editor unfolded.
     *
     * A branch whose parent is folded is skipped even if its identifier is in the
     * list, so an address kept in a bookmark cannot produce rows indented under a
     * page that is not on screen.
     *
     * @return list<PageListRow>
     */
    public function branch(string $locale, PageFilters $filters): array
    {
        $childCounts = $this->childCounts();
        $byParent = $this->pagesByParent($locale, [0, ...$filters->open]);
        $ordered = $this->walk($byParent, 0, 0, $filters);

        return $this->decorate($ordered, $locale, $childCounts, $filters);
    }

    /**
     * The pages matching a search or a state filter, flat, ordered by title and
     * cut to one page of results.
     *
     * Ordered by title rather than by position: position only means something
     * among siblings, and these rows are not siblings.
     */
    public function results(string $locale, PageFilters $filters, ?\DateTimeInterface $now = null): PageResults
    {
        $now ??= new \DateTimeImmutable();

        $total = (int) $this->matching($locale, $filters, $now)->count();
        $pages = iterator_to_array(
            $this->matching($locale, $filters, $now)
                ->orderBy(CmsPageI18nTableMap::COL_TITLE)
                ->offset(($filters->page - 1) * PageFilters::PER_PAGE)
                ->limit(PageFilters::PER_PAGE)
                ->find(),
            false
        );

        $rows = $this->decorate(
            array_map(static fn (CmsPage $page): array => ['page' => $page, 'depth' => 0], $pages),
            $locale,
            $this->childCounts(),
            $filters,
        );

        return new PageResults(
            rows: $this->withAncestorTitles($rows, $locale),
            total: $total,
            page: $filters->page,
            perPage: PageFilters::PER_PAGE,
        );
    }

    /**
     * Pages in the bin, each flagged with whether it can come back on its own: a
     * page whose parent is also in the bin is restored with that parent, not
     * before it.
     *
     * @return list<array{page: CmsPage, restorable: bool, days_left: int|null}>
     */
    public function trash(string $locale, ?PageFilters $filters = null): array
    {
        $query = CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNOTNULL)
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->orderByDeletedAt(Criteria::DESC);

        if (null !== $filters && '' !== $filters->search) {
            $query->where(CmsPageI18nTableMap::COL_TITLE.' LIKE ?', '%'.$filters->search.'%');
        }

        $pages = iterator_to_array($query->find(), false);

        $binned = [];
        foreach ($pages as $page) {
            $binned[(int) $page->getId()] = true;
        }

        // Deleting on a schedule without saying when is how an editor discovers
        // the rule by losing a page, so the screen shows the deadline.
        $retentionDays = $this->settings->trashRetentionDays();

        return array_map(
            static fn (CmsPage $page): array => [
                'page' => $page,
                'restorable' => !isset($binned[(int) $page->getParent()]),
                'days_left' => null === $page->getDeletedAt()
                    ? null
                    : TrashRetention::daysLeft($page->getDeletedAt(), $retentionDays),
            ],
            $pages
        );
    }

    public function countInTrash(): int
    {
        return (int) CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNOTNULL)
            ->count();
    }

    public function countLive(): int
    {
        return (int) CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->filterByModel(0)
            ->count();
    }

    /**
     * Identifiers of the pages that have at least one page underneath them, used
     * to unfold a site small enough to be read whole.
     *
     * @return list<int>
     */
    public function branchingPageIds(): array
    {
        // A key of the counts is a page that has children; zero is the root of
        // the site, which is not a page and cannot be unfolded.
        return array_values(array_filter(
            array_keys($this->childCounts()),
            static fn (int $parent): bool => $parent > 0,
        ));
    }

    public function findLive(int $id): ?CmsPage
    {
        return CmsPageQuery::create()
            ->filterById($id)
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->findOne();
    }

    public function findDeleted(int $id): ?CmsPage
    {
        return CmsPageQuery::create()
            ->filterById($id)
            ->filterByDeletedAt(null, Criteria::ISNOTNULL)
            ->findOne();
    }

    /**
     * The chain of pages above this one, closest first, so a screen can say where
     * a page sits without walking the tree itself.
     *
     * @return list<CmsPage>
     */
    public function ancestorsOf(CmsPage $page, string $locale): array
    {
        $ancestors = [];
        $seen = [(int) $page->getId() => true];
        $parentId = (int) $page->getParent();

        while ($parentId > 0 && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = CmsPageQuery::create()
                ->filterById($parentId)
                ->joinWithI18n($locale, Criteria::LEFT_JOIN)
                ->findOne();

            if (null === $parent) {
                break;
            }

            $ancestors[] = $parent;
            $parentId = (int) $parent->getParent();
        }

        return $ancestors;
    }

    /**
     * Pages that could become the parent of `$pageId`, i.e. everything but the
     * page itself and its own descendants — otherwise the tree gets a cycle
     * and every walk of it hangs.
     *
     * @return array<string, int>
     */
    public function parentChoices(string $locale, ?int $pageId = null): array
    {
        $excluded = null === $pageId ? [] : $this->descendantIds($pageId) + [$pageId => $pageId];
        $byParent = $this->pagesByParent($locale, null);
        $choices = [];

        // No state and no address is read here: this builds a select, and asking
        // for the state of every page on the site to label an option is how the
        // edit screen came to cost one statement per page as well.
        foreach ($this->walkAll($byParent, 0, 0) as $row) {
            $id = (int) $row['page']->getId();

            if (isset($excluded[$id])) {
                continue;
            }

            $choices[str_repeat('— ', $row['depth']).$this->label($row['page'], $locale)] = $id;
        }

        return $choices;
    }

    /**
     * Whether the draft or what is online in this language carries the mark
     * of a generated content.
     */
    public function isAiMarked(CmsPage $page, string $locale): bool
    {
        return CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->where('(CmsPageContent.DraftProvenance IS NOT NULL OR CmsPageContent.PublishedProvenance IS NOT NULL)')
            ->exists();
    }

    /**
     * The draft of the page in this language; null while none was written.
     */
    public function draftDocument(CmsPage $page, string $locale): ?Document
    {
        return Document::fromJson(CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($locale)->findOne()?->getDraftDocument());
    }

    /**
     * The rendering of the draft of the page in this language; null while none was written.
     */
    public function draftHtml(CmsPage $page, string $locale): ?string
    {
        return CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($locale)->findOne()?->getDraftHtml();
    }

    public function statusOf(CmsPage $page, string $locale): PageStatus
    {
        $content = CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->findOne();

        return PageStatus::resolve($page, $content);
    }

    /**
     * The live pages matching the filters, without ordering or limit.
     */
    private function matching(string $locale, PageFilters $filters, \DateTimeInterface $now): CmsPageQuery
    {
        $query = CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            // The models of the page types are reached from their type.
            ->filterByModel(0)
            ->joinWithI18n($locale, Criteria::LEFT_JOIN);

        if ('' !== $filters->search) {
            // Looked for in the title of the language being edited, which is the
            // one the rows show: matching a title the screen does not display
            // returns pages an editor cannot recognise.
            $query->where(CmsPageI18nTableMap::COL_TITLE.' LIKE ?', '%'.$filters->search.'%');
        }

        if (null !== $filters->visible) {
            $query->filterByVisible($filters->visible ? 1 : 0);
        }

        if ([] !== $filters->statuses) {
            PageStatusCriteria::restrictTo(PageStatusCriteria::joinContent($query, $locale), $filters->statuses, $now);
        }

        return $query;
    }

    /**
     * Live pages grouped by their parent. `$parents` limits the read to the
     * branches being shown; null reads the whole tree.
     *
     * @param list<int>|null $parents
     *
     * @return array<int, list<CmsPage>>
     */
    private function pagesByParent(string $locale, ?array $parents): array
    {
        $query = CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            // The models of the page types are reached from their type, and
            // never parents: a page under a model would hang off a page
            // nobody sees in the tree.
            ->filterByModel(0)
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->orderByParent()
            ->orderByPosition();

        if (null !== $parents) {
            $query->filterByParent(array_values(array_unique($parents)), Criteria::IN);
        }

        $byParent = [];

        foreach ($query->find() as $page) {
            $byParent[(int) $page->getParent()][] = $page;
        }

        return $byParent;
    }

    /**
     * Depth-first walk that only descends into the branches the editor unfolded.
     *
     * Bounded by the pages it has already seen rather than by a depth: a limit on
     * depth would silently drop the pages below it, and what has to be guarded
     * against is a tree holding a cycle.
     *
     * @param array<int, list<CmsPage>> $byParent
     *
     * @return list<array{page: CmsPage, depth: int}>
     */
    private function walk(array $byParent, int $parentId, int $depth, PageFilters $filters, array &$seen = []): array
    {
        $rows = [];

        foreach ($byParent[$parentId] ?? [] as $page) {
            $id = (int) $page->getId();

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $rows[] = ['page' => $page, 'depth' => $depth];

            if ($filters->isOpen($id)) {
                $rows = array_merge($rows, $this->walk($byParent, $id, $depth + 1, $filters, $seen));
            }
        }

        return $rows;
    }

    /**
     * @param array<int, list<CmsPage>> $byParent
     *
     * @return list<array{page: CmsPage, depth: int}>
     */
    private function walkAll(array $byParent, int $parentId, int $depth, array &$seen = []): array
    {
        $rows = [];

        foreach ($byParent[$parentId] ?? [] as $page) {
            $id = (int) $page->getId();

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $rows[] = ['page' => $page, 'depth' => $depth];
            $rows = array_merge($rows, $this->walkAll($byParent, $id, $depth + 1, $seen));
        }

        return $rows;
    }

    /**
     * Turns walked pages into rows, reading state and addresses for all of them
     * in two statements.
     *
     * @param list<array{page: CmsPage, depth: int}> $ordered
     * @param array<int, int>                        $childCounts
     *
     * @return list<PageListRow>
     */
    private function decorate(array $ordered, string $locale, array $childCounts, PageFilters $filters): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['page']->getId(), $ordered);
        $contents = $this->contentsFor($ids, $locale);
        $addresses = $this->addressesFor($ids, $locale);

        $rows = [];
        $untitled = [];

        foreach ($ordered as $row) {
            $id = (int) $row['page']->getId();
            // The rows are read with the translation of one locale joined; pinning
            // it makes the title of a page with no translation come out empty
            // rather than in whichever language was asked for last.
            $page = $row['page']->setLocale($locale);

            if ('' === trim((string) $page->getTitle())) {
                $untitled[] = $id;
            }

            $rows[] = new PageListRow(
                page: $page,
                depth: $row['depth'],
                status: PageStatus::resolve($page, $contents[$id] ?? null),
                // Empty stays empty. A page with no address in this language
                // shown as "/" reads as the home page of the site.
                address: isset($addresses[$id]) ? '/'.ltrim($addresses[$id], '/') : '',
                childCount: $childCounts[$id] ?? 0,
                isOpen: $filters->isOpen($id),
            );
        }

        return $this->withTitlesFromAnotherLanguage($rows, $untitled, $locale);
    }

    /**
     * Gives the rows with nothing to show a name from another language.
     *
     * A page written in French and listed in English used to appear as "#28",
     * which nobody can recognise and nobody can find. One statement, and only
     * when at least one row needs it.
     *
     * @param list<PageListRow> $rows
     * @param list<int>         $untitled
     *
     * @return list<PageListRow>
     */
    private function withTitlesFromAnotherLanguage(array $rows, array $untitled, string $locale): array
    {
        if ([] === $untitled) {
            return $rows;
        }

        $found = [];

        foreach (CmsPageI18nQuery::create()->filterById($untitled, Criteria::IN)->find() as $translation) {
            $title = trim((string) $translation->getTitle());
            $id = (int) $translation->getId();

            if ('' === $title || $locale === $translation->getLocale() || isset($found[$id])) {
                continue;
            }

            $found[$id] = $title;
        }

        return array_map(
            static fn (PageListRow $row): PageListRow => new PageListRow(
                page: $row->page,
                depth: $row->depth,
                status: $row->status,
                address: $row->address,
                childCount: $row->childCount,
                isOpen: $row->isOpen,
                ancestorTitles: $row->ancestorTitles,
                titleInAnotherLanguage: $found[$row->id()] ?? null,
            ),
            $rows,
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, \TheliaCMS\Model\CmsPageContent>
     */
    private function contentsFor(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $contents = [];

        foreach (CmsPageContentQuery::create()->filterByPageId($ids, Criteria::IN)->filterByLocale($locale)->find() as $content) {
            $contents[(int) $content->getPageId()] = $content;
        }

        return $contents;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function addressesFor(array $ids, string $locale): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = RewritingUrlQuery::create()
            ->filterByView(TheliaCMS::PAGE_VIEW)
            ->filterByViewId(array_map(strval(...), $ids), Criteria::IN)
            ->filterByViewLocale($locale)
            // A redirect is an address the page used to answer on. Showing one
            // would tell the editor the page lives somewhere it no longer does.
            ->filterByRedirected(null, Criteria::ISNULL)
            ->select(['ViewId', 'Url'])
            ->find()
            ->toArray();

        $addresses = [];

        foreach ($rows as $row) {
            $addresses[(int) $row['ViewId']] = (string) $row['Url'];
        }

        return $addresses;
    }

    /**
     * The titles of the pages above each row, read level by level: four
     * statements at most for a tree the module recommends keeping four deep,
     * whatever the number of rows.
     *
     * @param list<PageListRow> $rows
     *
     * @return list<PageListRow>
     */
    private function withAncestorTitles(array $rows, string $locale): array
    {
        $titles = [];
        $parents = [];

        foreach ($rows as $row) {
            $parents[(int) $row->page->getParent()] = true;
        }

        unset($parents[0]);
        $known = [];

        while ([] !== $parents) {
            $found = CmsPageQuery::create()
                ->filterById(array_keys($parents), Criteria::IN)
                ->joinWithI18n($locale, Criteria::LEFT_JOIN)
                ->find();

            $parents = [];

            foreach ($found as $page) {
                $id = (int) $page->getId();

                if (isset($known[$id])) {
                    continue;
                }

                $known[$id] = ['title' => $this->label($page, $locale), 'parent' => (int) $page->getParent()];

                if ($known[$id]['parent'] > 0 && !isset($known[$known[$id]['parent']])) {
                    $parents[$known[$id]['parent']] = true;
                }
            }
        }

        foreach ($rows as $row) {
            $chain = [];
            $parentId = (int) $row->page->getParent();

            while ($parentId > 0 && isset($known[$parentId])) {
                array_unshift($chain, $known[$parentId]['title']);
                $parentId = $known[$parentId]['parent'];
            }

            $titles[] = new PageListRow(
                page: $row->page,
                depth: $row->depth,
                status: $row->status,
                address: $row->address,
                childCount: $row->childCount,
                isOpen: $row->isOpen,
                ancestorTitles: $chain,
                titleInAnotherLanguage: $row->titleInAnotherLanguage,
            );
        }

        return $titles;
    }

    /**
     * How many live pages sit directly under each page, in one statement for the
     * whole site: the tree shows the count on every folded branch, and asking per
     * row is the difference between five statements and one per line.
     *
     * @return array<int, int>
     */
    private function childCounts(): array
    {
        $rows = CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->filterByModel(0)
            ->groupByParent()
            ->select(['Parent'])
            ->withColumn('COUNT(*)', 'children')
            ->find()
            ->toArray();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['Parent']] = (int) $row['children'];
        }

        return $counts;
    }

    /**
     * The pages under this one at any depth, binned ones included.
     *
     * @return array<int, int>
     */
    public function descendantIds(int $pageId): array
    {
        $ids = [];
        $frontier = [$pageId];

        while ([] !== $frontier) {
            $children = CmsPageQuery::create()
                ->filterByParent($frontier)
                ->select(['Id'])
                ->find()
                ->toArray();

            $frontier = [];

            foreach ($children as $childId) {
                $childId = (int) $childId;

                if (isset($ids[$childId])) {
                    continue;
                }

                $ids[$childId] = $childId;
                $frontier[] = $childId;
            }
        }

        return $ids;
    }

    private function label(CmsPage $page, string $locale): string
    {
        $title = trim((string) $page->setLocale($locale)->getTitle());

        return '' === $title ? '#'.$page->getId() : $title;
    }
}
