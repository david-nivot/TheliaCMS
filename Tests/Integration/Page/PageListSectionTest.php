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
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The pages of one page, listed flat: what an editor opens to work on a
 * section of the site without unfolding the tree branch by branch.
 */
final class PageListSectionTest extends AdminScreenTestCase
{
    public function testThePagesDirectlyUnderAPageAreListedInTheOrderOfTheSite(): void
    {
        $recipes = $this->createPage('Section recipes');
        $soup = $this->under($this->createPage('Section soup'), $recipes, 2);
        $tart = $this->under($this->createPage('Section tart'), $recipes, 1);
        $this->under($this->createPage('Section tart crust'), $tart, 1);
        $other = $this->createPage('Section elsewhere');

        $screen = $this->listing(['inside' => (string) $recipes->getId()]);

        self::assertSame([(int) $tart->getId(), (int) $soup->getId()], $this->rowIds($screen), 'Its own pages only, by position: neither the page itself, nor a page further down, nor another one.');
        self::assertNotContains((int) $other->getId(), $this->rowIds($screen));
        self::assertSame(0, $screen->filter('[data-testid="cms-pages-flat-list"]')->count(), 'Not a list of results: the head names the page.');
    }

    public function testTheHeadLeadsBackUpAndStartsAPageUnderIt(): void
    {
        $recipes = $this->createPage('Trail recipes');
        $desserts = $this->under($this->createPage('Trail desserts'), $recipes, 1);
        $this->under($this->createPage('Trail tart'), $desserts, 1);

        $head = $this->listing(['inside' => (string) $desserts->getId(), 'open' => (string) $recipes->getId()])->filter('[data-testid="cms-pages-section"]');

        self::assertSame(1, $head->count());
        self::assertStringContainsString('Trail desserts', $head->filter('.breadcrumb-item.active')->text());
        self::assertSame(['open' => (string) $recipes->getId()], $this->queryOf((string) $head->filter('[data-testid="cms-pages-section-all"]')->attr('href')), 'All pages is the tree, as it was left.');

        $up = $head->filter('.breadcrumb-item a')->reduce(static fn (Crawler $link): bool => 'Trail recipes' === trim($link->text()));
        self::assertSame(1, $up->count(), 'Each page above is a step back.');
        self::assertSame((string) $recipes->getId(), $this->queryOf((string) $up->attr('href'))['inside'] ?? null);

        $create = (string) $head->filter('[data-testid="cms-pages-section-create"]')->attr('href');
        self::assertSame((string) $desserts->getId(), $this->queryOf($create)['parent'] ?? null);

        $form = $this->screen($create);
        self::assertSame((string) $desserts->getId(), $form->filter('select[name$="[parent]"] option[selected]')->attr('value'), 'The new page starts under it.');
    }

    public function testAPageThatHoldsOthersOpensTheirListFromTheTreeAndFromResults(): void
    {
        $recipes = $this->createPage('Entry recipes');
        $tart = $this->under($this->createPage('Entry tart'), $recipes, 1);
        $this->under($this->createPage('Entry crust'), $tart, 1);

        $tree = $this->listing(['open' => (string) $recipes->getId()]);
        $count = $tree->filter(\sprintf('[data-testid="cms-page-children-%d"]', $recipes->getId()));

        self::assertSame(1, $count->count(), 'The number of its pages is a link.');
        self::assertSame((string) $recipes->getId(), $this->queryOf((string) $count->attr('href'))['inside'] ?? null);
        self::assertSame(0, $tree->filter(\sprintf('[data-testid="cms-page-children-%d"]', $this->createPage('Entry alone')->getId()))->count());

        $results = $this->listing(['q' => 'Entry crust']);
        $above = $results->filter('[data-testid^="cms-page-ancestors-"] a');

        self::assertSame(['Entry recipes', 'Entry tart'], $above->each(static fn (Crawler $link): string => trim($link->text())), 'Each page above a result is a list of its own pages.');
        self::assertSame((string) $tart->getId(), $this->queryOf((string) $above->last()->attr('href'))['inside'] ?? null);
    }

