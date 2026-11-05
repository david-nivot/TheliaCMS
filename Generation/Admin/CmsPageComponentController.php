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

namespace TheliaCMS\Generation\Admin;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationTargets;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;

/**
 * A component prototyped from the content screen of a page: asked in a
 * prompt, drawn in the preview of the screen in place of the draft, changed
 * by asking again, then kept as a prototype and added at the end of the
 * draft with its sample. A prototype is in the catalogue for the pages that
 * hold it; CMS > Components keeps it for the whole site.
 *
 * The candidate is a row of the ledger, as on the components screen; the
 * content screen shows it when its address names it (`?component=<id>`).
 * Making one takes the right to generate and the rights a component of the
 * site takes (settings and custom code): it is markup and a stylesheet.
 */
#[Route('/admin/cms/pages/{id}/content/component', name: 'admin.cms.pages.content.component.', requirements: ['id' => '\d+'])]
final readonly class CmsPageComponentController
{
    /** The query parameter of the content screen that names the candidate on show. */
    public const string PARAMETER = 'component';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private TranslatorInterface $translator,
        private CmsPageAdminRepository $pages,
        private EditLanguage $languages,
        private ComponentGenerator $generator,
        private ComponentCandidates $candidates,
        private SiteComponentRepository $components,
        private SiteComponentValidator $validator,
        private SiteComponentPermission $permission,
        private SectionCatalog $catalog,
        private CmsPageWriter $writer,
        private GenerationLog $log,
        private GenerationTargets $targets,
    ) {
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPrototype();

        $text = trim((string) $request->request->get('request', ''));
        $screen = $this->screenUrl($page, $lang);

        if ('' === $text) {
            $this->support->flash($request, 'danger', $this->support->translate('Say what the component is for.'));

            return new RedirectResponse($screen);
        }

        $generation = $this->requestFor($request, $text, $lang->getLocale());

        // A call is as long as the model takes; the limit of a request of the
        // back office is not meant for it.
        set_time_limit(0);

        if (str_contains((string) $request->headers->get('Accept'), 'text/event-stream')) {
            return $this->stream($generation, $screen);
        }

        try {
            $result = $this->generator->generate($generation);
        } catch (GenerationException $exception) {
            if (null === $exception->logId) {
                $this->support->flash($request, 'danger', $exception->getMessage());

                return new RedirectResponse($screen);
            }

            return new RedirectResponse(self::withCandidate($screen, $exception->logId));
        }

        return new RedirectResponse(self::withCandidate($screen, $result->logId));
    }

    /**
     * The candidate kept as a prototype and added at the end of the draft
     * with its sample, which is then edited by hand like any section.
     */
    #[Route('/keep', name: 'keep', methods: ['POST'])]
    public function keep(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPrototype();

        $locale = $lang->getLocale();
        $screen = $this->screenUrl($page, $lang);
        $candidate = $this->candidates->find((int) $request->request->get('candidate', 0));

        // A candidate of this screen is always a new component: a change of
        // a kept one is asked on the components screen.
        if (null === $candidate?->component || $candidate->componentId > 0) {
            throw new NotFoundHttpException();
        }

        try {
            $component = $this->validator->validate($candidate->component);
            $this->components->save($component, SiteComponentRepository::STATUS_DRAFT);
        } catch (InvalidSiteComponentException $exception) {
            $this->support->flash($request, 'danger', implode(' ', $exception->problems));

            return new RedirectResponse(self::withCandidate($screen, $candidate->logId));
        }

        $this->candidates->attach([$candidate->logId], $this->components->idOf($component->code));

        // The catalogue of this request was built before the row existed.
        $this->catalog->reset();
        $definition = $this->catalog->find($component->name()) ?? throw new \LogicException(\sprintf('The component %s was saved and is not in the catalogue.', $component->name()));
        $current = $this->pages->draftDocument($page, $locale) ?? new Document([]);

        try {
            $section = new Section($current->freeSectionId($component->name()), $component->name(), $definition->normalizeProps($definition->sample()));
            $document = $current->withSectionAppended($section);
            $this->writer->saveContent($page, $locale, $document);
        } catch (InvalidDocumentException $exception) {
            foreach ($exception->translated($this->translator) as $problem) {
                $this->support->flash($request, 'danger', $problem);
            }

            return new RedirectResponse($screen);
        }

        $this->log->recordManual($this->targets->forPage($page, $locale), $locale, $document->toJson(), \sprintf('Section "%s" added from the component prototyped on this page (%s).', $section->id, $component->name()));
        $this->support->flash($request, 'success', $this->support->translate('"%label%" is a prototype of this site, added at the end of the draft with example text: edit it by hand. CMS > Components keeps it for the whole site.', ['%label%' => $component->label]));

        return new RedirectResponse($screen);
    }

    public static function withCandidate(string $url, int $logId): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').self::PARAMETER.'='.$logId;
    }

    /**
     * The call as server-sent events, for the script of the screen: the
     * same events as a page being written, then the address of the screen
     * with the candidate, where the outcome is read from the ledger.
     */
    private function stream(ComponentRequest $generation, string $screen): StreamedResponse
    {
        $events = new GenerationStream(
            fn (string $message, array $parameters): string => $this->support->translate($message, $parameters),
            [
                'answered' => 'The model answered: the site is checking the component.',
                'refused' => 'The component was refused by the site and sent back for correction.',
            ],
        );

        return new StreamedResponse(function () use ($events, $generation, $screen): void {
            // The editor closing the tab must not kill a call the site pays
            // for: it runs to its end and is written to the ledger.
            ignore_user_abort(true);

            $events->start($this->support->translate('The generator is reading the request.'));

            try {
                $result = $this->generator->generate($generation, $events);
            } catch (GenerationException $exception) {
                $events->error($exception->getMessage(), null === $exception->logId ? null : self::withCandidate($screen, $exception->logId));

                return;
            }

            $events->done(self::withCandidate($screen, $result->logId), $this->support->translate('The component is written.'));
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * A new component, or a change of the candidate on show.
     */
    private function requestFor(Request $request, string $text, string $locale): ComponentRequest
    {
        $candidateId = (int) $request->request->get('candidate', 0);

        if ($candidateId <= 0) {
            return new ComponentRequest($text, $locale);
        }

        $candidate = $this->candidates->find($candidateId);

        if (null === $candidate?->component || $candidate->componentId > 0) {
            throw new NotFoundHttpException();
        }

        return new ComponentRequest($text, $locale, $candidate->component);
    }

    private function denyUnlessMayPrototype(): void
    {
        $this->support->denyUnless(CmsResources::GENERATE, AccessManager::UPDATE, 'You are not allowed to use the generator.');

        if (!$this->permission->mayMake()) {
            throw new AccessDeniedHttpException($this->support->translate('Making a component takes the settings and the custom code permissions.'));
        }
    }

    private function livePageOrFail(int $id): CmsPage
    {
        return $this->pages->findLive($id) ?? throw new NotFoundHttpException();
    }

    private function screenUrl(CmsPage $page, Lang $lang): string
    {
        return $this->urls->generate('admin.cms.pages.content.edit', ['id' => $page->getId(), EditLanguage::PARAMETER => $lang->getId()]);
    }
}
