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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\SecurityContext;
use Thelia\Tools\TokenProvider;
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponentCall;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Site\SiteComponentStyles;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Content\Site\SiteTemplateRenderer;
use TheliaCMS\Generation\Admin\TypedComponentProps;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Http\ContentSecurityPolicyListener;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PublishedPage;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * Draws a component of the catalogue, alone on a page of the theme: what the
 * frame of the back office shows of it, with its sample, or with the props
 * the editor typed in the controls of its page.
 *
 * A component the site made prints its stylesheet in the page, scoped like
 * the served one: a candidate has no served stylesheet yet.
 *
 * Typed props arrive by POST, from the form of the page of the component, and
 * are read as the content screen reads a section. The signed address says the
 * frame may be drawn; it is not enough to post to it: an administrator must be
 * signed in, and the token of the back office the form carries is checked as
 * the back office checks it.
 */
final readonly class ComponentPreviewController
{
    private const string REFUSAL_TEMPLATE = '@TheliaCMSModule/front/component-preview-refusal.html.twig';

    public function __construct(
        private ComponentPreviewLink $links,
        private ComponentCandidates $candidates,
        private SectionCatalog $catalog,
        private SiteComponentRepository $components,
        private SitePropReader $props,
        private SiteTemplateRenderer $renderer,
        private SiteComponentStyles $styles,
        private CmsPageRenderer $pages,
        private EmbedFacade $facade,
        private TypedComponentProps $typedProps,
        private TokenProvider $tokens,
        private SecurityContext $security,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    #[Route(
        '/cms-component-preview/{subject}/{locale}/{expires}/{signature}',
        name: 'cms.component.preview',
        requirements: ['subject' => ComponentPreviewLink::SUBJECT_PATTERN, 'locale' => '[a-z]{2}_[A-Z]{2}', 'expires' => '\d+', 'signature' => '[a-f0-9]{64}'],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $subject, string $locale, int $expires, string $signature): Response
    {
        if (!$this->links->isValid($subject, $locale, $expires, $signature)) {
            throw new NotFoundHttpException();
        }

        $typed = $request->isMethod('POST');

        if ($typed) {
            $this->denyUnlessTheBackOfficeSent($request);
        }

        $definition = $this->definition($subject) ?? throw new NotFoundHttpException();

        try {
            $props = $typed ? $this->typedProps->read($request, $definition) : $definition->normalizeProps($definition->sample());
        } catch (InvalidDocumentException $exception) {
            return $this->refusal($request, $exception, $locale);
        }

        $html = $this->stylesheet($definition).$definition->render($props, $locale);

        $page = new PublishedPage(
            id: 0,
            locale: $locale,
            title: $definition->label(),
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

        return $this->protect($request, $response);
    }

    /**
     * An administrator signed in, with the token the guard of `/admin/cms`
     * asks of every write. The token alone is not enough: any session may
     * hold one, a page of the shop that posts a form gives it to a visitor.
     */
    private function denyUnlessTheBackOfficeSent(Request $request): void
    {
        if (!$this->security->hasAdminUser()) {
            throw new AccessDeniedHttpException('Only an administrator draws a component with props of their own.');
        }

        try {
            $this->tokens->checkRequestToken($request);
        } catch (TokenAuthenticationException) {
            throw new AccessDeniedHttpException('The token of the back office is missing or wrong.');
        }
    }

    /**
     * What the props are refused for, in the language of the editor, in a
     * small page of the frame: never a 500.
     */
    private function refusal(Request $request, InvalidDocumentException $refusal, string $locale): Response
    {
        // The frame is a page of the front office: its translator speaks the
        // language of the visitor, and the editor reads the one of the screen.
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }

        $response = new Response($this->twig->render(self::REFUSAL_TEMPLATE, [
            'locale' => $locale,
            'title' => $this->translator->trans('This preview cannot be drawn', [], TheliaCMS::DOMAIN_NAME, $locale),
            'problems' => $refusal->translated($this->translator),
        ]), Response::HTTP_UNPROCESSABLE_ENTITY);

        return $this->protect($request, $response);
    }

    private function protect(Request $request, Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        ContentSecurityPolicyListener::mark($request);

        return $response;
    }

    /**
     * A component made on this site comes with the stylesheets of the
     * components it draws; the other origins have theirs in the theme.
     * Inline, a `<` could close the element: the validator refuses one, and
     * an older row is escaped all the same. A candidate is not in the served
     * stylesheet yet, and the preview prints its own only.
     */
    private function stylesheet(SectionDefinitionInterface $definition): string
    {
        if (!$definition instanceof SiteComponentSection) {
            return '';
        }

        $component = $definition->component;
        $css = str_replace('<', '\\3c ', implode("\n", array_unique(array_filter([
            $this->styles->scoped($component),
            ...array_map(
                fn (SiteComponentCall $call): string => null === ($called = $this->components->kept($call->code)) ? '' : $this->styles->scoped($called),
                $this->renderer->calls($component->template),
            ),
        ]))));

        return '' === $css ? '' : '<style>'.$css.'</style>';
    }

    private function definition(string $subject): ?SectionDefinitionInterface
    {
        if (str_starts_with($subject, 'section-')) {
            return $this->catalog->find(substr($subject, \strlen('section-')));
        }

        $component = $this->candidates->find((int) substr($subject, \strlen('candidate-')))?->component;

        if (null === $component) {
            return null;
        }

        try {
            return new SiteComponentSection($component, $this->props->read($component->props), $this->renderer, facade: $this->facade);
        } catch (InvalidSiteComponentException) {
            return null;
        }
    }
}
