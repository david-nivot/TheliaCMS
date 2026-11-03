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

namespace TheliaCMS\Tests\Unit\Sitemap;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Sitemap\SitemapBoundary;

/**
 * A scheduled publication writes nothing, so the kept sitemap has to expire on
 * the next date by itself.
 */
final class SitemapBoundaryTest extends TestCase
{
    private const string NOW = '2026-11-03 12:00:00';

    public function testTheBoundaryIsTheEarliestDateStillToCome(): void
    {
        $boundary = SitemapBoundary::nextAfter($this->dates('2026-11-05 08:00:00', '2026-11-03 18:30:00', '2026-12-01 00:00:00'), $this->now());

        self::assertSame('2026-11-03 18:30:00', $boundary?->format('Y-m-d H:i:s'));
    }

    public function testADateAlreadyPastIsNotABoundary(): void
    {
        $boundary = SitemapBoundary::nextAfter($this->dates('2026-11-01 08:00:00', '2026-11-04 09:00:00'), $this->now());

        self::assertSame('2026-11-04 09:00:00', $boundary?->format('Y-m-d H:i:s'));
    }

    public function testADateAtThisVeryMomentIsNotABoundaryEither(): void
    {
        self::assertNull(SitemapBoundary::nextAfter($this->dates(self::NOW), $this->now()));
    }

    public function testNoDateMeansNoBoundary(): void
    {
        self::assertNull(SitemapBoundary::nextAfter([], $this->now()));
        self::assertNull(SitemapBoundary::nextAfter([null, null], $this->now()));
        self::assertNull(SitemapBoundary::nextAfter($this->dates('2026-10-01 00:00:00'), $this->now()));
    }

    public function testNullDatesAreSkipped(): void
    {
        $boundary = SitemapBoundary::nextAfter([null, new \DateTimeImmutable('2026-11-03 13:00:00')], $this->now());

        self::assertSame('2026-11-03 13:00:00', $boundary?->format('Y-m-d H:i:s'));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function dates(string ...$dates): array
    {
        return array_map(static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date), array_values($dates));
    }
}
