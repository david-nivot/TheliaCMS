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

namespace TheliaCMS\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\HttpFoundation\RequestPath;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Tools\TokenProvider;

/**
 * Guards every route under `/admin/cms`, including the ones imported from the
 * page builder bundle.
 *
 * There is no Symfony firewall on `/admin` in Thelia (the `access_control`
 * section of security.yaml is commented out), so an admin route is only as
 * protected as the check its own controller performs — and the bundle's
 * controllers perform none by design. A listener on the whole prefix means a
 * new route can never ship unguarded by omission.
 *
 * The same goes for the token of the back office: every request that is not a
 * read (POST, PUT, PATCH, DELETE) must carry it, in the `_token` field of the
 * body or in the `X-CSRF-Token` header, the way the core and the back-office
 * theme send it.
 *
 * A visitor who is not logged in and opens a screen in the browser is sent to
 * the login of the back office, which brings them back to the screen, as the
 * screens of the core do. A script, a write or an administrator without the
 * right gets the 403.
 */
final readonly class CmsAdminGuard
{
    private const string PREFIX = '/admin/cms';

    private const string LOGIN_ROUTE = 'admin.login';

    /**
     * Section of the CMS a URL belongs to. Anything not listed falls back to
     * the page resource, so a route added later is guarded by default rather
     * than left open.
     *
     * @var array<string, string>
     */
    private const SECTION_RESOURCES = [
        'pages' => CmsResources::PAGE,
        // The documents of the catalogue items are written like pages.
        'catalogue' => CmsResources::PAGE,
        // Tags label pages: whoever may edit a page may manage its labels.
        'tags' => CmsResources::PAGE,
        'menus' => CmsResources::MENU,
        'forms' => CmsResources::FORM,
        'media' => CmsResources::MEDIA,
        'settings' => CmsResources::SETTINGS,
        // The skills steer every page the generator writes: a setting of the
        // site by another name.
        'skills' => CmsResources::SETTINGS,
        // So does putting a component away.
        'components' => CmsResources::SETTINGS,
        'custom-code' => CmsResources::CUSTOM_CODE,
        // What the module does, told to whoever edits the pages.
        'documentation' => CmsResources::PAGE,
        // Pasting a script tag onto every page of the site is the custom-code
        // permission by any other name, so it is guarded as such.
        'scripts' => CmsResources::CUSTOM_CODE,
    ];

    /**
     * Screens kept out of the back office: the third-party scripts. What they
     * configure keeps applying (the saved snippets are still written into the
     * pages, and the custom-code permission still governs free HTML in a
     * page); only the screen is closed, to everybody, so it answers 404 rather
     * than a 403 that would say it exists.
     *
     * @var list<string>
     */
    private const array HIDDEN_PREFIXES = ['/admin/cms/scripts'];

    public function __construct(
        private SecurityContext $securityContext,
        private TokenProvider $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: 128)]
    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // Decoded, as the router reads it: compared as the client wrote it,
        // `/admin/c%6Ds/forms` would reach the forms without starting with the
        // prefix.
        $path = RequestPath::decoded($event->getRequest());

        if (self::PREFIX !== $path && !str_starts_with($path, self::PREFIX.'/')) {
            return;
        }

        foreach (self::HIDDEN_PREFIXES as $hidden) {
            if ($hidden === $path || str_starts_with($path, $hidden.'/')) {
                throw new NotFoundHttpException();
            }
        }

        if (null === $this->securityContext->getAdminUser()) {
            if (self::opensAScreen($event->getRequest())) {
                $login = $this->loginUrl($event->getRequest());
                $event->setController(static fn (): RedirectResponse => new RedirectResponse($login));

                return;
            }

            throw new AccessDeniedHttpException('Administrator authentication is required.');
        }

        // Read access is the floor; the controller performing a write checks
        // CREATE/UPDATE/DELETE on the same resource.
        if (!$this->securityContext->isGranted(['ADMIN'], [$this->resourceFor($path)], [], [AccessManager::VIEW])) {
            throw new AccessDeniedHttpException('You are not allowed to access the CMS section.');
        }

        // Throws TokenAuthenticationException, which the back-office theme
        // answers with a 403 (JSON for a script).
        if (!$event->getRequest()->isMethodSafe()) {
            $this->tokens->checkRequestToken($event->getRequest());
        }
    }

    /**
     * A page the browser navigates to: read with GET, asking for HTML, not sent
     * by a script. The scripts of the screens ask for JSON or send
     * `X-Requested-With`, and a redirect would hand them the login page.
     */
    private static function opensAScreen(Request $request): bool
    {
        return \in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && !$request->isXmlHttpRequest()
            && \in_array('text/html', $request->getAcceptableContentTypes(), true);
    }

    /**
     * The login screen, told where to come back to, the way the screens of the
     * back-office theme send there.
     */
    private function loginUrl(Request $request): string
    {
        return $this->urls->generate(self::LOGIN_ROUTE, ['_target_path' => $request->getRequestUri()]);
    }

    private function resourceFor(string $path): string
    {
        $section = explode('/', trim(substr($path, \strlen(self::PREFIX)), '/'))[0] ?? '';

        return self::SECTION_RESOURCES[$section] ?? CmsResources::PAGE;
    }
}
