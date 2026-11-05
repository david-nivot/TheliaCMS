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

namespace TheliaCMS\Tests\Integration\Dashboard;

use Thelia\Model\LangQuery;
use TheliaCMS\Dashboard\ShowcaseStats;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The figures on the dashboard of a showcase site. They are counts over the
 * real tables, and the only thing worth checking is that they count the right
 * rows: a dashboard that is quietly wrong is worse than no dashboard.
 */
final class ShowcaseStatsTest extends CmsIntegrationTestCase
{
    public function testPublishingAPageMovesItFromTheDraftsToThePublished(): void
    {
        $before = $this->collect();

        $page = $this->createPage('Page à publier', published: false);
        $withDraft = $this->collect();

        self::assertSame($before['pages_draft'] + 1, $withDraft['pages_draft']);
        self::assertSame($before['pages_published'], $withDraft['pages_published']);

        $this->writer()->publish($page, $this->locale());
        $after = $this->collect();

        self::assertSame($before['pages_published'] + 1, $after['pages_published']);
        self::assertSame($before['pages_draft'], $after['pages_draft']);
    }

    public function testAPageInTheBinIsCountedNowhere(): void
    {
        $before = $this->collect();
        $page = $this->createPage('Page à jeter');

        $this->writer()->moveToTrash($page);
        $after = $this->collect();

        self::assertSame($before['pages_published'], $after['pages_published']);
        self::assertSame($before['pages_draft'], $after['pages_draft']);
    }

    public function testTheLastEditedPageComesFirst(): void
    {
        $this->createPage('Page modifiée en dernier');

        $recent = $this->collect()['recent_pages'];

        self::assertNotSame([], $recent);
        self::assertSame('Page modifiée en dernier', $recent[0]['title']);
    }

    public function testEachActiveLanguageHasItsRowOfPublishedAndTotalPages(): void
    {
        $rows = [];

        foreach ($this->collect()['completeness'] as $row) {
            $rows[$row['locale']] = $row;
        }

        $locales = array_map(strval(...), LangQuery::create()->filterByActive(true)->select(['Locale'])->find()->getData());
        self::assertEqualsCanonicalizing($locales, array_keys($rows), 'A row for each active language.');

        $before = $rows[$this->locale()];
        $page = $this->createPage('Page à compter', published: false);
        $this->writer()->publish($page, $this->locale());

        $after = [];

        foreach ($this->collect()['completeness'] as $row) {
            $after[$row['locale']] = $row;
        }

        self::assertSame($before['total'] + 1, $after[$this->locale()]['total']);
        self::assertSame($before['published'] + 1, $after[$this->locale()]['published']);

        foreach (array_diff($locales, [$this->locale()]) as $other) {
            self::assertSame($rows[$other]['total'] + 1, $after[$other]['total'], 'Every language counts the page in its total.');
            self::assertSame($rows[$other]['published'], $after[$other]['published'], 'Only the language it is published in counts it as published.');
        }
    }

    /**
     * Every figure costs at least one query on each opening of the dashboard,
     * so only the ones the dashboard shows are worked out.
     */
    public function testOnlyTheFiguresTheDashboardShowsAreWorkedOut(): void
    {
        $figures = array_keys($this->collect());
        sort($figures);

        self::assertSame(
            ['completeness', 'pages_draft', 'pages_published', 'recent_days', 'recent_pages', 'submissions_recent', 'submissions_total'],
            $figures,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        return $this->getService(ShowcaseStats::class)->collect($this->locale());
    }
}
