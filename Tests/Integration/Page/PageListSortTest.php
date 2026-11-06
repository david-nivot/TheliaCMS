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

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\Lang;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageTypeDefinition;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Page\Admin\PageListRow;
use TheliaCMS\Page\Admin\PageSort;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The list of pages sorts by title, last change or creation, in both
 * directions, as a flat list; it shows when each page last changed and its type.
 */
final class PageListSortTest extends AdminScreenTestCase
{
    private const string TYPE = 'sort-case';

    public function testTheTitlesSortBothWaysWithThePagesWithoutATitleLast(): void
    {
        $this->givenType();
        $beta = $this->page('Sortcase Beta');
        $alpha = $this->page('Sortcase Alpha');
        $gamma = $this->page('Sortcase Gamma');
        $untitled = $this->untitled($this->page('Sortcase Nameless'));

        self::assertSame([$alpha, $beta, $gamma, $untitled], $this->idsOf(PageSort::Title, false));
        self::assertSame([$gamma, $beta, $alpha, $untitled], $this->idsOf(PageSort::Title, true), 'A page without a title stays last in both directions.');
        self::assertSame([$alpha, $beta, $gamma, $untitled], $this->idsOf(null, null), 'Without a sort the results keep their order by title.');
    }

    public function testTheLastChangeIsTheLatestOfThePageAndOfItsContent(): void
    {
        $this->givenType();
        // Settings saved in February, content untouched since January.
        $settings = $this->page('Sortcase settings');
        $this->dated($settings, page: '2026-02-01 10:00:00', content: '2026-01-15 10:00:00');
        // Settings untouched since January, content rewritten in March: the
        // page alone would put it last.
        $content = $this->page('Sortcase content');
        $this->dated($content, page: '2026-01-01 10:00:00', content: '2026-03-01 10:00:00');
        $recent = $this->page('Sortcase recent');
        $this->dated($recent, page: '2026-04-01 10:00:00', content: '2026-04-01 09:00:00');

        self::assertSame([$recent, $content, $settings], $this->idsOf(PageSort::Modified, true));
        self::assertSame([$settings, $content, $recent], $this->idsOf(PageSort::Modified, false));

        $rows = $this->rowsOf(PageSort::Modified, true);
        self::assertSame(
            ['2026-04-01 10:00:00', '2026-03-01 10:00:00', '2026-02-01 10:00:00'],
            array_map(static fn (PageListRow $row): string => (string) $row->modifiedAt?->format('Y-m-d H:i:s'), $rows),
            'The date shown is the one the list is sorted on.',
        );
    }

    public function testTheCreationDateSortsBothWays(): void
    {
        $this->givenType();
        $old = $this->page('Sortcase old');
        $new = $this->page('Sortcase new');
        $middle = $this->page('Sortcase middle');
        CmsPageQuery::create()->filterById($old)->update(['CreatedAt' => '2025-01-01 10:00:00']);
        CmsPageQuery::create()->filterById($middle)->update(['CreatedAt' => '2025-06-01 10:00:00']);
        CmsPageQuery::create()->filterById($new)->update(['CreatedAt' => '2026-01-01 10:00:00']);
        $this->forgetThePages();

        self::assertSame([$new, $middle, $old], $this->idsOf(PageSort::Created, true));
        self::assertSame([$old, $middle, $new], $this->idsOf(PageSort::Created, false));
    }

    public function testTheHeadersSortAndReverseAndTheChipGivesTheTreeBack(): void
    {
        $this->page('Headercase page');

        $tree = $this->listing([]);
        self::assertSame(0, $tree->filter('th[aria-sort]')->count(), 'The tree is sorted by nothing.');
        self::assertSame(['sort' => 'title'], $this->filtersOf((string) $tree->filter('[data-testid="cms-pages-sort-title"]')->attr('href')));
        self::assertSame(['sort' => 'modified'], $this->filtersOf((string) $tree->filter('[data-testid="cms-pages-sort-modified"]')->attr('href')));

        $byTitle = $this->listing(['sort' => 'title']);
        self::assertSame('ascending', $byTitle->filter('th[aria-sort]')->attr('aria-sort'));
        self::assertSame(1, $byTitle->filter('th[aria-sort] [data-testid="cms-pages-sort-title"]')->count(), 'The title header says it sorts the list.');
        self::assertSame(['sort' => 'title', 'dir' => 'desc'], $this->filtersOf((string) $byTitle->filter('[data-testid="cms-pages-sort-title"]')->attr('href')), 'A second click reverses the order.');
        self::assertSame(['sort' => 'title', 'dir' => 'desc'], $this->filtersOf((string) $byTitle->filter('[data-testid="cms-pages-sort-reverse"]')->attr('href')));
        self::assertSame('title', $byTitle->filter('#cms-pages-sort option[selected]')->attr('value'), 'The select shows the sort.');
        self::assertSame(1, $byTitle->filter('[data-testid="cms-pages-flat-list"]')->count(), 'A sort makes the list flat.');

        $chip = $byTitle->filter('[data-testid="cms-pages-sort-chip"]');
        self::assertSame(1, $chip->count(), 'The sort has its chip.');
        self::assertStringContainsString('Sorted by title, A to Z', $chip->text());
        self::assertStringNotContainsString('Showing only', $byTitle->filter('[data-testid="cms-pages-active-filters"]')->text(), 'A sort keeps every page: nothing is "shown only".');

        $filteredAndSorted = $this->listing(['q' => 'Headercase', 'sort' => 'title']);
        self::assertStringContainsString('Showing only', $filteredAndSorted->filter('[data-testid="cms-pages-active-filters"]')->text(), 'The filters keep their label.');
        self::assertSame(1, $filteredAndSorted->filter('[data-testid="cms-pages-sort-chip"]')->count());
        self::assertSame([], $this->filtersOf((string) $chip->attr('href')), 'Removing the chip gives the tree back.');

        $reversed = $this->listing(['sort' => 'title', 'dir' => 'desc']);
        self::assertSame('descending', $reversed->filter('th[aria-sort]')->attr('aria-sort'));

        $byDate = $this->listing(['sort' => 'modified']);
        self::assertSame('descending', $byDate->filter('th[aria-sort]')->attr('aria-sort'), 'A date starts from the latest.');
        self::assertSame(['sort' => 'modified', 'dir' => 'asc'], $this->filtersOf((string) $byDate->filter('[data-testid="cms-pages-sort-modified"]')->attr('href')));
        self::assertSame(['sort' => 'title'], $this->filtersOf((string) $byDate->filter('[data-testid="cms-pages-sort-title"]')->attr('href')), 'Another header starts its own order.');
    }

