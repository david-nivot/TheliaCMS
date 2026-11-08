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

namespace TheliaCMS\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Media\Admin\MediaQuery;
use TheliaCMS\Media\MediaKind;
use TheliaCMS\Media\MediaView;

/**
 * The view of the list of the media is read from its address and kept in it,
 * like a filter, so that every link of the list carries it.
 */
final class MediaQueryTest extends TestCase
{
    public function testTheGridIsTheViewUnlessTheAddressAsksForTheList(): void
    {
        self::assertSame(MediaView::Grid, MediaQuery::fromRequest(Request::create('/admin/cms/media'))->view);
        self::assertSame(MediaView::List, MediaQuery::fromRequest(Request::create('/admin/cms/media?view=list'))->view);
        self::assertSame(MediaView::Grid, MediaQuery::fromRequest(Request::create('/admin/cms/media?view=table'))->view, 'An unknown view is the grid, not a failure.');
    }

    public function testTheListIsKeptInTheAddressAndTheGridLeavesItPlain(): void
    {
        self::assertSame(['view' => 'list'], (new MediaQuery(view: MediaView::List))->parameters());
        self::assertSame([], (new MediaQuery())->parameters(), 'The grid is the default: its address stays plain.');
        self::assertSame(['search' => 'chair', 'view' => 'list', 'page' => 3], (new MediaQuery(search: 'chair', page: 3, view: MediaView::List))->addressParameters());
        self::assertFalse((new MediaQuery(view: MediaView::List))->isNarrowed(), 'A view narrows nothing.');
    }

    public function testAnotherViewKeepsTheFiltersAndThePage(): void
    {
        $query = new MediaQuery(search: 'chair', tag: 'Chairs', page: 2, images: [4, 5]);

        self::assertSame(['search' => 'chair', 'tag' => 'Chairs', 'view' => 'list', 'page' => 2], $query->viewParameters(MediaView::List));
        self::assertSame(['search' => 'chair', 'tag' => 'Chairs', 'page' => 2], (new MediaQuery(search: 'chair', tag: 'Chairs', page: 2, view: MediaView::List))->viewParameters(MediaView::Grid));
    }

    public function testAQueryNarrowedToAKindKeepsItsView(): void
    {
        self::assertSame(MediaView::List, (new MediaQuery(view: MediaView::List))->ofKind(MediaKind::Image)->view);
    }
}
