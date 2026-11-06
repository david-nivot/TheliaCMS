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

namespace TheliaCMS\Tests\Unit\Page;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Page\Admin\PageStatus;

/**
 * What the page listing was asked to show, read from the address and written back
 * to it.
 */
final class PageFiltersTest extends TestCase
{
    public function testNothingAskedIsTheTree(): void
    {
        $filters = PageFilters::fromRequest(new Request());

        self::assertFalse($filters->isFiltering());
        self::assertTrue($filters->isEmpty());
        self::assertSame([], $filters->toQueryParams());
    }

    public function testASearchTermTurnsTheTreeIntoResults(): void
    {
        $filters = PageFilters::fromRequest(new Request(['q' => '  contact  ']));

        self::assertSame('contact', $filters->search);
        self::assertTrue($filters->isFiltering());
        self::assertSame(['q' => 'contact'], $filters->toQueryParams());
    }

    public function testAnUnfoldedBranchIsNotAFilter(): void
    {
        // Unfolding is a way of reading the tree, not a way of narrowing it: the
        // screen has to stay a tree, or the rows below the branch just opened
        // would come back without their parents.
        $filters = PageFilters::fromRequest(new Request(['open' => '4,9']));

        self::assertSame([4, 9], $filters->open);
        self::assertFalse($filters->isFiltering());
        self::assertFalse($filters->isEmpty());
    }

    public function testOnlyTheStatesTheModuleKnowsAreKept(): void
    {
        $filters = PageFilters::fromRequest(new Request(['status' => ['draft', 'nonsense', 'draft', 'published']]));

        self::assertSame([PageStatus::Draft, PageStatus::Published], $filters->statuses);
        self::assertSame(2, $filters->advancedCount());
    }

    #[DataProvider('visibilityCases')]
    public function testVisibilityReadsAsThreeStates(string $given, ?bool $expected): void
    {
        self::assertSame($expected, PageFilters::fromRequest(new Request(['visibility' => $given]))->visible);
    }

    /**
     * @return iterable<string, array{string, bool|null}>
     */
    public static function visibilityCases(): iterable
    {
        yield 'shown' => ['online', true];
        yield 'hidden' => ['offline', false];
        yield 'no preference' => ['', null];
        yield 'anything else' => ['maybe', null];
    }

    public function testOpenIdentifiersAreDigitsAndNothingElse(): void
    {
        $filters = PageFilters::fromRequest(new Request(['open' => '3, 0,-2,abc,7,3']));

        self::assertSame([3, 7], $filters->open);
    }

    public function testTogglingABranchLeavesTheRestAlone(): void
    {
        $filters = new PageFilters(search: 'contact', statuses: [PageStatus::Draft], open: [4]);

        $opened = $filters->toggling(9);
        self::assertSame([4, 9], $opened->open);
        self::assertSame('contact', $opened->search);
        self::assertSame([PageStatus::Draft], $opened->statuses);

        self::assertSame([4], $opened->toggling(9)->open);
    }

    public function testRemovingOneFilterKeepsTheOthers(): void
    {
        $filters = new PageFilters(search: 'contact', statuses: [PageStatus::Draft], visible: false, open: [4]);

        $withoutSearch = $filters->withoutFilter(PageFilters::SEARCH);
        self::assertSame('', $withoutSearch->search);
        self::assertSame([PageStatus::Draft], $withoutSearch->statuses);
        self::assertFalse($withoutSearch->visible);
        self::assertSame([4], $withoutSearch->open);

        // A nullable value cannot say "leave it alone" with null alone, which is
        // the mistake this asserts against.
        $withoutVisibility = $filters->withoutFilter(PageFilters::VISIBILITY);
        self::assertNull($withoutVisibility->visible);
        self::assertSame('contact', $withoutVisibility->search);
    }

    public function testNarrowingWhatIsShownGoesBackToTheFirstPageOfResults(): void
    {
        $filters = new PageFilters(search: 'contact', page: 7);

        self::assertSame(1, $filters->withoutFilter(PageFilters::SEARCH)->page);
        self::assertSame(1, $filters->withoutStatus(PageStatus::Draft)->page);
    }

    public function testTheAddressCarriesTheWholeState(): void
    {
        $filters = new PageFilters(
            search: 'contact',
            statuses: [PageStatus::Draft, PageStatus::Scheduled],
            visible: true,
            page: 3,
            open: [4, 9],
        );

        self::assertSame([
            'q' => 'contact',
            'status' => ['draft', 'scheduled'],
            'visibility' => 'online',
            'page' => '3',
            'open' => '4,9',
        ], $filters->toQueryParams());
    }

    public function testClosingTheLastBranchIsRememberedAsAChoice(): void
    {
        // A small site opens by itself. Closing its only branch leaves an empty
        // list, and an empty list has to be told apart from never having chosen,
        // or the next screen opens the branch again and the editor cannot close
        // anything.
        $closed = (new PageFilters(open: [4]))->toggling(4);

        self::assertSame([], $closed->open);
        self::assertTrue($closed->foldChosen);
        self::assertSame(['open' => ''], $closed->toQueryParams());

        self::assertTrue(PageFilters::fromRequest(new Request(['open' => '']))->foldChosen);
        self::assertFalse(PageFilters::fromRequest(new Request())->foldChosen);
    }

