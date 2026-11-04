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

use Propel\Runtime\Propel;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use TheliaCMS\Sitemap\SitemapBoundary;

/**
 * The next moment the site changes without anyone writing anything: a page
 * scheduled to go online reaches its date, or one scheduled to go offline does.
 *
 * Whatever keeps a rendered page (a proxy, the sitemap) has to let it go by
 * then. The site as a whole, not the page alone: menus, lists and breadcrumbs
 * show only the pages online, so a page changes when any page it may show
 * arrives or leaves.
 */
final readonly class ScheduledChanges
{
    private const string KEY = 'thelia_cms_next_scheduled_change';

    private const string TAG = 'thelia_cms_scheduled_changes';

    /**
     * The longest an answer is kept. A write to a page drops it (`forget()`):
     * this only bounds what a write that bypasses the module leaves behind.
     */
    private const int LONGEST_KEPT = 3600;

    public function __construct(
        private TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * The next change after the given moment, read from the pages.
     *
     * Hidden pages, pages in the bin and page models are never shown, so their
     * dates change nothing.
     */
    public function nextAfter(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $bound = $now->format('Y-m-d H:i:s');

        $statement = Propel::getConnection()->prepare(<<<'SQL'
            SELECT MIN(CASE WHEN publish_at > :now THEN publish_at END) AS next_publication,
                   MIN(CASE WHEN unpublish_at > :now_removal THEN unpublish_at END) AS next_removal
            FROM cms_page
            WHERE deleted_at IS NULL
              AND visible = 1
              AND model = 0
            SQL);
        $statement->execute(['now' => $bound, 'now_removal' => $bound]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        return SitemapBoundary::nextAfter(
            array_map(
                static fn (mixed $date): ?\DateTimeImmutable => \is_string($date) ? new \DateTimeImmutable($date) : null,
                [$row['next_publication'], $row['next_removal']],
            ),
            $now,
        );
    }

    /**
     * The same, kept between requests: it is asked on every page that may be
     * shared, and changes only when a page is written or the date passes.
     */
    public function next(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $next = $this->kept($now);

        // An entry expires on the date it holds, but a cache counts in whole
        // seconds and the moment asked for may be ahead of the clock.
        if (null !== $next && $next <= $now) {
            $this->forget();
            $next = $this->kept($now);
        }

        return null !== $next && $next > $now ? $next : null;
    }

    /**
     * Called on every write to a page, through the invalidator.
     */
    public function forget(): void
    {
        $this->cache->invalidateTags([self::TAG]);
    }

    private function kept(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $timestamp = $this->cache->get(self::KEY, function (ItemInterface $item) use ($now): ?int {
            $item->tag(self::TAG);

            $next = $this->nextAfter($now);
            $item->expiresAfter(self::LONGEST_KEPT);

            if (null !== $next && $next->getTimestamp() - $now->getTimestamp() < self::LONGEST_KEPT) {
                $item->expiresAt($next);
            }

            return $next?->getTimestamp();
        });

        return \is_int($timestamp) ? (new \DateTimeImmutable())->setTimestamp($timestamp) : null;
    }
}
