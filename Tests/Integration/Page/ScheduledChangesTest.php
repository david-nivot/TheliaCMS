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

use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Page\ScheduledChanges;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\CountsStatements;

/**
 * The next moment the site changes by itself: a page scheduled to go online
 * or offline reaches its date. Whatever keeps a page past that moment serves
 * a site that no longer exists.
 *
 * The dates are years ahead, so that no page of the test base, scheduled for
 * real, comes before them.
 */
final class ScheduledChangesTest extends CmsIntegrationTestCase
{
    use CountsStatements;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // To the second: the columns keep no fraction, and MySQL rounds one
        // where MariaDB cuts it.
        $this->now = new \DateTimeImmutable(date('Y-m-d H:i:s', strtotime('+10 years')));
        $this->changes()->forget();
    }

    protected function tearDown(): void
    {
        $this->changes()->forget();

        parent::tearDown();
    }

    public function testTheNextChangeIsTheEarliestDateStillToCome(): void
    {
        $this->createPage('Page qui arrive')->setPublishAt($this->now->modify('+3 days'))->save();
        $this->createPage('Page qui part')->setUnpublishAt($this->now->modify('+2 days'))->save();
        $this->createPage('Page déjà arrivée')->setPublishAt($this->now->modify('-1 day'))->save();

        self::assertEquals($this->now->modify('+2 days'), $this->changes()->nextAfter($this->now));
    }

    /**
     * A hidden page, a page in the bin and a page model are never shown, so
     * their dates change nothing a visitor sees.
     */
    public function testPagesNobodySeesChangeNothing(): void
    {
        $this->createPage('Page cachée', visible: false)->setUnpublishAt($this->now->modify('+1 day'))->save();
        $this->createPage('Page à la corbeille')->setDeletedAt($this->now->modify('-1 year'))->setUnpublishAt($this->now->modify('+1 day'))->save();
        $this->createPage('Modèle de page')->setModel(1)->setPublishAt($this->now->modify('+1 day'))->save();
        $this->createPage('Page visible')->setUnpublishAt($this->now->modify('+5 days'))->save();

        self::assertEquals($this->now->modify('+5 days'), $this->changes()->nextAfter($this->now));
    }

    /**
     * Asked on every page that may be shared: read once, then kept until the
     * date passes or a page changes.
     */
    public function testTheAnswerIsKeptUntilAPageChanges(): void
    {
        $page = $this->createPage('Page qui part');
        $page->setUnpublishAt($this->now->modify('+4 days'))->save();

        self::assertEquals($this->now->modify('+4 days'), $this->changes()->next($this->now));

        $sooner = $this->now->modify('+1 day');
        $page->setUnpublishAt($sooner)->save();

        self::assertSame(0, $this->statementsFor(fn (): ?\DateTimeImmutable => $this->changes()->next($this->now)), 'The answer was read again.');
        self::assertEquals($this->now->modify('+4 days'), $this->changes()->next($this->now));

        // Every write to a page lands on the invalidator.
        $this->getService(PageInvalidator::class)->listsChanged();

        self::assertEquals($sooner, $this->changes()->next($this->now));
    }

    public function testADatePastIsNotKeptAsTheNextChange(): void
    {
        $this->createPage('Page qui part')->setUnpublishAt($this->now->modify('+1 day'))->save();
        self::assertNotNull($this->changes()->next($this->now));

        self::assertNull($this->changes()->next($this->now->modify('+2 days')));
    }

    private function changes(): ScheduledChanges
    {
        return $this->getService(ScheduledChanges::class);
    }
}
