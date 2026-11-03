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
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\TheliaCMS;

/**
 * Turns `/` into a CMS page when one is set as the home page.
 *
 * Runs at priority 5, ahead of both the CMS page listener (4) and the Page
 * module (3): by the time they look at the request, the view is already
 * `cmspage`, so neither fights for the home page.
 *
 * If the configured page is missing or not published in the visitor's locale,
 * nothing is changed and the theme renders its own index — a broken setting
 * must not take the storefront down.
 */
final readonly class CmsHomeViewListener
{
    public function __construct(
        private LangService $langService,
        private PublishedPageRepository $pages,
    ) {
    }

    #[AsEventListener(event: KernelEvents::VIEW, priority: 5)]
    public function onKernelView(ViewEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ('index' !== $request->attributes->get('_view')) {
            return;
        }

        $homePageId = (int) TheliaCMS::getConfigValue('home_page_id', 0);

        if ($homePageId <= 0) {
            return;
        }

        $locale = $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();

        if (!$this->pages->isReachable($homePageId, $locale)) {
            return;
        }

        $request->attributes->set('_view', TheliaCMS::PAGE_VIEW);
        $request->query->set(TheliaCMS::PAGE_VIEW.'_id', (string) $homePageId);
    }
}
