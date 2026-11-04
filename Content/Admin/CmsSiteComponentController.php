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

namespace TheliaCMS\Content\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\SectionAvailability;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Content\Site\SitePropRows;
use TheliaCMS\Generation\Admin\GenerationStream;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Model\CmsComponentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\DraftRendering;
use TheliaCMS\Preview\ComponentPreviewLink;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * The components the site makes in the back office: asked for in a prompt,
 * looked at in the theme, changed by asking again, then kept.
 *
 * A candidate is a row of the ledger: what the generator wrote, never saved
 * anywhere else until the editor keeps it. Seeing them takes the settings
 * permission (CmsAdminGuard holds the path to it); making, changing and
 * keeping one takes the custom code permission too, since a component writes
 * markup and styles shown on every page that uses it.
 */
#[Route('/admin/cms/components/site', name: 'admin.cms.components.site.')]
final readonly class CmsSiteComponentController
{
    /** The code of a component of the site, as its addresses carry it. */
    private const string CODE = '[A-Z][A-Za-z0-9]{1,39}';

    private const string TEMPLATES = '@TheliaCMSModule/backOffice/default-twig/components/site/';

    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private ComponentGenerator $generator,
        private ComponentCandidates $candidates,
        private SiteComponentRepository $components,
        private SiteComponentValidator $validator,
        private SitePropReader $props,
        private SectionAvailability $availability,
        private SectionUsageFinder $usage,
        private ComponentPreviewLink $previews,
        private SiteComponentUsage $pagesUsing,
        private CmsPageWriter $writer,
        private SiteComponentPermission $permission,
        private SitePropRows $propRows,
    ) {
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('new.html.twig', [
            'may_ask' => $this->permission->mayAsk(),
        ]);
    }

    /**
     * A new component, a change of a candidate (`candidate`, the id of its
     * row of the ledger) or a change of a kept component (`component`, its
     * code). Every call ends on the screen of its candidate, which reads the
     * outcome from the ledger, a failure included.
     */
    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request): Response
    {
        if (!$this->permission->mayAsk()) {
            throw new AccessDeniedHttpException($this->translate('Asking the generator for a component takes the generator, the settings and the custom code permissions.'));
        }

        $text = trim((string) $request->request->get('request', ''));
        $back = $this->backTo($request);

        if ('' === $text) {
            $this->flash($request, 'danger', $this->translate('Say what the component is for.'));

            return new RedirectResponse($back);
        }

        $generation = $this->requestFor($request, $text);

        // A call is as long as the model takes; the limit of a request of the
        // back office is not meant for it.
        set_time_limit(0);

        if (str_contains((string) $request->headers->get('Accept'), 'text/event-stream')) {
            return $this->stream($generation);
        }

        try {
            $result = $this->generator->generate($generation);
        } catch (GenerationException $exception) {
            if (null === $exception->logId) {
                $this->flash($request, 'danger', $exception->getMessage());

                return new RedirectResponse($back);
            }

            return new RedirectResponse($this->urls->generate('admin.cms.components.site.candidate', ['id' => $exception->logId]));
        }

        return new RedirectResponse($this->urls->generate('admin.cms.components.site.candidate', ['id' => $result->logId]));
    }

    /**
     * The call as server-sent events, for the script of the screen: the
     * same events as a page being written, then the address of the
     * candidate, where the outcome is read from the ledger.
     */
    private function stream(ComponentRequest $generation): StreamedResponse
    {
        $events = new GenerationStream(
            fn (string $message, array $parameters): string => $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME),
            [
                'answered' => 'The model answered: the site is checking the component.',
                'refused' => 'The component was refused by the site and sent back for correction.',
            ],
        );

        return new StreamedResponse(function () use ($events, $generation): void {
            // The editor closing the tab must not kill a call the site pays
            // for: it runs to its end and is written to the ledger.
            ignore_user_abort(true);

            $events->start($this->translate('The generator is reading the request.'));

            try {
                $result = $this->generator->generate($generation, $events);
            } catch (GenerationException $exception) {
                $events->error($exception->getMessage(), null === $exception->logId ? null : $this->urls->generate('admin.cms.components.site.candidate', ['id' => $exception->logId]));

                return;
            }

            $events->done($this->urls->generate('admin.cms.components.site.candidate', ['id' => $result->logId]), $this->translate('The component is written.'));
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    #[Route('/candidates/{id}', name: 'candidate', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function candidate(int $id, Request $request): Response
    {
        $candidate = $this->candidates->find($id) ?? throw new NotFoundHttpException();
        $kept = $candidate->componentId > 0 ? CmsComponentQuery::create()->findPk($candidate->componentId)?->getCode() : null;

        return $this->render('candidate.html.twig', [
            'candidate' => $candidate,
            'component' => $candidate->component,
            'props' => null === $candidate->component ? [] : $this->propRows->of($candidate->component),
            'preview_url' => null === $candidate->component ? null : $this->previews->candidate($candidate->logId, $request->getLocale()),
            'kept_code' => $kept,
            'pages' => null === $kept ? 0 : ($this->usage->pageCounts()['Site:'.$kept] ?? 0),
            'may_make' => $this->mayMake(),
            'may_ask' => $this->permission->mayAsk(),
            'failed' => GenerationLog::STATUS_DONE !== $candidate->status,
        ]);
    }

    #[Route('/candidates/{id}/keep', name: 'keep', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function keep(int $id, Request $request): Response
    {
        $this->denyUnlessMayMake();

        $candidate = $this->candidates->find($id) ?? throw new NotFoundHttpException();
        $screen = $this->urls->generate('admin.cms.components.site.candidate', ['id' => $id]);

        if (null === $candidate->component) {
            throw new NotFoundHttpException();
        }

        $replacing = $candidate->componentId > 0 ? CmsComponentQuery::create()->findPk($candidate->componentId)?->getCode() : null;

        try {
            $component = $this->validator->validate($candidate->component);

            // The pages may have changed since the candidate was written: what
            // they hold now is what the version must still take.
            if (null !== $replacing && [] !== $breakages = $this->pagesUsing->breakages($component, $this->props->read($component->props))) {
                throw InvalidSiteComponentException::because($breakages);
            }

            $this->components->save($component, SiteComponentRepository::STATUS_READY, $replacing);
        } catch (InvalidSiteComponentException $exception) {
            $this->flash($request, 'danger', implode(' ', $exception->problems));

            return new RedirectResponse($screen);
        }

        $componentId = (int) CmsComponentQuery::create()->findOneByCode($component->code)?->getId();

        if (null === $replacing) {
            $this->candidates->attach([$candidate->logId], $componentId);
        }

        if (null === $replacing) {
            $this->flash($request, 'success', $this->translator->trans('"%label%" is a component of the site: pages may use it, and the generator is offered it.', ['%label%' => $component->label], TheliaCMS::DOMAIN_NAME));
        } else {
            [$rendered, $left] = $this->renderDraftsUsing($component->name());
            $this->flash($request, 'success', $this->translator->trans(
                '"%label%" is changed: %rendered% draft(s) drawn again with it. Published pages change at their next publication.',
                ['%label%' => $component->label, '%rendered%' => $rendered],
                TheliaCMS::DOMAIN_NAME,
            ));

            if ([] !== $left) {
                $this->flash($request, 'warning', $this->translator->trans('Drafts left as they were, to save from their screen: %pages%', ['%pages%' => implode(', ', $left)], TheliaCMS::DOMAIN_NAME));
            }
        }

        return new RedirectResponse($this->urls->generate('admin.cms.components.site.show', ['component' => $component->code]));
    }

    #[Route('/{component}', name: 'show', requirements: ['component' => self::CODE], methods: ['GET'])]
    public function show(string $component, Request $request): Response
    {
        $kept = $this->components->find($component) ?? throw new NotFoundHttpException();
        $id = (int) CmsComponentQuery::create()->findOneByCode($component)?->getId();

        $prototype = SiteComponentRepository::STATUS_DRAFT === $this->components->status($component);

        return $this->render('component.html.twig', [
            'component' => $kept,
            'props' => $this->propRows->of($kept),
            'preview_url' => $this->previews->component($component, $request->getLocale()),
            'history' => $this->candidates->history($id),
            'pages' => $this->usage->pageCounts()[$kept->name()] ?? 0,
            'prototype' => $prototype,
            'offered' => !$prototype && $this->availability->isOffered($kept->name()),
            'may_make' => $this->mayMake(),
            'may_ask' => $this->permission->mayAsk(),
        ]);
    }

    /**
     * A prototype kept for the whole site: made from a page (by its editor
     * or by the generator writing it), it was in the catalogue for the pages
     * that hold it alone. From here on it is offered like any component.
     */
    #[Route('/{component}/promote', name: 'promote', requirements: ['component' => self::CODE], methods: ['POST'])]
    public function promote(string $component, Request $request): Response
    {
        $this->denyUnlessMayMake();

        $kept = $this->components->find($component) ?? throw new NotFoundHttpException();

        if (SiteComponentRepository::STATUS_DRAFT === $this->components->status($component)) {
            $this->components->promote($component);
            $this->flash($request, 'success', $this->translator->trans('"%label%" is a component of the site: pages may use it, and the generator is offered it.', ['%label%' => $kept->label], TheliaCMS::DOMAIN_NAME));
        }

        return new RedirectResponse($this->urls->generate('admin.cms.components.site.show', ['component' => $component]));
    }

    /**
     * A component copied under a new code, to vary from it: the same
     * template, stylesheet, props, example and mark of provenance, without a
     * call to the generator. The copy is a prototype, offered to nobody
     * until it is changed and kept, or kept for the whole site: the
     * generator is never offered two identical components.
     */
    #[Route('/{component}/copy', name: 'copy', requirements: ['component' => self::CODE], methods: ['POST'])]
    public function copy(string $component, Request $request): Response
    {
        $this->denyUnlessMayMake();

        $source = $this->components->find($component) ?? throw new NotFoundHttpException();
        $code = trim((string) $request->request->get('code', ''));
        $label = trim((string) $request->request->get('label', ''));

        try {
            $copy = $this->validator->validate(new SiteComponent($code, $label, $source->description, $source->props, $source->sample, $source->template, $source->css, $source->provenance));
            $this->components->save($copy, SiteComponentRepository::STATUS_DRAFT);
        } catch (InvalidSiteComponentException $exception) {
            $this->flash($request, 'danger', implode(' ', $exception->problems));

            return new RedirectResponse($this->urls->generate('admin.cms.components.site.show', ['component' => $source->code]));
        }

        $this->flash($request, 'success', $this->translator->trans(
            '"%label%" is a copy of "%source%", kept as a prototype: ask it a change, then keep it for the whole site.',
            ['%label%' => $copy->label, '%source%' => $source->label],
            TheliaCMS::DOMAIN_NAME,
        ));

        return new RedirectResponse($this->urls->generate('admin.cms.components.site.show', ['component' => $copy->code]));
    }

    /**
     * Deleting a component no page holds. One a page holds, draft or online,
     * in any language, stays: the page would hold a section the catalogue no
     * longer knows. Its calls stay in the ledger, which is what the site spent.
     */
    #[Route('/{component}/delete', name: 'delete', requirements: ['component' => self::CODE], methods: ['POST'])]
    public function delete(string $component, Request $request): Response
    {
        $this->denyUnlessMayMake();

        $kept = $this->components->find($component) ?? throw new NotFoundHttpException();
        $pages = $this->usage->pageCounts()[$kept->name()] ?? 0;

        if ($pages > 0) {
            $this->flash($request, 'danger', $this->translator->trans('"%label%" is used by %count% page(s): take it out of them before deleting it.', ['%label%' => $kept->label, '%count%' => $pages], TheliaCMS::DOMAIN_NAME));

            return new RedirectResponse($this->urls->generate('admin.cms.components.site.show', ['component' => $component]));
        }

        $this->components->delete($component);
        // A component switched off and deleted leaves no name behind in the
        // list of the ones put away.
        $this->availability->setOffered($kept->name(), true);
        $this->flash($request, 'success', $this->translator->trans('"%label%" is deleted.', ['%label%' => $kept->label], TheliaCMS::DOMAIN_NAME));

        return new RedirectResponse($this->urls->generate('admin.cms.components.list'));
    }

    /**
     * Draws again the drafts that hold the component, the way
     * `thelia_cms:render` does: a draft whose new rendering would lose custom
     * code its author may not write, or that fails, is left as it was and
     * named.
     *
     * @return array{0: int, 1: list<string>} the drafts drawn again, and the ones left
     */
    private function renderDraftsUsing(string $name): array
    {
        $rendered = 0;
        $left = [];

        foreach ($this->pagesUsing->drafts($name) as $draft) {
            $page = CmsPageQuery::create()->findPk($draft['pageId']);

            if (null === $page) {
                continue;
            }

            try {
                $outcome = $this->writer->renderDraftAgain($page, $draft['locale']);
            } catch (\Throwable) {
                $outcome = null;
            }

            if (DraftRendering::Rendered === $outcome || DraftRendering::Unchanged === $outcome) {
                ++$rendered;
            } else {
                $left[] = \sprintf('#%d (%s)', $draft['pageId'], $draft['locale']);
            }
        }

        return [$rendered, $left];
    }

    private function requestFor(Request $request, string $text): ComponentRequest
    {
        $locale = $request->getLocale();
        $code = (string) $request->request->get('component', '');

        if ('' !== $code) {
            $component = $this->components->find($code) ?? throw new NotFoundHttpException();

            return new ComponentRequest($text, $locale, $component, (int) CmsComponentQuery::create()->findOneByCode($code)?->getId());
        }

        $candidateId = (int) $request->request->get('candidate', 0);

        if ($candidateId > 0) {
            $candidate = $this->candidates->find($candidateId);

            if (null === $candidate?->component) {
                throw new NotFoundHttpException();
            }

            return new ComponentRequest($text, $locale, $candidate->component, $candidate->componentId > 0 ? $candidate->componentId : null);
        }

        return new ComponentRequest($text, $locale);
    }

    /**
     * Where a request that cannot run sends the editor back: the screen it
     * came from.
     */
    private function backTo(Request $request): string
    {
        $code = (string) $request->request->get('component', '');
        $candidateId = (int) $request->request->get('candidate', 0);

        return match (true) {
            1 === preg_match('/^[A-Z][A-Za-z0-9]{1,39}$/', $code) => $this->urls->generate('admin.cms.components.site.show', ['component' => $code]),
            $candidateId > 0 => $this->urls->generate('admin.cms.components.site.candidate', ['id' => $candidateId]),
            default => $this->urls->generate('admin.cms.components.site.new'),
        };
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $template, array $parameters): Response
    {
        $script = __DIR__.'/../../templates/backOffice/default-twig/assets/component-generator.js';

        return new Response($this->twig->render(self::TEMPLATES.$template, $parameters + [
            'assets_version' => substr(hash('xxh3', (string) @filemtime($script)), 0, 12),
        ]));
    }

    private function mayMake(): bool
    {
        return $this->permission->mayMake();
    }

    private function denyUnlessMayMake(): void
    {
        if (!$this->mayMake()) {
            throw new AccessDeniedHttpException($this->translate('Making a component takes the settings and the custom code permissions.'));
        }
    }

    private function flash(Request $request, string $type, string $message): void
    {
        if ($request->hasSession() && method_exists($session = $request->getSession(), 'getFlashBag')) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
