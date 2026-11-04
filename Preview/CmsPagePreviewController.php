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

namespace TheliaCMS\Preview;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use TheliaCMS\Http\ContentSecurityPolicyListener;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\PublishedPage;
use TheliaCMS\Page\PublishedPageRepository;

/**
 * Shows the draft of a page to whoever holds a signed link.
 *
 * The route is public by design — a client reviewing a page has no
 * back-office account — so the signature is the whole authorisation, and an
 * unsigned or expired link is a plain 404 rather than an invitation to guess.
 */
final readonly class CmsPagePreviewController
{
    public function __construct(
        private PreviewLink $links,
        private PublishedPageRepository $pages,
        private CmsPageRenderer $renderer,
    ) {
    }

    #[Route(
        '/cms-preview/{id}/{locale}/{expires}/{signature}',
        name: 'cms.page.preview',
        requirements: ['id' => '\d+', 'locale' => '[a-z]{2}_[A-Z]{2}', 'expires' => '\d+', 'signature' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, int $id, string $locale, int $expires, string $signature): Response
    {
        if (!$this->links->isValid($id, $locale, $expires, $signature)) {
            throw new NotFoundHttpException();
        }

        $page = $this->pages->draft($id, $locale);

        if (!$page instanceof PublishedPage) {
            throw new NotFoundHttpException();
        }

        $response = new Response($request->query->getBoolean(PreviewLink::CONTENT_ONLY)
            ? $this->renderer->renderContent($page)
            : $this->renderer->render($page));

        // A draft must leave no trace: not in a search index, not in a shared
        // cache, not in the browser's history cache either.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        ContentSecurityPolicyListener::mark($request);

        return $response;
    }
}
