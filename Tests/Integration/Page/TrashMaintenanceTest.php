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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Maintenance\MaintenancePurgeEvent;
use Thelia\Core\Event\TheliaEvents;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The maintenance of the shop deletes the pages past their time in the bin,
 * and its dry run deletes none.
 */
final class TrashMaintenanceTest extends CmsIntegrationTestCase
{
    public function testADryRunKeepsThePagesDueInTheBin(): void
    {
        $page = $this->binnedLongAgo();

        $line = $this->binLine($this->maintenance(dryRun: true));

        self::assertMatchesRegularExpression('#<info>[1-9]\d* to delete</info>#', $line);
        self::assertNotNull(CmsPageQuery::create()->findPk($page), 'A dry run deletes nothing.');
    }

    public function testTheMaintenanceDeletesThePagesDueInTheBin(): void
    {
        $page = $this->binnedLongAgo();

        $line = $this->binLine($this->maintenance(dryRun: false));

        self::assertMatchesRegularExpression('#<info>[1-9]\d* deleted</info>#', $line);
        self::assertNull(CmsPageQuery::create()->findPk($page));
    }

    private function binnedLongAgo(): int
    {
        $page = $this->createPage('Page partie depuis longtemps');
        CmsPageQuery::create()->findPk($page->getId())?->setDeletedAt(new \DateTimeImmutable('-10 years'))->save();
        CmsPageTableMap::clearInstancePool();

        return (int) $page->getId();
    }

    private function maintenance(bool $dryRun): MaintenancePurgeEvent
    {
        $event = new MaintenancePurgeEvent($dryRun);
        $this->getService(EventDispatcherInterface::class)->dispatch($event, TheliaEvents::MAINTENANCE_PURGE);

        return $event;
    }

    private function binLine(MaintenancePurgeEvent $event): string
    {
        return implode("\n", array_filter($event->getResults(), static fn (string $line): bool => str_contains($line, 'CMS pages past their time in the bin')));
    }
}