    public function testTheRowsShowTheirLastChangeAndTheirType(): void
    {
        $this->givenType();
        $page = $this->page('Columncase page');
        $this->dated($page, page: '2026-02-03 04:05:00', content: '2026-01-01 00:00:00');

        $row = $this->listing(['type' => self::TYPE])->filter(\sprintf('[data-testid="cms-page-row-%d"]', $page));

        self::assertSame('03/02/2026 04:05', trim($row->filter(\sprintf('[data-testid="cms-page-modified-%d"]', $page))->text()));
        self::assertSame('Sort case', trim($row->filter(\sprintf('[data-testid="cms-page-type-%d"]', $page))->text()));
    }

    public function testTheRowActionsKeepTheSortAndComeBackToIt(): void
    {
        $page = $this->page('Rowsortcase page');
        $screen = $this->listing(['q' => 'Rowsortcase', 'sort' => 'title', 'dir' => 'desc']);
        $row = $screen->filter(\sprintf('[data-testid="cms-page-row-%d"]', $page));
        self::assertSame(1, $row->count());

        $forms = $row->filter('form')->each(static fn (Crawler $form): string => (string) $form->attr('action'));
        self::assertNotEmpty($forms, 'The row has actions.');

        foreach ($forms as $action) {
            parse_str((string) parse_url($action, \PHP_URL_QUERY), $query);
            self::assertSame('title', $query['sort'] ?? null, \sprintf('%s drops the sort.', $action));
            self::assertSame('desc', $query['dir'] ?? null, \sprintf('%s drops the direction.', $action));
        }

        $visibility = \sprintf('/admin/cms/pages/%d/visibility', $page);
        $action = (string) $row->filter('form')->reduce(
            static fn (Crawler $form): bool => parse_url((string) $form->attr('action'), \PHP_URL_PATH) === $visibility,
        )->attr('action');
        $this->send('POST', $action, ['_token' => $this->tokenOfTheForm($screen, $visibility)]);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $back);
        self::assertSame('title', $back['sort'] ?? null, 'Hiding a page comes back to the sorted list.');
        self::assertSame('desc', $back['dir'] ?? null);

        parse_str((string) parse_url((string) $row->filter('a.fw-medium')->attr('href'), \PHP_URL_QUERY), $edit);
        parse_str($edit['return'] ?? '', $return);
        self::assertSame(['q' => 'Rowsortcase', 'sort' => 'title', 'dir' => 'desc'], $return, 'The settings lead back to the sorted list.');
    }

    private function givenType(): void
    {
        (new CmsPageTypeDefinition())
            ->setCode(self::TYPE)
            ->setLocale($this->locale())
            ->setTitle('Sort case')
            ->save();
    }

    /**
     * A page of the type of this test, so the results hold the pages of the
     * test and nothing else.
     */
    private function page(string $title): int
    {
        $page = $this->createPage($title);
        $page->setPageType(self::TYPE)->save();

        return (int) $page->getId();
    }

    private function untitled(int $id): int
    {
        $page = CmsPageQuery::create()->findPk($id);
        self::assertInstanceOf(CmsPage::class, $page);
        $page->setLocale($this->locale())->setTitle('')->save();

        return $id;
    }

    private function dated(int $id, string $page, string $content): void
    {
        CmsPageQuery::create()->filterById($id)->update(['UpdatedAt' => $page]);
        CmsPageContentQuery::create()->filterByPageId($id)->filterByLocale($this->locale())->update(['UpdatedAt' => $content]);
        $this->forgetThePages();
    }

    /**
     * The instance pool would hand back the pages as they were before the
     * dates were written behind its back.
     */
    private function forgetThePages(): void
    {
        CmsPageTableMap::clearInstancePool();
        CmsPageContentTableMap::clearInstancePool();
    }

    /**
     * @return list<int>
     */
    private function idsOf(?PageSort $sort, ?bool $descending): array
    {
        return array_map(static fn (PageListRow $row): int => $row->id(), $this->rowsOf($sort, $descending));
    }

    /**
     * @return list<PageListRow>
     */
    private function rowsOf(?PageSort $sort, ?bool $descending): array
    {
        return $this->getService(CmsPageAdminRepository::class)
            ->results($this->locale(), new PageFilters(type: self::TYPE, sort: $sort, descending: $descending))
            ->rows;
    }

    /**
     * @param array<string, string> $query
     */
    private function listing(array $query): Crawler
    {
        return $this->screen('/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), ...$query]));
    }

    /**
     * The state of the list in an address, without the language.
     *
     * @return array<string, mixed>
     */
    private function filtersOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        unset($query['edit_language_id']);

        return $query;
    }
}
