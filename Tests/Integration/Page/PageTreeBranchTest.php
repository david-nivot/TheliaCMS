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
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The tree keeps the editor where they were: a branch opened, a page moved or
 * hidden brings them back to its row, and a large branch shows its first pages
 * and leads to the list of all of them.
 */
final class PageTreeBranchTest extends AdminScreenTestCase
{
    public function testOpeningOrClosingABranchComesBackToItsRow(): void
    {
        $parent = $this->createPage('Anchor parent');
        $this->under($this->createPage('Anchor child'), $parent, 1);

        foreach ([[], ['open' => (string) $parent->getId()]] as $query) {
            $screen = $this->listing($query);
            $fold = (string) $screen->filter(\sprintf('[data-testid="cms-page-fold-%d"]', $parent->getId()))->attr('href');

            self::assertStringEndsWith('#cms-page-'.$parent->getId(), $fold, 'Opened or closed, the screen comes back to the row.');
            self::assertSame(1, $screen->filter('tr#cms-page-'.$parent->getId())->count(), 'The row is what the address points at.');
        }
    }

    public function testAPageMovedOrHiddenFromTheListComesBackToItsRow(): void
    {
        $parent = $this->createPage('Moved anchor parent');
        $first = $this->under($this->createPage('Moved anchor first'), $parent, 1);
        $this->under($this->createPage('Moved anchor second'), $parent, 2);
        $screen = $this->listing(['open' => (string) $parent->getId()]);

        foreach (['/move/down', '/visibility'] as $action) {
            $form = $screen->filter(\sprintf('[data-testid="cms-page-row-%d"] form', $first->getId()))->reduce(static fn (Crawler $form): bool => str_contains((string) $form->attr('action'), $action));
            $url = (string) $form->attr('action');

            $this->send('POST', $url, ['_token' => $this->tokenOfTheForm($screen, (string) parse_url($url, \PHP_URL_PATH))]);

            self::assertStringEndsWith('#cms-page-'.$first->getId(), (string) $this->client->getResponse()->headers->get('Location'), $action);
            self::assertStringContainsString('open='.$parent->getId(), (string) $this->client->getResponse()->headers->get('Location'), 'The branch stays open.');
        }
    }

    public function testALargeBranchShowsItsFirstPagesAndLeadsToAllOfThem(): void
    {
        $parent = $this->createPage('Large branch');
        $children = [];

        foreach (range(1, PageFilters::BRANCH_ROWS + 3) as $position) {
            $children[] = $this->under($this->createPage('Large branch child '.$position), $parent, $position);
        }

        $screen = $this->listing(['open' => (string) $parent->getId()]);
        $shown = $screen->filter('tr[data-row-id]')->each(static fn (Crawler $row): int => (int) $row->attr('data-row-id'));
        $kept = \array_slice($children, 0, PageFilters::BRANCH_ROWS);

        self::assertSame(array_map(static fn (CmsPage $page): int => (int) $page->getId(), $kept), array_values(array_intersect($shown, array_map(static fn (CmsPage $page): int => (int) $page->getId(), $children))), 'The first pages, by position.');

        self::assertStringContainsString((PageFilters::BRANCH_ROWS + 3).' pages', $screen->filter(\sprintf('[data-testid="cms-page-children-%d"]', $parent->getId()))->text(), 'The button names all of them, not the ones shown.');

        $more = $screen->filter(\sprintf('[data-testid="cms-page-more-%d"]', $parent->getId()));
        self::assertSame(1, $more->count());
        self::assertStringContainsString('3', $more->text(), 'It says how many are left out.');
        parse_str((string) parse_url((string) $more->filter('a')->attr('href'), \PHP_URL_QUERY), $query);
        self::assertSame((string) $parent->getId(), $query['inside'] ?? null, 'It leads to the list of all of them.');

        $last = $screen->filter(\sprintf('[data-testid="cms-page-row-%d"] form[action*="/move/down"] button', end($kept)->getId()));
        self::assertFalse($last->matches('[disabled]'), 'The last page shown still has pages after it.');
    }

    public function testABranchThatFitsShowsAllItsPagesAndNothingMore(): void
    {
        $parent = $this->createPage('Fitting branch');

        foreach (range(1, PageFilters::BRANCH_ROWS) as $position) {
            $this->under($this->createPage('Fitting branch child '.$position), $parent, $position);
        }

        $screen = $this->listing(['open' => (string) $parent->getId()]);

        self::assertSame(0, $screen->filter(\sprintf('[data-testid="cms-page-more-%d"]', $parent->getId()))->count());
        self::assertSame(PageFilters::BRANCH_ROWS, $screen->filter('tr[data-row-id]')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Fitting branch child'))->count());
    }

    private function under(CmsPage $page, CmsPage $parent, int $position): CmsPage
    {
        $page->setParent((int) $parent->getId())->setPosition($position)->save();

        return $page;
    }

    /**
     * @param array<string, string> $query
     */
    private function listing(array $query): Crawler
    {
        return $this->screen('/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), ...$query]));
    }
}