    public function testASearchInsideAPageStaysThereUntilItsChipIsRemoved(): void
    {
        $recipes = $this->createPage('Searched recipes');
        $soup = $this->under($this->createPage('Searched soup'), $recipes, 1);
        $this->under($this->createPage('Searched tart'), $recipes, 2);
        $this->createPage('Searched soup elsewhere');

        $screen = $this->listing(['inside' => (string) $recipes->getId(), 'q' => 'soup']);

        self::assertSame([(int) $soup->getId()], $this->rowIds($screen));
        self::assertSame((string) $recipes->getId(), $screen->filter('form input[type="hidden"][name="inside"]')->attr('value'), 'The search box keeps the page.');

        $chip = $screen->filter('[data-testid="cms-pages-active-filters"] a')->reduce(static fn (Crawler $link): bool => str_contains($link->text(), 'Searched recipes'));
        self::assertSame(1, $chip->count());
        self::assertSame(['q' => 'soup'], $this->queryOf((string) $chip->attr('href')), 'Removed, the search looks through the whole site.');
    }

    public function testThePagesOfAPageMoveAmongThemselvesAndTheListComesBack(): void
    {
        $recipes = $this->createPage('Moved recipes');
        $first = $this->under($this->createPage('Moved first'), $recipes, 1);
        $second = $this->under($this->createPage('Moved second'), $recipes, 2);
        $inside = ['inside' => (string) $recipes->getId()];

        $screen = $this->listing($inside);

        self::assertTrue($this->moveButton($screen, $first, 'up')->matches('[disabled]'), 'The first cannot go up.');
        self::assertFalse($this->moveButton($screen, $first, 'down')->matches('[disabled]'));
        self::assertTrue($this->moveButton($screen, $second, 'down')->matches('[disabled]'), 'The last cannot go down.');

        $action = (string) $this->moveButton($screen, $second, 'up')->closest('form')?->attr('action');
        $this->send('POST', $action, ['_token' => $this->tokenOfTheForm($screen, (string) parse_url($action, \PHP_URL_PATH))]);

        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertSame((string) $recipes->getId(), $this->queryOf((string) $this->client->getResponse()->headers->get('Location'))['inside'] ?? null, 'Back to the pages of the page.');
        self::assertSame([(int) $second->getId(), (int) $first->getId()], $this->rowIds($this->listing($inside)));

        self::assertSame(0, $this->listing([...$inside, 'q' => 'Moved'])->filter('form[action*="/move/"]')->count(), 'A search among them is not their order: no move.');
    }

    public function testAPageThatCannotHoldPagesIsNoPlaceToList(): void
    {
        $gone = $this->createPage('Section gone');
        $goneId = (int) $gone->getId();
        $gone->setDeletedAt(new \DateTimeImmutable())->save();

        foreach (['999999999', (string) $goneId] as $inside) {
            $screen = $this->listing(['inside' => $inside]);

            self::assertSame(0, $screen->filter('[data-testid="cms-pages-section"]')->count(), $inside);
            self::assertSame(0, $screen->filter('[data-testid="cms-pages-active-filters"]')->count(), 'The tree, as if nothing was asked.');
        }

        $form = $this->screen('/admin/cms/pages/new?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), 'parent' => $goneId]));
        self::assertSame('0', $form->filter('select[name$="[parent]"] option[selected]')->attr('value') ?? '0', 'A new page does not start under a page in the bin.');
    }

    private function under(CmsPage $page, CmsPage $parent, int $position): CmsPage
    {
        $page->setParent((int) $parent->getId())->setPosition($position)->save();

        return $page;
    }

    private function moveButton(Crawler $screen, CmsPage $page, string $direction): Crawler
    {
        $button = $screen->filter(\sprintf('[data-testid="cms-page-row-%d"] form[action*="/move/%s"] button', $page->getId(), $direction));

        self::assertSame(1, $button->count(), \sprintf('No %s button on the row of %s.', $direction, $page->getTitle()));

        return $button;
    }

    /**
     * @return list<int>
     */
    private function rowIds(Crawler $screen): array
    {
        return $screen->filter('tr[data-row-id]')->each(static fn (Crawler $row): int => (int) $row->attr('data-row-id'));
    }

    /**
     * @return array<string, mixed> the query of an address, the language left out
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        unset($query['edit_language_id']);

        return $query;
    }

    /**
     * @param array<string, string> $query
     */
    private function listing(array $query): Crawler
    {
        return $this->screen('/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), ...$query]));
    }
}
