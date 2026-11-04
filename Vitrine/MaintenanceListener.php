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

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Http\ContentSecurityPolicyListener;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\IpAllowlist;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * Closes the site while it is being worked on.
 *
 * Answering 503 with `Retry-After` is the whole point: a 200 with "back soon" on
 * it gets indexed in place of the real page, and a 404 tells a crawler the page
 * is gone. The back office stays reachable, an administrator who is already
 * logged in browses the site normally, and the addresses on the allow list get
 * through — which is how the work being done can be checked before reopening.
 *
 * Runs before the router on purpose: a URL that does not resolve has to answer
 * 503 as well, not 404.
 */
final readonly class MaintenanceListener
{
    /**
     * Paths that are never closed: the back office, because it is where the site
     * is reopened from, and the development tooling.
     *
     * @var list<string>
     */
    private const array ALWAYS_OPEN = ['/admin', '/_wdt', '/_profiler', '/_error', '/_fragment'];

    public function __construct(
        private CmsSettings $settings,
        private IpAllowlist $allowlist,
        private TranslatorInterface $translator,
        private SecurityContext $securityContext,
        private PublishedPageRepository $pages,
        private CmsPageRenderer $renderer,
        private LangService $langService,
        private Environment $twig,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 40)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($this->isAlwaysOpen($request) || !$this->settings->isMaintenanceActive()) {
            return;
        }

        if ($this->allowlist->contains($this->settings->maintenanceAllowlist(), $request->getClientIp())) {
            return;
        }

        // An administrator already signed in sees the site as it is: they are
        // the one working on it.
        if (null !== $this->securityContext->getAdminUser()) {
            return;
        }

        ContentSecurityPolicyListener::mark($request);
        $event->setResponse($this->closedResponse());
    }

    private function isAlwaysOpen(Request $request): bool
    {
        $path = $request->getPathInfo();

        foreach (self::ALWAYS_OPEN as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function closedResponse(): Response
    {
        $response = new Response($this->body(), Response::HTTP_SERVICE_UNAVAILABLE);

        $response->headers->set('Retry-After', (string) CmsSettings::RETRY_AFTER);
        // Never store a closed site: a cache holding this page keeps serving it
        // after the site reopens.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function body(): string
    {
        $pageId = $this->settings->maintenancePageId();

        if (null !== $pageId) {
            $locale = $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
            $page = $this->pages->find($pageId, $locale);

            if (null !== $page) {
                try {
                    return $this->renderer->render($page);
                } catch (\Throwable) {
                    // The theme is part of what may be broken while the site is
                    // closed, so a failure here falls through to the plain page
                    // below rather than turning a 503 into a 500.
                }
            }
        }

        $minutes = (int) round(CmsSettings::RETRY_AFTER / 60);

        // Translated here rather than by a Twig filter in the template: the
        // filter follows the locale Symfony resolved for the request, and a
        // front-office page follows the language the visitor is reading the
        // site in. On this page the two are rarely the same, since the request
        // never reached the router.
        return $this->twig->render('@TheliaCMSModule/front/maintenance.html.twig', [
            'locale' => $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale(),
            'title' => $this->translator->trans('The site is closed for maintenance', [], TheliaCMS::DOMAIN_NAME),
            'message' => str_replace(
                '%minutes%',
                (string) $minutes,
                $this->translator->trans('Please come back in about %minutes% minutes.', [], TheliaCMS::DOMAIN_NAME),
            ),
        ]);
    }
}
