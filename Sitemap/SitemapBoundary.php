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

namespace TheliaCMS\Sitemap;

/**
 * The next moment the sitemap changes without anyone writing anything: a page
 * that was scheduled goes online, or one that was scheduled goes offline.
 *
 * A write empties the kept sitemap; a date that passes does not write, so the
 * kept answer has to expire on it.
 */
final readonly class SitemapBoundary
{
    /**
     * The earliest of the dates that is still to come, or null when none is.
     *
     * A date that is now or already past changed the sitemap earlier and is not
     * a boundary any more.
     *
     * @param list<\DateTimeInterface|null> $dates
     */
    public static function nextAfter(array $dates, \DateTimeInterface $now): ?\DateTimeImmutable
    {
        $next = null;

        foreach ($dates as $date) {
            if (null === $date || $date <= $now) {
                continue;
            }

            $date = \DateTimeImmutable::createFromInterface($date);

            if (null === $next || $date < $next) {
                $next = $date;
            }
        }

        return $next;
    }
}
