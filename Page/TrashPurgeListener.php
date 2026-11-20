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

namespace TheliaCMS\Page;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Thelia\Core\Event\Maintenance\MaintenancePurgeEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * Empties the bin along with the carts and the admin logs.
 *
 * `maintenance:purge` is the command a Thelia site is already told to schedule,
 * which is what makes the retention happen: a rule needing a cron entry of its
 * own is a rule half the sites will never run.
 */
final readonly class TrashPurgeListener
{
    public function __construct(
        private TrashPurger $purger,
    ) {
    }

    #[AsEventListener(event: TheliaEvents::MAINTENANCE_PURGE)]
    public function onMaintenancePurge(MaintenancePurgeEvent $event): void
    {
        // A dry run reports and deletes nothing, as the other purges of the
        // maintenance do.
        $dryRun = $event->isDryRun();

        $event->addResult(\sprintf(
            '<comment>CMS pages past their time in the bin:</comment> <info>%d %s</info>',
            $dryRun ? \count($this->purger->due()) : $this->purger->purge(),
            $dryRun ? 'to delete' : 'deleted',
        ));
    }
}
