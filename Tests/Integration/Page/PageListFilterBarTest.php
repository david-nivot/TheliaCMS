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
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The filters of the list of pages sit on one line: the search, a menu for the
 * publication, the selects, a menu for the period, and the sort. A menu is a
 * `<details>`, so it opens without a script, and its button counts the filters
 * folded in it.
 */
final class PageListFilterBarTest extends AdminScreenTestCase
{
    private const string BAR = '[data-testid="cms-pages-filter-bar"]';

    public function testEveryControlSitsInTheOneLineOfTheBar(): void
    {
        (new CmsTag())->setName('Filterbar news')->save();
        $this->createPage('Filterbar page');

        $screen = $this->listing([]);
        $bar = $screen->filter(self::BAR);

        self::assertSame(1, $bar->count(), 'The filters have one line.');
        foreach (['#cms-pages-search', '#cms-pages-tag', '#cms-pages-sort', '#cms-pages-visibility', '#cms-pages-date', '#cms-pages-from', '#cms-pages-to'] as $control) {
            self::assertSame(1, $bar->filter($control)->count(), \sprintf('%s is in the line of the filters.', $control));
        }
        self::assertSame(5, $bar->filter('[data-testid="cms-pages-status-filters"] input[type="checkbox"]')->count(), 'Every state of publication can be chosen.');
        self::assertSame(1, $bar->filter('[data-testid="cms-pages-search-submit"]')->count(), 'The line sends the search.');
        self::assertSame(1, $screen->filter('label[for="cms-pages-sort"]')->count(), 'The sort keeps its label, read out if not shown.');
        self::assertSame(1, $screen->filter('script[src*="page-filters.js"]')->count(), 'The script that closes the menus is loaded.');
    }

    public function testThePublicationAndThePeriodFoldIntoMenusThatOpenWithoutAScript(): void
    {
        $this->createPage('Filterbar page');

        $bar = $this->listing([])->filter(self::BAR);
        $status = $bar->filter('details[data-testid="cms-pages-status-menu"]');
        $period = $bar->filter('details[data-testid="cms-pages-period-menu"]');

        self::assertSame(1, $status->filter('summary')->count(), 'The publication is a menu opened by its button.');
        self::assertSame(1, $status->filter('[data-testid="cms-pages-status-filters"]')->count(), 'The states of publication are in its menu.');
        self::assertSame(1, $status->filter('#cms-pages-visibility')->count(), 'Shown on the site or not is in the same menu.');
        self::assertSame(1, $status->filter('button[type="submit"]')->count(), 'The menu applies what is chosen in it.');
        self::assertSame(1, $period->filter('summary')->count(), 'The period is a menu opened by its button.');
        self::assertSame(3, $period->filter('#cms-pages-date, #cms-pages-from, #cms-pages-to')->count(), 'The date and both days are in its menu.');
        self::assertNull($status->attr('open'), 'A menu is closed when the screen opens.');
        self::assertSame(0, $bar->filter('[data-testid="cms-pages-status-menu-count"], [data-testid="cms-pages-period-menu-count"]')->count(), 'Nothing is counted while nothing is on.');
    }

    public function testTheButtonOfAMenuCountsTheFiltersFoldedInIt(): void
    {
        $this->createPage('Filterbar page');

        $screen = $this->listing(['status' => ['draft', 'published'], 'visibility' => 'offline', 'from' => '2026-02-01']);

        self::assertSame('3', trim($screen->filter('[data-testid="cms-pages-status-menu-count"]')->text()), 'Two states and the visibility are on in the publication menu.');
        self::assertSame('1', trim($screen->filter('[data-testid="cms-pages-period-menu-count"]')->text()), 'The period is on.');
        self::assertSame(['draft', 'published'], $screen->filter('[data-testid="cms-pages-status-filters"] input[checked]')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertSame(1, $screen->filter('[data-testid="cms-pages-active-filters"] [data-testid="cms-pages-reset"]')->count(), 'Everything is cleared from the line of the chips.');
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private function listing(array $query): Crawler
    {
        return $this->screen('/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), ...$query]));
    }
}
