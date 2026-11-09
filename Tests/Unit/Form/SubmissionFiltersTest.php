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

namespace TheliaCMS\Tests\Unit\Form;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Form\Admin\SubmissionFilters;
use TheliaCMS\Form\SubmissionStatus;

final class SubmissionFiltersTest extends TestCase
{
    public function testWithNoStatusTheListHoldsTheAnswersStillInUse(): void
    {
        $filters = SubmissionFilters::fromRequest(Request::create('/', 'GET', ['email' => ' ann ']));

        self::assertSame([SubmissionStatus::New, SubmissionStatus::Handled], $filters->statuses());
        self::assertSame(['email' => 'ann'], $filters->parameters());
    }

    public function testAStatusOrAllIsKeptInTheAddress(): void
    {
        $archived = SubmissionFilters::fromRequest(Request::create('/', 'GET', ['status' => 'archived']));
        self::assertSame([SubmissionStatus::Archived], $archived->statuses());
        self::assertSame(['status' => 'archived'], $archived->parameters());

        $all = SubmissionFilters::fromRequest(Request::create('/', 'GET', ['status' => 'all']));
        self::assertSame(SubmissionStatus::cases(), $all->statuses());
        self::assertSame(['status' => 'all'], $all->parameters());
    }

    public function testAnUnknownStatusFallsBackToTheDefaultList(): void
    {
        $filters = SubmissionFilters::fromRequest(Request::create('/', 'GET', ['status' => 'forgotten']));

        self::assertSame([SubmissionStatus::New, SubmissionStatus::Handled], $filters->statuses());
        self::assertSame([], $filters->parameters());
    }

    public function testAGestureReadsTheFiltersItWasPostedWith(): void
    {
        $filters = SubmissionFilters::fromRequest(Request::create('/', 'POST', ['email' => 'bob', 'status' => 'handled']));

        self::assertSame(['email' => 'bob', 'status' => 'handled'], $filters->parameters());
        self::assertSame(['email' => 'bob', 'status' => 'new'], $filters->onStatus('new'));
        self::assertSame(['email' => 'bob'], $filters->onStatus(''));
    }

    public function testAPeriodIsTwoDaysOfTheCalendarPutBackInOrder(): void
    {
        $filters = SubmissionFilters::fromRequest(Request::create('/', 'GET', ['from' => '2026-03-31', 'to' => '2026-03-01']));

        self::assertSame('2026-03-01', $filters->from?->format('Y-m-d'));
        self::assertSame('2026-03-31', $filters->to?->format('Y-m-d'));
        self::assertSame(['from' => '2026-03-01', 'to' => '2026-03-31'], $filters->parameters());
        self::assertTrue($filters->isNarrowed());

        $wrong = SubmissionFilters::fromRequest(Request::create('/', 'GET', ['from' => '2026-02-31', 'to' => 'yesterday']));
        self::assertNull($wrong->from, 'The 31st of February is no day.');
        self::assertNull($wrong->to);
        self::assertFalse($wrong->isNarrowed());
    }

    public function testAnAnswerAskedForMatchesTheVeryValueGivenToThatQuestion(): void
    {
        $filters = SubmissionFilters::fromRequest(Request::create('/', 'GET', ['answer' => ['topic' => 'Sales', 'size' => '', 'bad' => ['x']]]));

        self::assertSame(['topic' => 'Sales'], $filters->answers, 'An empty choice is "any answer"; a value that is no text is ignored.');
        self::assertTrue($filters->matches([['name' => 'email', 'value' => 'Sales'], ['name' => 'topic', 'value' => 'Sales']]));
        self::assertFalse($filters->matches([['name' => 'email', 'value' => 'Sales'], ['name' => 'topic', 'value' => 'Support']]), 'The value of another question does not count.');
        self::assertFalse($filters->matches([['name' => 'topic', 'value' => 'sales']]), 'The very value, case included.');
        self::assertFalse($filters->matches([]), 'An answer missing matches nothing.');
        self::assertTrue((new SubmissionFilters())->matches([]), 'Nothing asked, everything matches.');

        $both = new SubmissionFilters(answers: ['topic' => 'Sales', 'size' => 'L']);
        self::assertFalse($both->matches([['name' => 'topic', 'value' => 'Sales']]), 'Every question asked must match.');
        self::assertTrue($both->matches([['name' => 'size', 'value' => 'L'], ['name' => 'topic', 'value' => 'Sales']]));
    }

    public function testEveryFilterTravelsInTheAddressAndInTheFormsOfTheGestures(): void
    {
        $filters = SubmissionFilters::fromRequest(Request::create('/', 'POST', [
            'email' => 'ann', 'status' => 'all', 'from' => '2026-01-01', 'answer' => ['topic' => 'Sales'],
        ]));

        self::assertSame(['email' => 'ann', 'status' => 'all', 'from' => '2026-01-01', 'answer' => ['topic' => 'Sales']], $filters->parameters());
        self::assertSame(['email' => 'ann', 'status' => 'new', 'from' => '2026-01-01', 'answer' => ['topic' => 'Sales']], $filters->onStatus('new'));
        self::assertSame([
            ['name' => 'email', 'value' => 'ann'],
            ['name' => 'status', 'value' => 'all'],
            ['name' => 'from', 'value' => '2026-01-01'],
            ['name' => 'answer[topic]', 'value' => 'Sales'],
        ], $filters->hiddenFields());
    }

    public function testEachFilterIsRemovedAloneAndAllAtOnce(): void
    {
        $filters = new SubmissionFilters(
            email: 'ann',
            status: SubmissionStatus::Handled,
            from: new \DateTimeImmutable('2026-01-01'),
            to: new \DateTimeImmutable('2026-01-31'),
            answers: ['size' => 'L', 'topic' => 'Sales'],
        );

        self::assertSame(['status' => 'handled', 'from' => '2026-01-01', 'to' => '2026-01-31', 'answer' => ['size' => 'L', 'topic' => 'Sales']], $filters->without('email'));
        self::assertSame(['email' => 'ann', 'status' => 'handled', 'answer' => ['size' => 'L', 'topic' => 'Sales']], $filters->without('from'));
        self::assertSame(['email' => 'ann', 'status' => 'handled', 'from' => '2026-01-01', 'to' => '2026-01-31', 'answer' => ['size' => 'L']], $filters->without('answer', 'topic'));
        self::assertSame(['status' => 'handled'], $filters->cleared(), 'Clearing keeps the tab.');
    }
}
