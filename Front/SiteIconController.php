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

namespace TheliaCMS\Front;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use TheliaCMS\Settings\SiteIcon;

/**
 * Serves the icon uploaded in the store configuration to visitors.
 *
 * A theme points its `<link rel="icon">` here, and changing the icon of a site
 * becomes an upload in the back office rather than a file in the theme.
 */
final readonly class SiteIconController
{
    public function __construct(
        private SiteIcon $icon,
    ) {
    }

    #[Route('/site-icon', name: 'cms.site_icon', methods: ['GET'])]
    public function __invoke(): Response
    {
        $path = $this->icon->path();

        if (null === $path) {
            throw new NotFoundHttpException('No icon is configured for this site.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', (string) $this->icon->mimeTypeOf($path));

        // An SVG icon is a document that may carry a script, and this address
        // is public. Opened on its own, it never gets the origin of the site:
        // no script, no cookie, no storage. A raster icon is unaffected.
        $response->headers->set('Content-Security-Policy', 'sandbox');

        // The same file for everybody, asked for on every page: it is cached
        // for a day, and a browser that already has it revalidates on the date.
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->setAutoLastModified();

        // Without this, the session listener rewrites the header above to
        // `private` on the way out, because a session was opened during the
        // request. Symfony strips the marker itself before sending.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
