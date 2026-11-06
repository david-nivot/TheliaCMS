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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\Lang;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\PageDate;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The list of pages keeps the pages of a period, on the date the editor
 * picks, both days included, and the pages an administrator changed last.
 */
final class PageListPeriodAuthorTest extends AdminScreenTestCase
{
    #[DataProvider('dates')]
    public function testEachDateKeepsItsPeriodBothDaysIncluded(PageDate $date): void
    {
        $before = $this->dated($this->createPage('Periodfilter before'), $date, '2026-01-31 23:59:59');
        $first = $this->dated($this->createPage('Periodfilter first'), $date, '2026-02-01 00:00:00');
        $last = $this->dated($this->createPage('Periodfilter last'), $date, '2026-02-10 23:59:59');
        $after = $this->dated($this->createPage('Periodfilter after'), $date, '2026-02-11 00:00:00');

        self::assertSame(
            [$first, $last],
            $this->idsOf(new PageFilters(search: 'Periodfilter', date: $date, from: new \DateTimeImmutable('2026-02-01'), to: new \DateTimeImmutable('2026-02-10'))),
            'The two days are kept whole, the days around them are not.',
        );
        self::assertSame([$first, $last, $after], $this->idsOf(new PageFilters(search: 'Periodfilter', date: $date, from: new \DateTimeImmutable('2026-02-01'))), 'From a day on.');
        self::assertSame([$before, $first, $last], $this->idsOf(new PageFilters(search: 'Periodfilter', date: $date, to: new \DateTimeImmutable('2026-02-10'))), 'Up to a day.');
    }

    /**
     * @return iterable<string, array{PageDate}>
     */
    public static function dates(): iterable
    {
        foreach (PageDate::cases() as $date) {
            yield $date->value => [$date];
        }
    }

    public function testTheLastChangeIsTheOneOfTheContentWhenItIsTheLatest(): void
    {
        $page = $this->createPage('Periodfilter content');
        $id = (int) $page->getId();
        CmsPageQuery::create()->filterById($id)->update(['UpdatedAt' => '2025-06-01 10:00:00']);
        CmsPageContentQuery::create()->filterByPageId($id)->update(['UpdatedAt' => '2026-02-05 10:00:00']);

        self::assertSame([$id], $this->idsOf(new PageFilters(search: 'Periodfilter', date: PageDate::Modified, from: new \DateTimeImmutable('2026-02-01'))));
    }

    public function testThePublicationIsTheOneOfTheLanguageListed(): void
    {
        $page = $this->createPage('Periodfilter language');
        $id = (int) $page->getId();
        CmsPageContentQuery::create()->filterByPageId($id)->update(['PublishedAt' => '2025-06-01 10:00:00']);
        $this->getPropelConnection()->prepare("INSERT INTO cms_page_content (page_id, locale, published_at, created_at, updated_at) VALUES (?, 'xx_XX', '2026-02-05 10:00:00', NOW(), NOW())")->execute([$id]);

        self::assertSame([], $this->idsOf(new PageFilters(search: 'Periodfilter', date: PageDate::Published, from: new \DateTimeImmutable('2026-02-01'))));
    }

    public function testAnAuthorKeepsThePagesTheyChangedLast(): void
    {
        $fixtures = new FixtureFactory($this->getPropelConnection());
        $jeanne = (int) $fixtures->admin(['firstname' => 'Jeanne', 'lastname' => 'Martin'])->getId();
        $paul = (int) $fixtures->admin(['firstname' => 'Paul', 'lastname' => 'Durand'])->getId();
        $hers = (int) $this->changedBy($this->createPage('Authorfilter hers'), $jeanne)->getId();
        $this->changedBy($this->createPage('Authorfilter his'), $paul);
        $this->createPage('Authorfilter nobody');

        self::assertSame([$hers], $this->idsOf(new PageFilters(search: 'Authorfilter', author: $jeanne)));

        $authors = $this->getService(CmsPageAdminRepository::class)->authors();
        self::assertSame('Jeanne Martin', $authors[$jeanne] ?? null);
        self::assertSame('Paul Durand', $authors[$paul] ?? null);
    }

