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
use TheliaCMS\Model\CmsPageTypeDefinition;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Page\Admin\PageListPresenter;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The list of pages keeps the pages of one tag (or those without one) and of
 * one page type, as a flat list of results with a chip for each.
 */
final class PageListTagTypeFilterTest extends AdminScreenTestCase
{
    public function testATagKeepsItsPagesOnly(): void
    {
        $news = $this->givenTag('Filter news');
        $events = $this->givenTag('Filter events');
        $tagged = $this->tagged($this->createPage('Tagfilter launch'), $news);
        $other = $this->tagged($this->createPage('Tagfilter party'), $events);
        $untagged = $this->createPage('Tagfilter plain');

        $shown = $this->idsOf(new PageFilters(search: 'Tagfilter', tag: (int) $news->getId()));

        self::assertSame([(int) $tagged->getId()], $shown, 'Only the pages of the tag are kept.');
        self::assertNotContains((int) $other->getId(), $shown);
        self::assertNotContains((int) $untagged->getId(), $shown);
    }

    public function testNoTagKeepsThePagesWithoutOne(): void
    {
        $news = $this->givenTag('Filter news');
        $this->tagged($this->createPage('Tagfilter launch'), $news);
        $untagged = $this->createPage('Tagfilter plain');

        self::assertSame(
            [(int) $untagged->getId()],
            $this->idsOf(new PageFilters(search: 'Tagfilter', tag: PageFilters::NO_TAG)),
            'Only the pages without a tag are kept.',
        );
    }

    public function testATypeKeepsItsPagesOnly(): void
    {
        $this->givenType('filter-recipe', 'Filter recipe');
        $recipe = $this->typed($this->createPage('Typefilter soup'), 'filter-recipe');
        $plain = $this->createPage('Typefilter about');

        $shown = $this->idsOf(new PageFilters(search: 'Typefilter', type: 'filter-recipe'));

        self::assertSame([(int) $recipe->getId()], $shown, 'Only the pages of the type are kept.');
        self::assertSame([(int) $plain->getId()], $this->idsOf(new PageFilters(search: 'Typefilter', type: 'default')));
    }

    public function testTheScreenOffersBothSelectsAndNamesWhatIsOnInChips(): void
    {
        $news = $this->givenTag('Filter news');
        $this->givenType('filter-recipe', 'Filter recipe');
        $page = $this->typed($this->tagged($this->createPage('Screenfilter soup'), $news), 'filter-recipe');
        $plain = $this->createPage('Screenfilter about');

        $screen = $this->listing(['tag' => (string) $news->getId(), 'type' => 'filter-recipe']);

        self::assertSame((string) $news->getId(), $screen->filter('#cms-pages-tag option[selected]')->attr('value'), 'The tag select shows the tag kept.');
        self::assertSame('filter-recipe', $screen->filter('#cms-pages-type option[selected]')->attr('value'), 'The type select shows the type kept.');
        self::assertSame(1, $screen->filter('label[for="cms-pages-tag"]')->count(), 'The tag select has its label.');
        self::assertSame(1, $screen->filter('label[for="cms-pages-type"]')->count(), 'The type select has its label.');

        self::assertSame(1, $screen->filter(\sprintf('[data-testid="cms-page-row-%d"]', $page->getId()))->count());
        self::assertSame(0, $screen->filter(\sprintf('[data-testid="cms-page-row-%d"]', $plain->getId()))->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-pages-flat-list"]')->count(), 'The result line says the tree is off.');

        $chips = $this->chips($screen);
        self::assertArrayHasKey('Tag: Filter news', $chips);
        self::assertArrayHasKey('Type: Filter recipe', $chips);

        // Removing one chip keeps the other.
        parse_str((string) parse_url($chips['Tag: Filter news'], \PHP_URL_QUERY), $withoutTag);
        self::assertArrayNotHasKey('tag', $withoutTag);
        self::assertSame('filter-recipe', $withoutTag['type'] ?? null);
    }

    public function testThePagesWithoutATagHaveTheirChip(): void
    {
        $this->givenTag('Filter news');

        $screen = $this->listing(['tag' => 'none']);

        self::assertSame('none', $screen->filter('#cms-pages-tag option[selected]')->attr('value'));
        self::assertArrayHasKey('Without a tag', $this->chips($screen));
    }

    public function testATagOrATypeTheSiteDoesNotHaveIsIgnored(): void
    {
        $news = $this->givenTag('Filter news');
        $news->delete();

        $screen = $this->listing(['tag' => (string) $news->getId(), 'type' => 'no-such-type']);

        self::assertSame(0, $screen->filter('[data-testid="cms-pages-active-filters"]')->count(), 'No chip names a filter that filters nothing.');
        self::assertSame(0, $screen->filter('[data-testid="cms-pages-result-count"]')->count(), 'The listing stays the tree.');
    }

    public function testTheRowActionsKeepTheTagAndTheType(): void
    {
        $news = $this->givenTag('Filter news');
        $page = $this->tagged($this->createPage('Rowfilter soup'), $news);

        $row = $this->listing(['tag' => (string) $news->getId(), 'type' => 'default'])
            ->filter(\sprintf('[data-testid="cms-page-row-%d"]', $page->getId()));
        self::assertSame(1, $row->count());

        $forms = $row->filter('form')->each(static fn (Crawler $form): string => (string) $form->attr('action'));
        self::assertNotEmpty($forms, 'The row has actions.');

        foreach ($forms as $action) {
            parse_str((string) parse_url($action, \PHP_URL_QUERY), $query);
            self::assertSame((string) $news->getId(), $query['tag'] ?? null, \sprintf('%s drops the tag.', $action));
            self::assertSame('default', $query['type'] ?? null, \sprintf('%s drops the type.', $action));
        }
    }

    public function testThePagesOfResultsKeepTheTagAndTheType(): void
    {
        $news = $this->givenTag('Filter news');

        // What the pagination of the listing builds its links from.
        $context = $this->getService(PageListPresenter::class)->present(
            new PageFilters(tag: (int) $news->getId(), type: 'default'),
            Lang::getDefaultLanguage(),
        );

        self::assertSame((string) $news->getId(), $context['filter_query']['tag'] ?? null);
        self::assertSame('default', $context['filter_query']['type'] ?? null);
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

    private function givenTag(string $name): CmsTag
    {
        $tag = (new CmsTag())->setName($name);
        $tag->save();

        return $tag;
    }

    private function givenType(string $code, string $title): void
    {
        (new CmsPageTypeDefinition())
            ->setCode($code)
            ->setLocale($this->locale())
            ->setTitle($title)
            ->save();
    }

    private function tagged(CmsPage $page, CmsTag $tag): CmsPage
    {
        $page->setTagId((int) $tag->getId())->save();

        return $page;
    }

    private function typed(CmsPage $page, string $code): CmsPage
    {
        $page->setPageType($code)->save();

        return $page;
    }
}
