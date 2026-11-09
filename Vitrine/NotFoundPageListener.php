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

namespace TheliaCMS\Vitrine;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Http\ContentSecurityPolicyListener;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\TheliaCMS;

/**
 * Serves a CMS page when an address does not exist, so the 404 of a site is
 * written by the person who writes the site.
 *
 * Runs *above* the core listener that renders the theme's 404, because
 * `ExceptionEvent::setResponse()` stops propagation: whichever listener answers
 * first is the only one that answers at all. Falling back is therefore done by
 * returning early rather than by letting a later listener win.
 *
 * The page is served with the 404 status: a soft 404 answering 200 is worse than
 * no custom page at all.
 */
final readonly class NotFoundPageListener
{
    /** Where the rewriting router leaves the id of the page an address points at. */
    private const string PAGE_ID_PARAMETER = TheliaCMS::PAGE_VIEW.'_id';

    public function __construct(
        private CmsSettings $settings,
        private PublishedPageRepository $pages,
        private CmsPageRenderer $renderer,
        private LangService $langService,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 132)]
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $request = $event->getRequest();

        // The back office and the API have their own answers, and neither wants
        // a themed page. Read as a first segment: a page addressed
        // `/administration-des-ventes` is not the back office.
        if (ReservedPaths::isReserved($request->getPathInfo())) {
            return;
        }

        $pageId = $this->settings->notFoundPageId();

        if (null === $pageId) {
            return;
        }

        $locale = $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
        $page = $this->pages->find($pageId, $locale);

        // Not published in this language: the theme's 404 is the honest
        // fallback, rather than a page in the wrong language.

        if (null === $page) {
            return;
        }

        $requestedView = $this->currentView($request);
        $this->serveAs($request, $pageId);

        try {
            $body = $this->renderer->render($page);
        } catch (\Throwable $throwable) {
            $this->restoreView($request, $requestedView);

            // A 404 that turns into a 500 loses both the page and the status, so
            // the theme keeps the hand — but silently swallowing the reason is
            // how a broken 404 page stays broken.
            $this->logger->warning('The CMS page configured for missing addresses could not be rendered: {reason}', [
                'reason' => $throwable->getMessage(),
                'page' => $pageId,
                'exception' => $throwable,
            ]);

            return;
        }

        $response = new Response($body, Response::HTTP_NOT_FOUND);
        $response->headers->set('X-Robots-Tag', 'noindex');
        ContentSecurityPolicyListener::mark($request);

        $event->setResponse($response);
    }

    /**
     * Says which page is being served, so that what describes it agrees with
     * it.
     *
     * The breadcrumb, its BreadcrumbList and the title are resolved from the
     * request rather than from what was rendered. An address that used to hold
     * a page still carries its id, so without this the page a visitor is shown
     * is the 404 page while everything around it announces the page they asked
     * for. Request attributes win over the query string, which is where the
     * rewriting router leaves the id.
     *
     * The id is written to the query string as well, because the services that
     * answer "which page is this" read that one explicitly rather than through
     * `Request::get()` — the canonical URL, the hreflang tags and the language
     * switcher all come from there.
     *
     * The marker is what tells them the response is a stand-in: on any other
     * CMS page the address asked for and the page served are the same, and
     * nothing should second-guess SEOne.
     */
    private function serveAs(Request $request, int $pageId): void
    {
        $request->attributes->set('_view', TheliaCMS::PAGE_VIEW);
        $request->attributes->set(self::PAGE_ID_PARAMETER, $pageId);
        $request->attributes->set(TheliaCMS::STAND_IN_PAGE_ATTRIBUTE, $pageId);
        $request->attributes->set(TheliaCMS::SERVED_PAGE_ATTRIBUTE, $pageId);
        $request->query->set(self::PAGE_ID_PARAMETER, (string) $pageId);
    }

    /** @return array{view: string|null, id: mixed, queryId: mixed} */
    private function currentView(Request $request): array
    {
        return [
            'view' => $request->attributes->get('_view'),
            'id' => $request->attributes->get(self::PAGE_ID_PARAMETER),
            'queryId' => $request->query->get(self::PAGE_ID_PARAMETER),
        ];
    }

    /**
     * @param array{view: string|null, id: mixed, queryId: mixed} $previous
     */
    private function restoreView(Request $request, array $previous): void
    {
        // The theme is about to render its own page: naming one that was never
        // rendered would only move the lie somewhere else.
        foreach (['_view' => $previous['view'], self::PAGE_ID_PARAMETER => $previous['id']] as $key => $value) {
            null === $value ? $request->attributes->remove($key) : $request->attributes->set($key, $value);
        }

        $request->attributes->remove(TheliaCMS::STAND_IN_PAGE_ATTRIBUTE);
        $request->attributes->remove(TheliaCMS::SERVED_PAGE_ATTRIBUTE);

        null === $previous['queryId']
            ? $request->query->remove(self::PAGE_ID_PARAMETER)
            : $request->query->set(self::PAGE_ID_PARAMETER, $previous['queryId']);
    }
}