    public function testOpeningEverythingIsNotACaseOfTheEditorChoosing(): void
    {
        $filters = (new PageFilters())->withEverythingOpen([4, 9]);

        self::assertSame([4, 9], $filters->open);
        self::assertFalse($filters->foldChosen);
    }

    public function testClearingTheFiltersKeepsWhatIsOpen(): void
    {
        $filters = (new PageFilters(search: 'contact', open: [4]))->toggling(9);

        $cleared = $filters->withoutFilter(PageFilters::SEARCH);

        self::assertSame([4, 9], $cleared->open);
        self::assertTrue($cleared->foldChosen);
    }

    public function testAPageNumberBelowOneIsOne(): void
    {
        self::assertSame(1, PageFilters::fromRequest(new Request(['page' => '-4']))->page);
        self::assertSame(1, PageFilters::fromRequest(new Request(['page' => '0']))->page);
    }

    #[DataProvider('tagCases')]
    public function testATagIsAnIdOrTheWordForNone(string $given, ?int $expected): void
    {
        self::assertSame($expected, PageFilters::fromRequest(new Request(['tag' => $given]))->tag);
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function tagCases(): iterable
    {
        yield 'an id' => ['12', 12];
        yield 'none' => ['none', PageFilters::NO_TAG];
        yield 'any' => ['', null];
        yield 'zero' => ['0', null];
        yield 'negative' => ['-3', null];
        yield 'not digits' => ['12abc', null];
        yield 'too long' => ['12345678901', null];
    }

    #[DataProvider('typeCases')]
    public function testATypeIsACodeAndNothingElse(string $given, ?string $expected): void
    {
        self::assertSame($expected, PageFilters::fromRequest(new Request(['type' => $given]))->type);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function typeCases(): iterable
    {
        yield 'a code' => ['recipe', 'recipe'];
        yield 'with a dash' => ['blog-post', 'blog-post'];
        yield 'any' => ['', null];
        yield 'upper case' => ['Recipe', null];
        yield 'a path' => ['../recipe', null];
    }

    public function testATagOrATypeTurnsTheTreeIntoResults(): void
    {
        $tagged = PageFilters::fromRequest(new Request(['tag' => 'none']));
        self::assertTrue($tagged->isFiltering());
        self::assertSame(1, $tagged->advancedCount());
        self::assertSame(['tag' => 'none'], $tagged->toQueryParams());

        $typed = PageFilters::fromRequest(new Request(['type' => 'recipe', 'tag' => '4']));
        self::assertTrue($typed->isFiltering());
        self::assertSame(2, $typed->advancedCount());
        self::assertSame(['tag' => '4', 'type' => 'recipe'], $typed->toQueryParams());
    }

    public function testRemovingTheTagOrTheTypeKeepsTheOthers(): void
    {
        $filters = new PageFilters(search: 'contact', page: 3, open: [4], tag: 7, type: 'recipe');

        $withoutTag = $filters->withoutFilter(PageFilters::TAG);
        self::assertNull($withoutTag->tag);
        self::assertSame('recipe', $withoutTag->type);
        self::assertSame('contact', $withoutTag->search);
        self::assertSame(1, $withoutTag->page);

        $withoutType = $filters->withoutFilter(PageFilters::TYPE);
        self::assertNull($withoutType->type);
        self::assertSame(7, $withoutType->tag);

        // Every other change keeps both.
        self::assertSame(7, $filters->withoutFilter(PageFilters::SEARCH)->tag);
        self::assertSame('recipe', $filters->withoutStatus(PageStatus::Draft)->type);
        self::assertSame('recipe', $filters->toggling(9)->type);
    }

    public function testATagOrATypeTheSiteDoesNotHaveIsDropped(): void
    {
        $tags = [7 => 'News'];
        $types = ['default' => 'Page', 'recipe' => 'Recipe'];

        $known = new PageFilters(page: 2, tag: 7, type: 'recipe');
        self::assertSame($known, $known->knowing($tags, $types), 'known values are kept as they are');

        $unknownTag = (new PageFilters(page: 2, tag: 8, type: 'recipe'))->knowing($tags, $types);
        self::assertNull($unknownTag->tag);
        self::assertSame('recipe', $unknownTag->type);
        self::assertSame(2, $unknownTag->page);

        $unknownType = (new PageFilters(tag: PageFilters::NO_TAG, type: 'event'))->knowing($tags, $types);
        self::assertSame(PageFilters::NO_TAG, $unknownType->tag);
        self::assertNull($unknownType->type);

        // On a site without tags, every page is without one: the filter is no
        // filter, and a chip saying "Without a tag" would read as a choice.
        self::assertNull((new PageFilters(tag: PageFilters::NO_TAG))->knowing([], $types)->tag);
    }
}
