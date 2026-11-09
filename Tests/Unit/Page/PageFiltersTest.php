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
use TheliaCMS\Page\Admin\PageDate;
use TheliaCMS\Page\Admin\PageFilters;
use TheliaCMS\Page\Admin\PageSort;
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

    public function testAPageToLookInsideListsItsPagesFlat(): void
    {
        $filters = PageFilters::fromRequest(new Request(['inside' => '12', 'open' => '4']));

        self::assertSame(12, $filters->inside);
        self::assertTrue($filters->isFiltering(), 'The pages of one page are a list, paginated and sortable like results.');
        self::assertTrue($filters->isInsideOnly());
        self::assertSame(0, $filters->advancedCount(), 'Named by the breadcrumb, not counted as a filter of the bar.');
        self::assertSame(['inside' => '12', 'open' => '4'], $filters->toQueryParams());
        self::assertSame($filters->toQueryParams(), PageFilters::fromReturnParameter((string) $filters->toReturnParameter())->toQueryParams(), 'A screen opened from it comes back to it.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAPage(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-3'];
        yield 'a word' => ['recipes'];
        yield 'empty' => [''];
    }

    #[DataProvider('notAPage')]
    public function testAnythingButAnIdentifierIsNoPageToLookInside(string $given): void
    {
        self::assertNull(PageFilters::fromRequest(new Request(['inside' => $given]))->inside);
    }

    public function testGoingInsideAPageStartsAfreshAndKeepsTheTree(): void
    {
        $filters = new PageFilters(search: 'soup', page: 3, open: [4], foldChosen: true, sort: PageSort::Title);

        $inside = $filters->inside(12);

        self::assertSame(['inside' => '12', 'open' => '4'], $inside->toQueryParams(), 'Every page of it, back to the tree as it was left.');
    }

    public function testAnythingElseAskedInsideAPageIsNoLongerOnlyInside(): void
    {
        self::assertFalse((new PageFilters(search: 'soup', inside: 12))->isInsideOnly());
        self::assertFalse((new PageFilters(sort: PageSort::Title, inside: 12))->isInsideOnly());
        self::assertFalse((new PageFilters())->isInsideOnly());
    }

    public function testLeavingThePageKeepsWhatElseWasAsked(): void
    {
        $filters = new PageFilters(search: 'soup', page: 2, inside: 12);

        self::assertSame(['q' => 'soup'], $filters->withoutFilter(PageFilters::INSIDE)->toQueryParams());
        self::assertSame(12, $filters->withoutFilter(PageFilters::SEARCH)->inside, 'Removing another filter stays inside.');
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

    public function testAReturnCarriesTheStateOfTheListAndReadsBackTheSame(): void
    {
        $filters = new PageFilters(
            search: 'contact',
            statuses: [PageStatus::Draft, PageStatus::Scheduled],
            visible: false,
            page: 2,
            open: [4, 9],
            foldChosen: true,
            tag: PageFilters::NO_TAG,
            type: 'recipe',
            sort: PageSort::Modified,
            descending: false,
        );

        $return = $filters->toReturnParameter();
        self::assertNotNull($return);

        self::assertSame($filters->toQueryParams(), PageFilters::fromReturnParameter($return)->toQueryParams());
    }

    public function testThePlainListNeedsNoReturn(): void
    {
        self::assertNull((new PageFilters())->toReturnParameter());
        self::assertSame([], PageFilters::returnQuery(new Request()));
    }

    #[DataProvider('refusedReturns')]
    public function testAReturnHoldingAnythingElseThanFiltersIsThePlainList(string $given): void
    {
        self::assertSame([], PageFilters::fromReturnParameter($given)->toQueryParams());
        self::assertSame([], PageFilters::returnQuery(new Request(['return' => $given])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedReturns(): iterable
    {
        yield 'an address' => ['https://elsewhere.example/admin'];
        yield 'a path' => ['/admin/cms/pages/4'];
        yield 'a protocol-relative address' => ['//elsewhere.example'];
        yield 'a filter and something else' => ['q=contact&next=https://elsewhere.example'];
        yield 'a list where a word is expected' => ['q[]=contact'];
        yield 'a word where a list is expected' => ['status=draft'];
        yield 'too long' => ['q='.str_repeat('a', 1001)];
    }

    public function testAReturnIsPassedOnRebuiltFromItsFilters(): void
    {
        // Values the listing would not keep are dropped on the way, so the next
        // screen receives what the list understands and not what was typed.
        $query = PageFilters::returnQuery(new Request(['return' => 'q=+contact+&status[]=draft&status[]=nonsense&open=4,abc']));

        self::assertSame(['return' => 'q=contact&status%5B0%5D=draft&open=4'], $query);
    }

    #[DataProvider('sortCases')]
    public function testASortIsOneOfThreeAndADirectionOneOfTwo(array $given, ?PageSort $sort, bool $descending): void
    {
        $filters = PageFilters::fromRequest(new Request($given));

        self::assertSame($sort, $filters->sort);
        self::assertSame($descending, $filters->isDescending());
    }

    /**
     * @return iterable<string, array{array<string, string>, PageSort|null, bool}>
     */
    public static function sortCases(): iterable
    {
        yield 'none' => [[], null, false];
        yield 'a title reads from A' => [['sort' => 'title'], PageSort::Title, false];
        yield 'a date from the latest' => [['sort' => 'modified'], PageSort::Modified, true];
        yield 'created, from the latest' => [['sort' => 'created'], PageSort::Created, true];
        yield 'reversed' => [['sort' => 'title', 'dir' => 'desc'], PageSort::Title, true];
        yield 'a date from the oldest' => [['sort' => 'modified', 'dir' => 'asc'], PageSort::Modified, false];
        yield 'an unknown sort' => [['sort' => 'status'], null, false];
        yield 'an unknown direction' => [['sort' => 'created', 'dir' => 'up'], PageSort::Created, true];
    }

    public function testASortTurnsTheTreeIntoResults(): void
    {
        $sorted = new PageFilters(sort: PageSort::Created);

        self::assertTrue($sorted->isFiltering());
        self::assertFalse($sorted->withoutFilter(PageFilters::SORT)->isFiltering(), 'Removing the sort gives the tree back.');
    }

    public function testTheAddressCarriesTheDirectionOnlyWhenItIsNotTheOneTheSortStartsIn(): void
    {
        self::assertSame(['sort' => 'modified'], (new PageFilters(sort: PageSort::Modified))->toQueryParams());
        self::assertSame(['sort' => 'modified'], (new PageFilters(sort: PageSort::Modified, descending: true))->toQueryParams());
        self::assertSame(['sort' => 'modified', 'dir' => 'asc'], (new PageFilters(sort: PageSort::Modified, descending: false))->toQueryParams());
        self::assertSame(['sort' => 'title', 'dir' => 'desc'], (new PageFilters(sort: PageSort::Title, descending: true))->toQueryParams());
        self::assertSame([], (new PageFilters(descending: true))->toQueryParams(), 'A direction without a sort says nothing.');
    }

    public function testSortingAgainByTheSameOrderReversesItAndAnotherOrderStartsInItsOwnDirection(): void
    {
        $byTitle = (new PageFilters(search: 'contact', page: 3))->sortedBy(PageSort::Title);

        self::assertSame(PageSort::Title, $byTitle->sort);
        self::assertFalse($byTitle->isDescending());
        self::assertSame(1, $byTitle->page, 'A new order starts on the first page of results.');
        self::assertSame('contact', $byTitle->search, 'Sorting keeps the filters.');

        self::assertTrue($byTitle->sortedBy(PageSort::Title)->isDescending());
        self::assertFalse($byTitle->sortedBy(PageSort::Title)->sortedBy(PageSort::Title)->isDescending());

        $reversedTitle = $byTitle->sortedBy(PageSort::Title);
        self::assertTrue($reversedTitle->sortedBy(PageSort::Modified)->isDescending(), 'Another order starts latest first.');
        self::assertFalse((new PageFilters(sort: PageSort::Modified, descending: true))->sortedBy(PageSort::Title)->isDescending());
    }

    public function testRemovingTheSortKeepsTheFiltersAndForgetsTheDirection(): void
    {
        $filters = new PageFilters(search: 'contact', tag: 4, sort: PageSort::Title, descending: true);

        self::assertSame(['q' => 'contact', 'tag' => '4'], $filters->withoutFilter(PageFilters::SORT)->toQueryParams());
        self::assertSame(['q' => 'contact', 'sort' => 'title', 'dir' => 'desc'], $filters->withoutFilter(PageFilters::TAG)->toQueryParams());
    }

    /**
     * @param array<string, string> $given
     */
    #[DataProvider('periods')]
    public function testAPeriodIsADateAndOneOrTwoDaysOfTheCalendar(array $given, ?PageDate $date, ?string $from, ?string $to): void
    {
        $filters = PageFilters::fromRequest(new Request($given));

        self::assertSame($date, $filters->date);
        self::assertSame($from, $filters->from?->format('Y-m-d H:i:s'));
        self::assertSame($to, $filters->to?->format('Y-m-d H:i:s'));
        self::assertSame(null !== $date, $filters->isFiltering(), 'a period turns the tree into results, no period does not');
    }

    /**
     * @return iterable<string, array{array<string, string>, PageDate|null, string|null, string|null}>
     */
    public static function periods(): iterable
    {
        yield 'two days' => [['date' => 'published', 'from' => '2026-02-01', 'to' => '2026-02-10'], PageDate::Published, '2026-02-01 00:00:00', '2026-02-10 00:00:00'];
        yield 'the last change unless told' => [['from' => '2026-02-01'], PageDate::Modified, '2026-02-01 00:00:00', null];
        yield 'a date the module does not know is the last change' => [['date' => 'deleted', 'to' => '2026-02-10'], PageDate::Modified, null, '2026-02-10 00:00:00'];
        yield 'bounds the wrong way round are put back' => [['date' => 'created', 'from' => '2026-03-01', 'to' => '2026-02-01'], PageDate::Created, '2026-02-01 00:00:00', '2026-03-01 00:00:00'];
        yield 'a day that does not exist is ignored' => [['from' => '2026-02-31', 'to' => '2026-03-01'], PageDate::Modified, null, '2026-03-01 00:00:00'];
        yield 'a day written another way is ignored' => [['from' => '01/02/2026', 'to' => '2026-2-1'], null, null, null];
        yield 'a date with no day is no period' => [['date' => 'unpublish_on'], null, null, null];
        yield 'a time is not a day' => [['from' => '2026-02-01 10:00'], null, null, null];
    }

    public function testTheAddressCarriesThePeriodAndTheDateOnlyWhenItIsNotTheLastChange(): void
    {
        $day = new \DateTimeImmutable('2026-02-01');

        self::assertSame(['from' => '2026-02-01', 'to' => '2026-02-01'], (new PageFilters(date: PageDate::Modified, from: $day, to: $day))->toQueryParams());
        self::assertSame(['date' => 'publish_on', 'to' => '2026-02-01'], (new PageFilters(date: PageDate::PublishOn, to: $day))->toQueryParams());

        $read = PageFilters::fromRequest(new Request(['date' => 'publish_on', 'to' => '2026-02-01']));
        self::assertSame(['date' => 'publish_on', 'to' => '2026-02-01'], $read->toQueryParams(), 'read back the same');
    }

    #[DataProvider('authors')]
    public function testAnAuthorIsTheIdOfAnAccount(string $given, ?int $expected): void
    {
        $filters = PageFilters::fromRequest(new Request(['author' => $given]));

        self::assertSame($expected, $filters->author);
        self::assertSame(null === $expected ? [] : ['author' => $given], $filters->toQueryParams());
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function authors(): iterable
    {
        yield 'an id' => ['12', 12];
        yield 'zero' => ['0', null];
        yield 'a negative id' => ['-3', null];
        yield 'a name' => ['admin', null];
    }

    public function testAPeriodAndAnAuthorCountAsFiltersAndGoOneByOne(): void
    {
        $day = new \DateTimeImmutable('2026-02-01');
        $filters = new PageFilters(search: 'contact', date: PageDate::Created, from: $day, author: 4);

        self::assertSame(2, $filters->advancedCount());
        self::assertSame(['q' => 'contact', 'author' => '4'], $filters->withoutFilter(PageFilters::DATE)->toQueryParams());
        self::assertSame(['q' => 'contact', 'date' => 'created', 'from' => '2026-02-01'], $filters->withoutFilter(PageFilters::AUTHOR)->toQueryParams());
    }

    public function testAnAuthorWhoChangedNoPageIsDropped(): void
    {
        $known = new PageFilters(author: 4);
        self::assertSame($known, $known->knowing([], [], [4 => 'Jeanne Martin']));
        // A deleted account still names the pages it changed.
        self::assertSame(5, (new PageFilters(author: 5))->knowing([], [], [5 => null])->author);
        self::assertNull((new PageFilters(author: 6))->knowing([], [], [4 => 'Jeanne Martin'])->author);
    }

    public function testAReturnCarriesThePeriodAndTheAuthor(): void
    {
        $filters = new PageFilters(date: PageDate::Published, from: new \DateTimeImmutable('2026-02-01'), to: new \DateTimeImmutable('2026-02-10'), author: 4);
        $return = $filters->toReturnParameter();
        self::assertNotNull($return);

        self::assertSame($filters->toQueryParams(), PageFilters::fromReturnParameter($return)->toQueryParams());
    }
}
