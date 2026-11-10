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
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Site\SiteComponentStyles;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Content\Site\SiteTemplateRenderer;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Http\ContentSecurityPolicyListener;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PublishedPage;

/**
 * Draws a component of the site with its sample, alone on a page of the
 * theme: what the frame of the back office shows before the editor keeps it.
 *
 * Its stylesheet is printed in the page, scoped like the served one: a
 * candidate has no served stylesheet yet.
 */
final readonly class ComponentPreviewController
{
    public function __construct(
        private ComponentPreviewLink $links,
        private ComponentCandidates $candidates,
        private SiteComponentRepository $components,
        private SitePropReader $props,
        private SiteTemplateRenderer $renderer,
        private SiteComponentStyles $styles,
        private CmsPageRenderer $pages,
        private EmbedFacade $facade,
    ) {
    }

    #[Route(
        '/cms-component-preview/{subject}/{locale}/{expires}/{signature}',
        name: 'cms.component.preview',
        requirements: ['subject' => ComponentPreviewLink::SUBJECT_PATTERN, 'locale' => '[a-z]{2}_[A-Z]{2}', 'expires' => '\d+', 'signature' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $subject, string $locale, int $expires, string $signature): Response
    {
        if (!$this->links->isValid($subject, $locale, $expires, $signature)) {
            throw new NotFoundHttpException();
        }

        $component = $this->component($subject);

        if (null === $component) {
            throw new NotFoundHttpException();
        }

        try {
            $schema = $this->props->read($component->props);
        } catch (InvalidSiteComponentException) {
            throw new NotFoundHttpException();
        }

        $section = new SiteComponentSection($component, $schema, $this->renderer, facade: $this->facade);
        // Inline, a `<` could close the element: the validator refuses one,
        // and an older row is escaped all the same.
        $css = str_replace('<', '\\3c ', $this->styles->scoped($component));
        $html = ('' === $css ? '' : '<style>'.$css.'</style>').$section->render($section->normalizeProps($component->sample), $locale);

        $page = new PublishedPage(
            id: 0,
            locale: $locale,
            title: $component->label,
            pageType: PageTypeCode::DEFAULT,
            html: '<div class="cms-page-content">'.$html.'</div>',
            noindex: true,
            nofollow: true,
        );

        // The component in a whole page of the theme, or alone, as the
        // preview of a page draft offers.
        $response = new Response($request->query->getBoolean(PreviewLink::CONTENT_ONLY)
            ? $this->pages->renderContent($page)
            : $this->pages->render($page));
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        ContentSecurityPolicyListener::mark($request);

        return $response;
    }

    private function component(string $subject): ?SiteComponent
    {
        if (str_starts_with($subject, 'candidate-')) {
            return $this->candidates->find((int) substr($subject, \strlen('candidate-')))?->component;
        }

        return $this->components->find(substr($subject, \strlen('component-')));
    }
}
