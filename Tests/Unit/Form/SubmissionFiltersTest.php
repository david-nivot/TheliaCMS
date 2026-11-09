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
}
