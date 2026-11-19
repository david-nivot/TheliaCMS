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

use TheliaCMS\Content\PropType;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\PageListQuery;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Partial\Catalogue\PickablePages;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Partial\PartialRenderer;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The list of pages set under a page: the news under "News", the articles of
 * a rubric, right under it or at any depth.
 */
final class PageListUnderPageTest extends CmsIntegrationTestCase
{
    public function testTheListHoldsThePagesRightUnderItsPage(): void
    {
        [$news] = $this->newsSection();

        self::assertSame(['2025', '2026', 'Short news'], $this->titles($news, ['sort' => PageListQuery::SORT_TITLE]), 'The pages right under it, not the ones under them.');
    }

    public function testTheListTakesTheWholeBranchWhenAsked(): void
    {
        [$news] = $this->newsSection();

        self::assertSame(
            ['2025', '2026', 'Autumn rides', 'Bike fair', 'New lanes', 'Short news', 'Spring rally'],
            $this->titles($news, ['scope' => PageListPartial::SCOPE_BRANCH, 'sort' => PageListQuery::SORT_TITLE]),
        );
    }

    public function testATemplateKeepsOnlyItsPagesInTheBranch(): void
    {
        [$news] = $this->newsSection();

        self::assertSame(
            ['Autumn rides', 'Bike fair', 'Spring rally'],
            $this->titles($news, ['scope' => PageListPartial::SCOPE_BRANCH, 'type' => 'news-item', 'sort' => PageListQuery::SORT_TITLE]),
            'The year pages and the short news are of the default template: left out.',
        );
    }

    public function testAPageOfflineOrInTheBinOrUnpublishedInTheLanguageIsLeftOut(): void
    {
        [$news, $year] = $this->newsSection();
        $this->createPage('Hidden event', parent: (int) $year->getId(), visible: false);
        $this->createPage('Draft event', parent: (int) $year->getId(), published: false);
        $this->createPage('English only', locale: 'en_US' === $this->locale() ? 'fr_FR' : 'en_US', parent: (int) $year->getId());
        $binned = $this->createPage('Binned event', parent: (int) $year->getId());
        $this->writer()->moveToTrash($binned);

        self::assertNotContains('Hidden event', $this->titles($news, ['scope' => PageListPartial::SCOPE_BRANCH]));
        self::assertSame(
            ['Autumn rides', 'Spring rally'],
            $this->titles($year, ['sort' => PageListQuery::SORT_TITLE, 'type' => PageListPartial::ANY_TYPE]),
        );
    }

    public function testAPageUnderAnOfflinePageIsStillListed(): void
    {
        $section = $this->createPage('Guides');
        $hidden = $this->createPage('Old rubric', parent: (int) $section->getId(), visible: false);
        $this->createPage('Still read', parent: (int) $hidden->getId());

        self::assertSame(['Still read'], $this->titles($section, ['scope' => PageListPartial::SCOPE_BRANCH]));
    }

    public function testTheOrderAndTheNumberOfPagesStillApply(): void
    {
        [$news] = $this->newsSection();

        self::assertSame(['Spring rally', 'Short news'], $this->titles($news, ['scope' => PageListPartial::SCOPE_BRANCH, 'sort' => PageListQuery::SORT_TITLE, 'direction' => 'desc', 'limit' => 2]));
    }

    public function testAListWithoutPageKeepsListingTheTemplate(): void
    {
        $this->newsSection();

        $titles = array_map(static fn ($card): string => $card->title, $this->getService(PublishedPageRepository::class)->ofType('news-item', $this->locale(), new PageListQuery(sort: PageListQuery::SORT_TITLE)));

        self::assertSame(['Autumn rides', 'Bike fair', 'Spring rally'], $titles);
        self::assertStringContainsString('Spring rally', $this->rendered(['type' => 'news-item']), 'A block saved before the page setting existed lists as before.');
    }

    public function testThePageIsPickedFromASearchOfThePages(): void
    {
        [$news, $year] = $this->newsSection();
        $props = $this->getService(SectionCatalog::class)->find('Partial:'.PageListPartial::NAME)?->schema()->props ?? [];
        $parent = array_values(array_filter($props, static fn ($prop): bool => 'parent' === $prop->name))[0] ?? null;

        self::assertNotNull($parent);
        self::assertSame([PropType::Reference, 'page', '/admin/cms/partials/sources/pages', true, false], [$parent->type, $parent->record, $parent->picker, $parent->searchable, $parent->required]);

        $found = $this->getService(PickablePages::class)->search('2026', $this->locale());
        self::assertSame(
            ['2026 (News)', 'Autumn rides (News › 2026)', 'Spring rally (News › 2026)'],
            array_map(static fn ($item): string => $item->name, $found),
            'A page is named with the pages above it, and brings the pages under it, each under its parent.',
        );
        self::assertSame((int) $year->getId(), $found[0]->id);
        self::assertSame('News', $this->getService(PickablePages::class)->find((int) $news->getId(), $this->locale())?->name);
    }

    /**
     * News > 2025 > Bike fair, News > 2026 > Spring rally and Autumn rides,
     * News > Short news > New lanes. The events are of the template
     * `news-item`, the other pages of the default one.
     *
     * @return array{CmsPage, CmsPage}
     */
    private function newsSection(): array
    {
        if (!$this->getService(PageTypeRepository::class)->exists('news-item')) {
            $this->getService(PageTypeWriter::class)->add('news-item');
        }

        $news = $this->createPage('News');
        $older = $this->createPage('2025', parent: (int) $news->getId());
        $year = $this->createPage('2026', parent: (int) $news->getId());
        $short = $this->createPage('Short news', parent: (int) $news->getId());

        foreach (['Bike fair' => $older, 'Spring rally' => $year, 'Autumn rides' => $year] as $title => $parent) {
            $this->createPage($title, parent: (int) $parent->getId())->setPageType('news-item')->save();
        }

        $this->createPage('New lanes', parent: (int) $short->getId());
        $this->createPage('Elsewhere');

        return [$news, $year];
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return list<string>
     */
    private function titles(CmsPage $parent, array $props): array
    {
        preg_match_all('~class="cms-page-card__link"[^>]*>([^<]+)</a>~', $this->rendered(['parent' => (int) $parent->getId(), 'type' => PageListPartial::ANY_TYPE, ...$props]), $matches);

        return array_map(static fn (string $title): string => html_entity_decode($title, \ENT_QUOTES), $matches[1]);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function rendered(array $props): string
    {
        $section = $this->getService(SectionCatalog::class)->find('Partial:'.PageListPartial::NAME);
        $normalized = $section?->normalizeProps(['limit' => 48, ...$props]) ?? [];

        return $this->getService(PartialRenderer::class)->renderOne(PageListPartial::NAME, $normalized, $this->locale(), cache: false);
    }
}