    public function testTheScreenOffersThePeriodAndTheAuthorsAndNamesThemInChips(): void
    {
        $fixtures = new FixtureFactory($this->getPropelConnection());
        $jeanne = (int) $fixtures->admin(['firstname' => 'Jeanne', 'lastname' => 'Martin'])->getId();
        $gone = $fixtures->admin(['firstname' => 'Gone', 'lastname' => 'Away']);
        $goneId = (int) $gone->getId();
        $kept = $this->dated($this->changedBy($this->createPage('Screenperiod kept'), $jeanne), PageDate::Created, '2026-02-03 12:00:00');
        $other = $this->dated($this->changedBy($this->createPage('Screenperiod other'), $goneId), PageDate::Created, '2026-02-03 12:00:00');
        $gone->delete();

        $screen = $this->listing(['date' => 'created', 'from' => '2026-02-01', 'to' => '2026-02-10', 'author' => (string) $jeanne]);

        self::assertSame('created', $screen->filter('#cms-pages-date option[selected]')->attr('value'));
        self::assertSame('2026-02-01', $screen->filter('#cms-pages-from')->attr('value'));
        self::assertSame('2026-02-10', $screen->filter('#cms-pages-to')->attr('value'));
        self::assertSame((string) $jeanne, $screen->filter('#cms-pages-author option[selected]')->attr('value'));
        foreach (['date', 'from', 'to', 'author'] as $control) {
            self::assertSame(1, $screen->filter(\sprintf('label[for="cms-pages-%s"]', $control))->count(), \sprintf('The %s control has its label.', $control));
        }

        $options = $screen->filter('#cms-pages-author option')->each(static fn (Crawler $option): string => trim($option->text()));
        self::assertContains('Jeanne Martin', $options);
        self::assertContains(\sprintf('Deleted account #%d', $goneId), $options, 'An account deleted since is named by its number.');

        self::assertSame(1, $screen->filter(\sprintf('[data-testid="cms-page-row-%d"]', $kept))->count());
        self::assertSame(0, $screen->filter(\sprintf('[data-testid="cms-page-row-%d"]', $other))->count());

        $chips = $this->chips($screen);
        self::assertArrayHasKey('Creation date: from 01/02/2026 to 10/02/2026', $chips);
        self::assertArrayHasKey('Last changed by Jeanne Martin', $chips);

        // Removing one chip keeps the other.
        parse_str((string) parse_url($chips['Last changed by Jeanne Martin'], \PHP_URL_QUERY), $withoutAuthor);
        self::assertArrayNotHasKey('author', $withoutAuthor);
        self::assertSame(['created', '2026-02-01', '2026-02-10'], [$withoutAuthor['date'] ?? null, $withoutAuthor['from'] ?? null, $withoutAuthor['to'] ?? null]);
    }

    public function testADeletedAccountStillFiltersItsPages(): void
    {
        $gone = (new FixtureFactory($this->getPropelConnection()))->admin();
        $goneId = (int) $gone->getId();
        $page = (int) $this->changedBy($this->createPage('Goneauthor page'), $goneId)->getId();
        $gone->delete();

        $screen = $this->listing(['q' => 'Goneauthor', 'author' => (string) $goneId]);

        self::assertSame(1, $screen->filter(\sprintf('[data-testid="cms-page-row-%d"]', $page))->count());
        self::assertArrayHasKey(\sprintf('Last changed by Deleted account #%d', $goneId), $this->chips($screen));
    }

    public function testAnUnknownAuthorAndADayThatIsNoneAreIgnored(): void
    {
        $screen = $this->listing(['author' => '999999999', 'from' => '2026-02-31']);

        self::assertSame(0, $screen->filter('[data-testid="cms-pages-active-filters"]')->count(), 'No chip names a filter that is not on.');
        self::assertSame(0, $screen->filter('[data-testid="cms-pages-flat-list"]')->count(), 'The tree stays.');
        self::assertSame('', (string) $screen->filter('#cms-pages-from')->attr('value'));
    }

    /**
     * @return list<int>
     */
    private function idsOf(PageFilters $filters): array
    {
        $results = $this->getService(CmsPageAdminRepository::class)->results($this->locale(), $filters);

        $ids = array_map(static fn ($row): int => $row->id(), $results->rows);
        sort($ids);

        return $ids;
    }

    /**
     * The page carries this date, and nothing else of it falls in a period
     * of the test: the last change of its content is put far back.
     */
    private function dated(CmsPage $page, PageDate $date, string $at): int
    {
        $id = (int) $page->getId();
        $content = CmsPageContentQuery::create()->filterByPageId($id)->filterByLocale($this->locale());

        match ($date) {
            PageDate::Modified => CmsPageQuery::create()->filterById($id)->update(['UpdatedAt' => $at]),
            PageDate::Created => CmsPageQuery::create()->filterById($id)->update(['CreatedAt' => $at]),
            PageDate::Published => $content->update(['PublishedAt' => $at]),
            PageDate::PublishOn => CmsPageQuery::create()->filterById($id)->update(['PublishAt' => $at]),
            PageDate::UnpublishOn => CmsPageQuery::create()->filterById($id)->update(['UnpublishAt' => $at]),
        };

        if (PageDate::Modified === $date) {
            CmsPageContentQuery::create()->filterByPageId($id)->update(['UpdatedAt' => '2000-01-01 00:00:00']);
        }

        return $id;
    }

    private function changedBy(CmsPage $page, int $adminId): CmsPage
    {
        CmsPageQuery::create()->filterById($page->getId())->update(['UpdatedBy' => $adminId]);

        return $page;
    }

    /**
     * @param array<string, string> $query
     */
    private function listing(array $query): Crawler
    {
        return $this->screen('/admin/cms/pages?'.http_build_query(['edit_language_id' => Lang::getDefaultLanguage()->getId(), ...$query]));
    }

    /**
     * @return array<string, string> the address removing each chip, by label
     */
    private function chips(Crawler $screen): array
    {
        $chips = [];

        $screen->filter('[data-testid="cms-pages-active-filters"] a')->each(static function (Crawler $chip) use (&$chips): void {
            $label = trim($chip->filter('.visually-hidden')->count() > 0
                ? str_replace($chip->filter('.visually-hidden')->text(), '', $chip->text())
                : $chip->text());
            $chips[$label] = (string) $chip->attr('href');
        });

        return $chips;
    }
}
