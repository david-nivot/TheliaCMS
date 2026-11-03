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

use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use TheliaCMS\TheliaCMS;

/**
 * The sitemap as text, kept per language and per host.
 *
 * A crawler asks for it on every visit and it costs a read of every page. What
 * is kept is the finished XML, which also saves the addresses and the escaping.
 * Every change that can alter it lands on `invalidate()`; a date that passes
 * alters it without a write, so an entry also expires on the next one.
 */
final readonly class SitemapCache
{
    private const string TAG = 'thelia_cms_sitemap';

    /** Upper bound of an entry: the explicit invalidation is what keeps it right. */
    private const int DEFAULT_TTL = 3600;

    public function __construct(
        private TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * @param callable(): ?string             $render     the sitemap, or null when there is nothing worth keeping (no active language asked for)
     * @param callable(): ?\DateTimeImmutable $nextChange when the sitemap next changes by itself, if it does
     */
    public function get(string $lang, string $host, callable $render, callable $nextChange): string
    {
        // The language comes from the query string and the host from the request:
        // both are user input as far as a cache key is concerned.
        $key = \sprintf('thelia_cms_sitemap.%s.%s', '' === $lang ? 'all' : preg_replace('/[^a-z0-9_-]/i', '_', $lang), substr(sha1($host), 0, 12));

        return $this->cache->get($key, function (ItemInterface $item) use ($render, $nextChange): string {
            $item->tag(self::TAG);

            $xml = $render();

            if (null === $xml) {
                // Expired as it is stored: nothing is kept for a value a crawler made up.
                $item->expiresAfter(-1);

                return '';
            }

            $ttl = $this->ttl();
            $item->expiresAfter($ttl);

            $boundary = $nextChange();

            if (null !== $boundary && $boundary < new \DateTimeImmutable(\sprintf('+%d seconds', $ttl))) {
                $item->expiresAt($boundary);
            }

            return $xml;
        });
    }

    public function invalidate(): void
    {
        $this->cache->invalidateTags([self::TAG]);
    }

    private function ttl(): int
    {
        $configured = (int) TheliaCMS::getConfigValue('cache_ttl', (string) self::DEFAULT_TTL);

        return $configured > 0 ? $configured : self::DEFAULT_TTL;
    }
}
