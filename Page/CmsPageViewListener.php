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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Http\ContentSecurityPolicyListener;
use TheliaCMS\Http\PageCacheListener;
use TheliaCMS\TheliaCMS;

/**
 * Serves a rewritten CMS page URL.
 *
 * Priority 4 sits between the home swap (5) and the Page module (3), so a page
 * the CMS owns is never handed over to another view provider, and the core
 * renderer (0) is never reached because a response is set here.
 */
final readonly class CmsPageViewListener
{
    public function __construct(
        private LangService $langService,
        private PublishedPageRepository $pages,
        private CmsPageRenderer $renderer,
    ) {
    }

    #[AsEventListener(event: KernelEvents::VIEW, priority: 4)]
    public function onKernelView(ViewEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (TheliaCMS::PAGE_VIEW !== $request->attributes->get('_view')) {
            return;
        }

        // RewritingRouter writes `<view>_id` into the QUERY bag, not into the
        // attributes — reading it from the attributes (as the core VIEW_CHECK
        // event does) always yields null on a rewritten URL.
        $pageId = $request->query->getInt(TheliaCMS::PAGE_VIEW.'_id');

        if ($pageId <= 0) {
            throw new NotFoundHttpException();
        }

        // The home page is reachable both on "/" and on its own slug. Serving
        // the same content twice is duplicate content, so the slug redirects.
        if ($pageId === (int) TheliaCMS::getConfigValue('home_page_id', 0) && '/' !== $request->getPathInfo()) {
            $event->setResponse(new RedirectResponse('/', Response::HTTP_MOVED_PERMANENTLY));

            return;
        }

        $locale = $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
        $page = $this->pages->find($pageId, $locale);

        if (null === $page) {
            // Serving another locale under this URL would index the wrong
            // language, so a page not published in the requested locale is a
            // 404, not a fallback.
            throw new NotFoundHttpException();
        }

        if (null !== $page->canonical) {
            $request->attributes->set(TheliaCMS::PAGE_CANONICAL_ATTRIBUTE, $page->canonical);
        }

        $request->attributes->set(TheliaCMS::SERVED_PAGE_ATTRIBUTE, $pageId);

        $response = new Response($this->renderer->render($page));

        if ($page->noindex) {
            $response->headers->set('X-Robots-Tag', 'noindex'.($page->nofollow ? ', nofollow' : ''));
        }

        // The headers are written on kernel.response, once the session
        // listener has had its say about cookies: whether this answer may be
        // shared depends on them.
        $request->attributes->set(PageCacheListener::TAGS_ATTRIBUTE, CacheTags::forPage($pageId));
        ContentSecurityPolicyListener::mark($request);

        $event->setResponse($response);
    }
}
