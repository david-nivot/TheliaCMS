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

namespace TheliaCMS\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;
use TheliaCMS\Http\SharedCache\CoreSharedCache;
use TheliaCMS\Partial\Definition\FormPartial;
use TheliaCMS\Settings\CmsSettings;

/**
 * Writes the cache headers of a CMS page, once everything else is done with the
 * response.
 *
 * It has to run last. Whether the answer may be shared depends on the cookies
 * on it, and those are added by the session listener on `kernel.response`,
 * after the page was rendered. Deciding earlier means deciding on a response
 * that is not finished.
 */
final readonly class PageCacheListener
{
    /** Set by whatever rendered a page, and read here. */
    public const string TAGS_ATTRIBUTE = '_cms_cache_tags';

    /**
     * Written by ShownTags: what the page shows of others. Added to the tags
     * of any page, but never what makes a page cacheable on its own.
     */
    public const string SHOWN_TAGS_ATTRIBUTE = '_cms_cache_shown_tags';

    public function __construct(
        private PageCachePolicy $policy,
        private CmsSettings $settings,
        private ?CoreSharedCache $sharedCache = null,
    ) {
    }

    /**
     * Below the -1000 of Symfony's session listener, so the cookies it adds are
     * already on the response.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // A request that got past the closed site (an address on the allow
        // list, an administrator) sees the real one. Shared, that page would be
        // served to everyone in place of the closed site. Any page, the shop's
        // too: before the shared cache of the core, which refuses a `no-store`.
        if ($this->settings->isMaintenanceActive()) {
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');

            return;
        }

        $request = $event->getRequest();
        $tags = $request->attributes->get(self::TAGS_ATTRIBUTE);
        $tags = \is_array($tags) ? array_values($tags) : [];
        $shown = $request->attributes->get(self::SHOWN_TAGS_ATTRIBUTE);
        $shown = \is_array($shown) ? array_values($shown) : [];

        // The shared cache of the core has the last word once the shop turns it on.
        // Every page may show the menus and the site settings of the module (a theme
        // hook, a footer menu), so every page is filed under them, and a page of the
        // module under its own tags as well.
        if (null !== $this->sharedCache && $this->sharedCache->isActive()) {
            $this->sharedCache->tag(array_values(array_unique([CacheTags::SITE, CacheTags::MENUS, ...$tags, ...$shown])));
        } elseif ([] !== $tags) {
            // Only a page of the module is given headers of its own: a page of
            // the shop that shows a list of pages stays what the shop made it.
            $this->policy->apply($event->getResponse(), $request, array_values(array_unique([...$tags, ...$shown])));
        }

        // A page showing a form is nobody else's, the shop's pages included: each
        // display carries a stamp that carries one message, so a copy served to a
        // second visitor would drop their message as a replay. `no-store` is also
        // what keeps the shared cache of the core, which runs after this, from
        // sharing it.
        if (\in_array(CacheTags::partial(FormPartial::NAME), [...$tags, ...$shown], true)) {
            $this->keepPrivate($event->getResponse());
        }
    }

    private function keepPrivate(Response $response): void
    {
        $response->headers->remove(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER);
        $response->headers->set('Cache-Control', 'private, no-store');
    }
}
