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

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Ai\AiProvenance;
use Thelia\Model\Lang;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\HeadingChecker;
use TheliaCMS\Content\Site\SiteComponentPermission;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GenerationTargets;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Page\Admin\EmptyPageContentException;
use TheliaCMS\Page\Admin\HeadingStructureException;
use TheliaCMS\Page\Admin\MissingPageFieldsException;
use TheliaCMS\Page\Admin\ModelPagePublicationException;
use TheliaCMS\Page\Admin\PlaceholderPageContentException;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Preview\PreviewLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;

/**
 * The content of a page: written by the generator from a brief, changed by
 * asking, read in the theme, published.
 *
 * Opening the screen takes the page right; asking the generator takes the
 * generate right, which costs money; publishing takes the right to change
 * pages, as everywhere else.
 */
#[Route('/admin/cms/pages/{id}/content', name: 'admin.cms.pages.content.', requirements: ['id' => '\d+'])]
final readonly class CmsPageContentController
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private SecurityContext $securityContext,
        private TranslatorInterface $translator,
        private CmsPageAdminRepository $pages,
        private CmsPageWriter $writer,
        private EditLanguage $languages,
        private HeadingChecker $headings,
        private PreviewLink $previewLinks,
        private ContentScreen $screen,
        private GenerationRunner $runner,
        private SectionUpdate $sectionUpdate,
        private TranslationSources $translationSources,
        private GenerationTargets $targets,
        private PageTypeRepository $pageTypes,
        private SiteComponentPermission $componentPermission,
    ) {
    }

    #[Route('', name: 'edit', methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);

        return new Response($this->screen->render($request, $this->subject($page, $lang)));
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayGenerate();

        $form = $this->forms->create(BriefType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $page, $lang);
        }

        $data = $form->getData();
        $brief = new Brief((string) $data['text'], self::blankToNull($data['tone'] ?? null), self::blankToNull($data['audience'] ?? null));

        return $this->run($request, $page, $lang, GenerationRequest::fromBrief($this->target($page, $lang), $lang->getLocale(), $brief));
    }

    #[Route('/revise', name: 'revise', methods: ['POST'])]
    public function revise(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayGenerate();

        $form = $this->forms->create(ChangeType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $page, $lang);
        }

        $current = $this->documentOrFail($request, $page, $lang);

        if (!$current instanceof Document) {
            return $this->back($page, $lang);
        }

        return $this->run($request, $page, $lang, GenerationRequest::revision($this->target($page, $lang), $lang->getLocale(), $current, new Brief((string) $form->getData()['text'])));
    }

    #[Route('/section', name: 'section', methods: ['POST'])]
    public function section(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayGenerate();

        // The forms of the sections are named after their section, so that
        // two of them on one screen never share a field id.
        $form = $this->forms->createNamed(ContentScreen::postedSectionForm($request), ChangeType::class, null, ['section' => true]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $page, $lang);
        }

        $current = $this->documentOrFail($request, $page, $lang);

        if (!$current instanceof Document) {
            return $this->back($page, $lang);
        }

        $data = $form->getData();

        try {
            $generation = GenerationRequest::section($this->target($page, $lang), $lang->getLocale(), $current, (string) $data['section'], new Brief((string) $data['text']));
        } catch (\InvalidArgumentException) {
            $this->flash($request, 'danger', $this->translate('This section is no longer in the page: reload the screen.'));

            return $this->back($page, $lang);
        }

        return $this->run($request, $page, $lang, $generation);
    }

    /**
     * A section changed by hand: a word corrected costs no generation, and
     * takes the right to change pages, not the right to generate.
     */
    #[Route('/update', name: 'update', methods: ['POST'])]
    public function update(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPublish();

        $current = $this->documentOrFail($request, $page, $lang);

        if (!$current instanceof Document) {
            return $this->back($page, $lang);
        }

        $locale = $lang->getLocale();
        $problems = $this->sectionUpdate->apply(
            $request,
            $this->subject($page, $lang),
            $current,
            fn (Document $document) => $this->writer->saveContent($page, $locale, $document),
        );

        if ([] === $problems) {
            $this->flash($request, 'success', $this->translate('The section is saved in the draft.'));
        }

        foreach ($problems as $problem) {
            $this->flash($request, 'danger', $problem);
        }

        return $this->back($page, $lang);
    }

    /**
     * A kept draft put back, by whoever may change pages: the draft it
     * replaces is kept in its turn.
     */
    #[Route('/restore', name: 'restore', methods: ['POST'])]
    public function restore(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPublish();

        try {
            $this->writer->restoreDraft($page, $lang->getLocale(), $request->request->getInt('revision'));
            $this->flash($request, 'success', $this->translate('The previous draft is back. The one it replaces is kept in its place.'));
        } catch (\InvalidArgumentException|InvalidDocumentException) {
            $this->flash($request, 'danger', $this->translate('This draft is no longer available.'));
        }

        return $this->back($page, $lang);
    }

    /**
     * A section taken out by hand: like a change by hand, it takes the right
     * to change pages.
     */
    #[Route('/remove', name: 'remove', methods: ['POST'])]
    public function remove(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPublish();

        $current = $this->documentOrFail($request, $page, $lang);

        if (!$current instanceof Document) {
            return $this->back($page, $lang);
        }

        $locale = $lang->getLocale();
        $problems = $this->sectionUpdate->remove(
            $request,
            $this->subject($page, $lang),
            $current,
            fn (Document $document) => $this->writer->saveContent($page, $locale, $document),
        );

        if ([] === $problems) {
            $this->flash($request, 'success', $this->translate('The section is removed from the draft.'));
        }

        foreach ($problems as $problem) {
            $this->flash($request, 'danger', $problem);
        }

        return $this->back($page, $lang);
    }

    /**
     * A section duplicated by hand, the copy right after the original: like
     * a change by hand, it takes the right to change pages.
     */
    #[Route('/duplicate', name: 'duplicate', methods: ['POST'])]
    public function duplicate(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPublish();

        $current = $this->documentOrFail($request, $page, $lang);

        if (!$current instanceof Document) {
            return $this->back($page, $lang);
        }

        $locale = $lang->getLocale();
        $problems = $this->sectionUpdate->duplicate(
            $request,
            $this->subject($page, $lang),
            $current,
            fn (Document $document) => $this->writer->saveContent($page, $locale, $document),
        );

        if ([] === $problems) {
            $this->flash($request, 'success', $this->translate('The section is duplicated in the draft, right after the original.'));
        }

        foreach ($problems as $problem) {
            $this->flash($request, 'danger', $problem);
        }

        return $this->back($page, $lang);
    }

    /**
     * A section added by hand at the end of the draft: like a change by hand,
     * it takes the right to change pages. A page holding nothing yet starts
     * its draft with it.
     */
    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPublish();

        $locale = $lang->getLocale();
        $problems = $this->sectionUpdate->add(
            $request,
            $this->subject($page, $lang),
            $this->documentOf($page, $locale),
            fn (Document $document) => $this->writer->saveContent($page, $locale, $document),
        );

        if ([] === $problems) {
            $this->flash($request, 'success', $this->translate('The section is added at the end of the draft, with example text: edit it by hand.'));
        }

        foreach ($problems as $problem) {
            $this->flash($request, 'danger', $problem);
        }

        return $this->back($page, $lang);
    }

    /**
     * A section moved one place up or down by hand: like a change by hand, it
     * takes the right to change pages.
     */
    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPublish();

        $current = $this->documentOrFail($request, $page, $lang);

        if (!$current instanceof Document) {
            return $this->back($page, $lang);
        }

        $locale = $lang->getLocale();
        $problems = $this->sectionUpdate->move(
            $request,
            $this->subject($page, $lang),
            $current,
            fn (Document $document) => $this->writer->saveContent($page, $locale, $document),
        );

        if ([] === $problems) {
            $this->flash($request, 'success', $this->translate('The order of the sections is saved in the draft.'));
        }

        foreach ($problems as $problem) {
            $this->flash($request, 'danger', $problem);
        }

        return $this->back($page, $lang);
    }

    /**
     * This language written from the draft of another: the generator keeps
     * the sections and writes their texts for the new reader.
     */
    #[Route('/translate', name: 'translate', methods: ['POST'])]
    public function writeFromAnotherLanguage(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayGenerate();

        $sources = $this->translationSources->forPage((int) $page->getId(), $lang->getLocale());
        $form = $this->forms->create(TranslateType::class, null, ['sources' => array_column($sources, 'locale', 'label')]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $page, $lang);
        }

        $sourceLocale = (string) $form->getData()['source'];
        $source = $this->translationSources->pageDocument((int) $page->getId(), $sourceLocale);

        try {
            $generation = GenerationRequest::translation($this->target($page, $lang), $lang->getLocale(), $source ?? new Document([]), $sourceLocale);
        } catch (\InvalidArgumentException) {
            $this->flash($request, 'danger', $this->translate('That language holds nothing to write from any more: reload the screen.'));

            return $this->back($page, $lang);
        }

        return $this->run($request, $page, $lang, $generation);
    }

    #[Route('/publish', name: 'publish', methods: ['POST'])]
    public function publish(Request $request, int $id): Response
    {
        $page = $this->livePageOrFail($id);
        $lang = $this->languages->resolve($request);
        $this->denyUnlessMayPublish();

        try {
            $this->writer->publish($page, $lang->getLocale());
        } catch (HeadingStructureException $exception) {
            foreach ($exception->problems as $problem) {
                $this->flash($request, 'danger', $problem);
            }

            $this->flash($request, 'danger', $this->translate('The page was not published: fix the heading structure first.'));

            return $this->back($page, $lang);
        } catch (InvalidDocumentException $exception) {
            foreach ($exception->translated($this->translator) as $problem) {
                $this->flash($request, 'danger', $problem);
            }

            $this->flash($request, 'danger', $this->translate('The page was not published: its content is no longer valid. Open it in the editor to see what to fix.'));

            return $this->back($page, $lang);
        } catch (EmptyPageContentException) {
            $this->flash($request, 'danger', $this->translate('This page has no content yet: write it from a brief before publishing it.'));

            return $this->back($page, $lang);
        } catch (PlaceholderPageContentException) {
            $this->flash($request, 'danger', $this->translate('This page still holds the sample text it was created with: write your own before publishing it.'));

            return $this->back($page, $lang);
        } catch (ModelPagePublicationException) {
            $this->flash($request, 'danger', $this->translate('This page is the model of its type: it is never published. Start a new page from it instead.'));

            return $this->back($page, $lang);
        } catch (MissingPageFieldsException $exception) {
            $this->flash($request, 'danger', $this->translator->trans('Fill in the fields this type requires before publishing: %fields%.', ['%fields%' => implode(', ', $exception->labels)], TheliaCMS::DOMAIN_NAME));

            return $this->back($page, $lang);
        }

        // In `warn` mode the page went online and its author is told what to
        // fix; the writer refused it in `block` mode above.
        foreach ($this->headings->check($this->draftHtmlOf($page, $lang->getLocale()), underTitle: true) as $problem) {
            $this->flash($request, 'warning', $problem);
        }

        $this->flash($request, 'success', $this->translate('The page is online.'));

        return $this->back($page, $lang);
    }

    private function run(Request $request, CmsPage $page, Lang $lang, GenerationRequest $generation): Response
    {
        $locale = $lang->getLocale();

        return $this->runner->respond(
            $request,
            // The generator may make a component of the site for this page
            // when whoever asks may make one; the settings have the last word.
            $generation->withComponentMaking($this->componentPermission->mayMake()),
            function (Document $document, ?array $fields = null, ?AiProvenance $provenance = null) use ($page, $locale): void {
                // The draft the answer replaces is kept first: it can be put back.
                $this->writer->keepDraft($page, $locale);
                $this->writer->saveContent($page, $locale, $document, $provenance);

                if (null !== $fields && [] !== $fields) {
                    $this->writer->saveFields($page, $locale, $fields);
                }
            },
            $this->screenUrl($page, $lang),
        );
    }

    private function subject(CmsPage $page, Lang $lang): ContentSubject
    {
        $locale = $lang->getLocale();
        $page->setLocale($locale);
        $status = $this->pages->statusOf($page, $locale);
        $parameters = ['id' => $page->getId(), EditLanguage::PARAMETER => $lang->getId()];

        return new ContentSubject(
            target: $this->target($page, $lang),
            locale: $locale,
            languageId: (int) $lang->getId(),
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            backUrl: $this->urls->generate('admin.cms.pages.edit', $parameters),
            backLabel: $this->translate('Back to settings'),
            draftPreviewUrl: $this->previewLinks->contentUrlFor((int) $page->getId(), $locale),
            previewUrl: $page->getUrl($locale) ?: null,
            document: $this->documentOf($page, $locale),
            publishHint: $this->translate('Publishing puts the draft online at the address of the page.'),
            routes: [
                'screen' => $this->screenUrl($page, $lang),
                'generate' => $this->urls->generate('admin.cms.pages.content.generate', $parameters),
                'revise' => $this->urls->generate('admin.cms.pages.content.revise', $parameters),
                'section' => $this->urls->generate('admin.cms.pages.content.section', $parameters),
                'update' => $this->urls->generate('admin.cms.pages.content.update', $parameters),
                'remove' => $this->urls->generate('admin.cms.pages.content.remove', $parameters),
                'restore' => $this->urls->generate('admin.cms.pages.content.restore', $parameters),
                'move' => $this->urls->generate('admin.cms.pages.content.move', $parameters),
                'duplicate' => $this->urls->generate('admin.cms.pages.content.duplicate', $parameters),
                'add' => $this->urls->generate('admin.cms.pages.content.add', $parameters),
                'translate' => $this->urls->generate('admin.cms.pages.content.translate', $parameters),
                'publish' => $this->urls->generate('admin.cms.pages.content.publish', $parameters),
                'component_generate' => $this->urls->generate('admin.cms.pages.content.component.generate', $parameters),
                'component_keep' => $this->urls->generate('admin.cms.pages.content.component.keep', $parameters),
            ],
            testId: 'cms-page-content',
            translationSources: $this->translationSources->forPage((int) $page->getId(), $locale),
            publishable: 1 !== $page->getModel(),
            draftPagePreviewUrl: $this->previewLinks->urlFor((int) $page->getId(), $locale),
            template: $this->template($page, $locale),
            previousDrafts: $this->writer->previousDrafts($page, $locale),
        );
    }

    /**
     * The template of pages the page belongs to, named and linked to its
     * settings; nothing for a page of the default type.
     *
     * @return array{label: string, url: string}|null
     */
    private function template(CmsPage $page, string $locale): ?array
    {
        $code = (string) $page->getPageType();

        if ('' === $code || PageTypeCode::DEFAULT === $code || !$this->pageTypes->exists($code)) {
            return null;
        }

        return [
            'label' => $this->pageTypes->details($code)->title($locale),
            'url' => $this->urls->generate('admin.cms.settings.page_types.edit', ['code' => $code]),
        ];
    }

    private function target(CmsPage $page, Lang $lang): GenerationTarget
    {
        return $this->targets->forPage($page, $lang->getLocale());
    }

    private function documentOf(CmsPage $page, string $locale): ?Document
    {
        return Document::fromJson(CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($locale)->findOne()?->getDraftDocument());
    }

    private function documentOrFail(Request $request, CmsPage $page, Lang $lang): ?Document
    {
        $document = $this->documentOf($page, $lang->getLocale());

        if (!$document instanceof Document) {
            $this->flash($request, 'danger', $this->translate('This page holds nothing to change yet: write it from a brief first.'));
        }

        return $document;
    }

    private function draftHtmlOf(CmsPage $page, string $locale): ?string
    {
        return CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($locale)->findOne()?->getDraftHtml();
    }

    private function backWithErrors(Request $request, FormInterface $form, CmsPage $page, Lang $lang): RedirectResponse
    {
        foreach ($form->getErrors(true) as $error) {
            $this->flash($request, 'danger', (string) $error->getMessage());
        }

        if (!$form->isSubmitted()) {
            $this->flash($request, 'danger', $this->translate('Nothing was sent.'));
        }

        return $this->back($page, $lang);
    }

    private function back(CmsPage $page, Lang $lang): RedirectResponse
    {
        return new RedirectResponse($this->screenUrl($page, $lang));
    }

    private function screenUrl(CmsPage $page, Lang $lang): string
    {
        return $this->urls->generate('admin.cms.pages.content.edit', ['id' => $page->getId(), EditLanguage::PARAMETER => $lang->getId()]);
    }

    private function livePageOrFail(int $id): CmsPage
    {
        $page = $this->pages->findLive($id);

        if (!$page instanceof CmsPage) {
            throw new NotFoundHttpException();
        }

        return $page;
    }

    private function denyUnlessMayGenerate(): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [CmsResources::GENERATE], [], [AccessManager::UPDATE])) {
            throw new AccessDeniedHttpException($this->translate('You are not allowed to use the generator.'));
        }
    }

    private function denyUnlessMayPublish(): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [CmsResources::PAGE], [], [AccessManager::UPDATE])) {
            throw new AccessDeniedHttpException($this->translate('You are not allowed to change CMS pages.'));
        }
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }

    private function flash(Request $request, string $type, string $message): void
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add($type, $message);
        }
    }

    private function translate(string $message): string
    {
        return $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME);
    }
}
