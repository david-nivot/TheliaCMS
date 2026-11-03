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

namespace TheliaCMS\Seo;

use SEOne\Event\SEOneUrlEvent;
use SEOne\Event\SEOneUrlEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use TheliaCMS\TheliaCMS;

/**
 * Serves the canonical address an editor typed in the SEO tab of a page.
 *
 * SEOne builds the canonical from the path of the request unless a listener
 * answers first; its own override is stored in its tables, which the page form
 * does not write. The view listener puts the address of the page on the request
 * before rendering it, and this listener hands it on.
 *
 * Below StandInPageCanonicalListener: a page shown on an address that does not
 * exist names the page served, whatever that page asks for. Above the SEOne
 * listener, which leaves an URL already set alone.
 */
final readonly class PageCanonicalListener
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(event: SEOneUrlEvents::GENERATE_CANONICAL, priority: 160)]
    public function onGenerateCanonical(SEOneUrlEvent $event): void
    {
        if (null !== $event->getUrl()) {
            return;
        }

        $canonical = $this->requestStack->getMainRequest()?->attributes->get(TheliaCMS::PAGE_CANONICAL_ATTRIBUTE);

        if (!\is_string($canonical) || '' === $canonical) {
            return;
        }

        // SEOne refuses what is neither an URL nor a path: an address an import
        // left malformed falls back to the address of the page.
        try {
            $event->setUrl($canonical);
        } catch (\InvalidArgumentException) {
            return;
        }
    }
}
