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

namespace TheliaCMS\Http\SharedCache;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use TheliaCMS\Http\PageCachePolicy;

/**
 * Stops what the shared cache of the core keeps at the next date a page of the
 * site goes online or offline.
 *
 * Once the shop turns it on, the core writes the headers of every shared page
 * with its own TTL, after the module (`PageCacheListener`), and knows nothing
 * of the dates of the pages. Any page may show a menu or a list of pages, the
 * shop's too, so every answer the core shares is capped. Only ever lowered: a
 * TTL shorter than the time left stays.
 */
final readonly class SharedMaxAgeCapListener
{
    public function __construct(
        private PageCachePolicy $policy,
        private CoreSharedCache $sharedCache,
    ) {
    }

    /**
     * Below the -1026 of the listener of the core, so its headers are there.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1030)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->sharedCache->isActive()) {
            return;
        }

        $response = $event->getResponse();
        $shared = $response->headers->getCacheControlDirective('s-maxage');
        $announced = $this->announcedMaxAge($response);

        // Nothing shared, nothing to read from the database.
        if (null === $shared && null === $announced) {
            return;
        }

        $ttl = max((int) $shared, (int) $announced);
        $capped = $this->policy->sharedMaxAgeFromNow($ttl);

        if (null === $capped) {
            $response->headers->remove('Surrogate-Control');
            $response->headers->set('Cache-Control', 'private, no-store');

            return;
        }

        if (null !== $shared && $capped < (int) $shared) {
            $response->setSharedMaxAge($capped);
        }

        if (null !== $announced && $capped < $announced) {
            $response->headers->set('Surrogate-Control', 'max-age='.$capped);
        }
    }

    /**
     * The TTL the core announces to a proxy that sets its own headers, when it
     * announces one.
     */
    private function announcedMaxAge(Response $response): ?int
    {
        $header = (string) $response->headers->get('Surrogate-Control', '');

        return 1 === preg_match('/(?:^|,)\s*max-age=(\d+)/', $header, $match) ? (int) $match[1] : null;
    }
}
