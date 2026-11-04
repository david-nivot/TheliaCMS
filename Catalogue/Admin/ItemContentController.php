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

namespace TheliaCMS\Catalogue\Admin;

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
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\DealerStores;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\HeadingChecker;
use TheliaCMS\Generation\Admin\BriefType;
use TheliaCMS\Generation\Admin\ChangeType;
use TheliaCMS\Generation\Admin\ContentScreen;
use TheliaCMS\Generation\Admin\ContentSubject;
use TheliaCMS\Generation\Admin\GenerationRunner;
use TheliaCMS\Generation\Admin\SectionUpdate;
use TheliaCMS\Generation\Admin\TranslateType;
use TheliaCMS\Generation\Admin\TranslationSources;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTargets;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Preview\ItemPreviewLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;

/**
 * The document of a catalogue item, on the content screen of a page: written
 * from a brief, changed by asking or by hand, published. The rights are the
 * ones of the pages: the screen and the changes by hand take the right to
 * change pages, the generator the right to generate.
 */
#[Route('/admin/cms/catalogue/{type}/{id}/content', name: 'admin.cms.catalogue.content.', requirements: ['type' => CatalogueItemType::ROUTE_REQUIREMENT, 'id' => '\d+'])]
final readonly class ItemContentController
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private SecurityContext $securityContext,
        private TranslatorInterface $translator,
        private CatalogueItems $items,
        private ItemContentRepository $contents,
        private ItemContentWriter $writer,
        private EditLanguage $languages,
        private HeadingChecker $headings,
        private ItemPreviewLink $previewLinks,
        private ContentScreen $screen,
        private GenerationRunner $runner,
        private SectionUpdate $sectionUpdate,
        private TranslationSources $translationSources,
        private GenerationTargets $targets,
    ) {
    }

    #[Route('', name: 'edit', methods: ['GET'])]
    public function edit(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);

        return new Response($this->screen->render($request, $this->subject($item, $lang)));
    }

    #[Route('/generate', name: 'generate', methods: ['POST'])]
    public function generate(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayGenerate();

        $form = $this->forms->create(BriefType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $item, $lang);
        }

        $data = $form->getData();
        $brief = new Brief((string) $data['text'], self::blankToNull($data['tone'] ?? null), self::blankToNull($data['audience'] ?? null));

        return $this->run($request, $item, $lang, GenerationRequest::fromBrief($this->targets->forItem($item), $item->locale, $brief));
    }

    #[Route('/revise', name: 'revise', methods: ['POST'])]
    public function revise(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayGenerate();

        $form = $this->forms->create(ChangeType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $item, $lang);
        }

        $current = $this->documentOrFail($request, $item);

        if (!$current instanceof Document) {
            return $this->back($item, $lang);
        }

        return $this->run($request, $item, $lang, GenerationRequest::revision($this->targets->forItem($item), $item->locale, $current, new Brief((string) $form->getData()['text'])));
    }

    #[Route('/section', name: 'section', methods: ['POST'])]
    public function section(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayGenerate();

        $form = $this->forms->createNamed(ContentScreen::postedSectionForm($request), ChangeType::class, null, ['section' => true]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $item, $lang);
        }

        $current = $this->documentOrFail($request, $item);

        if (!$current instanceof Document) {
            return $this->back($item, $lang);
        }

        $data = $form->getData();

        try {
            $generation = GenerationRequest::section($this->targets->forItem($item), $item->locale, $current, (string) $data['section'], new Brief((string) $data['text']));
        } catch (\InvalidArgumentException) {
            $this->flash($request, 'danger', $this->translate('This section is no longer in the page: reload the screen.'));

            return $this->back($item, $lang);
        }

        return $this->run($request, $item, $lang, $generation);
    }

    #[Route('/update', name: 'update', methods: ['POST'])]
    public function update(Request $request, string $type, int $id): Response
    {
        return $this->byHand($request, $type, $id, 'apply', 'The section is saved in the draft.');
    }

    #[Route('/remove', name: 'remove', methods: ['POST'])]
    public function remove(Request $request, string $type, int $id): Response
    {
        return $this->byHand($request, $type, $id, 'remove', 'The section is removed from the draft.');
    }

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(Request $request, string $type, int $id): Response
    {
        return $this->byHand($request, $type, $id, 'move', 'The order of the sections is saved in the draft.');
    }

    #[Route('/duplicate', name: 'duplicate', methods: ['POST'])]
    public function duplicate(Request $request, string $type, int $id): Response
    {
        return $this->byHand($request, $type, $id, 'duplicate', 'The section is duplicated in the draft, right after the original.');
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayPublish();

        $problems = $this->sectionUpdate->add(
            $request,
            $this->subject($item, $lang),
            $this->contents->draftDocument($item->type, $item->id, $item->locale),
            fn (Document $document) => $this->writer->saveContent($item->type, $item->id, $item->locale, $document),
        );

        return $this->afterHand($request, $item, $lang, $problems, 'The section is added at the end of the draft, with example text: edit it by hand.');
    }

    /**
     * A kept draft put back, by whoever may change the content: the draft it
     * replaces is kept in its turn.
     */
    #[Route('/restore', name: 'restore', methods: ['POST'])]
    public function restore(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayPublish();

        try {
            $this->writer->restoreDraft($item->type, $item->id, $item->locale, $request->request->getInt('revision'));
            $this->flash($request, 'success', $this->translate('The previous draft is back. The one it replaces is kept in its place.'));
        } catch (\InvalidArgumentException|InvalidDocumentException) {
            $this->flash($request, 'danger', $this->translate('This draft is no longer available.'));
        }

        return $this->back($item, $lang);
    }

    #[Route('/translate', name: 'translate', methods: ['POST'])]
    public function writeFromAnotherLanguage(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayGenerate();

        $sources = $this->translationSources->forItem($item->type, $item->id, $item->locale);
        $form = $this->forms->create(TranslateType::class, null, ['sources' => array_column($sources, 'locale', 'label')]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $item, $lang);
        }

        $sourceLocale = (string) $form->getData()['source'];
        $source = $this->translationSources->itemDocument($item->type, $item->id, $sourceLocale);

        try {
            $generation = GenerationRequest::translation($this->targets->forItem($item), $item->locale, $source ?? new Document([]), $sourceLocale);
        } catch (\InvalidArgumentException) {
            $this->flash($request, 'danger', $this->translate('That language holds nothing to write from any more: reload the screen.'));

            return $this->back($item, $lang);
        }

        return $this->run($request, $item, $lang, $generation);
    }

    #[Route('/publish', name: 'publish', methods: ['POST'])]
    public function publish(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayPublish();

        try {
            $this->writer->publish($item->type, $item->id, $item->locale);
        } catch (ItemHeadingStructureException $exception) {
            foreach ($exception->problems as $problem) {
                $this->flash($request, 'danger', $problem);
            }

            $this->flash($request, 'danger', $this->translate('The content was not published: fix the heading structure first.'));

            return $this->back($item, $lang);
        } catch (InvalidDocumentException $exception) {
            foreach ($exception->translated($this->translator) as $problem) {
                $this->flash($request, 'danger', $problem);
            }

            $this->flash($request, 'danger', $this->translate('The content was not published: it is no longer valid. Open it in the editor to see what to fix.'));

            return $this->back($item, $lang);
        } catch (EmptyItemContentException) {
            $this->flash($request, 'danger', $this->translate('This content is empty: write it before publishing it.'));

            return $this->back($item, $lang);
        }

        foreach ($this->headings->check((string) $this->contents->find($item->type, $item->id, $item->locale)?->getDraftHtml(), underTitle: true) as $problem) {
            $this->flash($request, 'warning', $problem);
        }

        $this->flash($request, 'success', $this->translate('The content is online on the page of the item.'));

        return $this->back($item, $lang);
    }

    #[Route('/unpublish', name: 'unpublish', methods: ['POST'])]
    public function unpublish(Request $request, string $type, int $id): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayPublish();

        $this->writer->unpublish($item->type, $item->id, $item->locale);
        $this->flash($request, 'success', $this->translate('The content is offline: the draft is kept.'));

        return $this->back($item, $lang);
    }

    /**
     * A change by hand through the section form: like on a page, it takes
     * the right to change pages and costs no generation.
     */
    private function byHand(Request $request, string $type, int $id, string $change, string $done): Response
    {
        $lang = $this->languages->resolve($request);
        $item = $this->itemOrFail($type, $id, $lang);
        $this->denyUnlessMayPublish();

        $current = $this->documentOrFail($request, $item);

        if (!$current instanceof Document) {
            return $this->back($item, $lang);
        }

        $save = fn (Document $document) => $this->writer->saveContent($item->type, $item->id, $item->locale, $document);
        $subject = $this->subject($item, $lang);

        $problems = match ($change) {
            'apply' => $this->sectionUpdate->apply($request, $subject, $current, $save),
            'remove' => $this->sectionUpdate->remove($request, $subject, $current, $save),
            'move' => $this->sectionUpdate->move($request, $subject, $current, $save),
            'duplicate' => $this->sectionUpdate->duplicate($request, $subject, $current, $save),
        };

        return $this->afterHand($request, $item, $lang, $problems, $done);
    }

    /**
     * @param list<string> $problems
     */
    private function afterHand(Request $request, CatalogueItem $item, Lang $lang, array $problems, string $done): RedirectResponse
    {
        if ([] === $problems) {
            $this->flash($request, 'success', $this->translate($done));
        }

        foreach ($problems as $problem) {
            $this->flash($request, 'danger', $problem);
        }

        return $this->back($item, $lang);
    }

    private function run(Request $request, CatalogueItem $item, Lang $lang, GenerationRequest $generation): Response
    {
        return $this->runner->respond(
            $request,
            $generation,
            // An item has no fields of a page type: whatever the answer
            // writes besides the document is left out. The draft the answer
            // replaces is kept first: it can be put back.
            function (Document $document, ?array $fields = null, ?AiProvenance $provenance = null) use ($item): void {
                $this->writer->keepDraft($item->type, $item->id, $item->locale);
                $this->writer->saveContent($item->type, $item->id, $item->locale, $document, $provenance);
            },
            $this->screenUrl($item, $lang),
        );
    }

    private function subject(CatalogueItem $item, Lang $lang): ContentSubject
    {
        $status = ItemContentStatus::resolve($this->contents->find($item->type, $item->id, $item->locale));
        $parameters = ['type' => $item->type->value, 'id' => $item->id, EditLanguage::PARAMETER => $lang->getId()];
        $routes = ['screen' => $this->screenUrl($item, $lang)];

        foreach (['generate', 'revise', 'section', 'update', 'remove', 'restore', 'move', 'duplicate', 'add', 'translate', 'publish'] as $action) {
            $routes[$action] = $this->urls->generate('admin.cms.catalogue.content.'.$action, $parameters);
        }

        return new ContentSubject(
            target: $this->targets->forItem($item),
            locale: $item->locale,
            languageId: (int) $lang->getId(),
            statusLabel: $status->label(),
            statusBadgeClass: $status->badgeClass(),
            backUrl: $this->itemEditUrl($item, $lang),
            backLabel: $this->translate($item->type->backLabel()),
            draftPreviewUrl: $this->previewLinks->urlFor($item->type, $item->id, $item->locale),
            previewUrl: $item->url,
            document: $this->contents->draftDocument($item->type, $item->id, $item->locale),
            publishHint: $this->translate('Publishing puts the draft online on the page of the item, under what the theme shows of it.'),
            routes: $routes,
            testId: 'cms-item-content',
            translationSources: $this->translationSources->forItem($item->type, $item->id, $item->locale),
            draftPagePreviewUrl: $this->previewLinks->pageUrlFor($item),
            previousDrafts: $this->writer->previousDrafts($item->type, $item->id, $item->locale),
        );
    }

    /**
     * The edit screen of the item in the back office of the shop, in the
     * language being edited.
     */
    private function itemEditUrl(CatalogueItem $item, Lang $lang): string
    {
        $language = [EditLanguage::PARAMETER => $lang->getId()];

        return match ($item->type) {
            CatalogueItemType::Product => $this->urls->generate('admin.products.update', ['product_id' => $item->id] + $language),
            CatalogueItemType::Category => $this->urls->generate('admin.categories.update', ['category_id' => $item->id] + $language),
            CatalogueItemType::Content => $this->urls->generate('admin.content.update', ['content_id' => $item->id] + $language),
            CatalogueItemType::Folder => $this->urls->generate('admin.folders.update', ['folder_id' => $item->id] + $language),
            CatalogueItemType::Dealer => DealerStores::editUrl($this->urls, $item->id, $language),
        };
    }

    private function documentOrFail(Request $request, CatalogueItem $item): ?Document
    {
        $document = $this->contents->draftDocument($item->type, $item->id, $item->locale);

        if (!$document instanceof Document) {
            $this->flash($request, 'danger', $this->translate('This content holds nothing to change yet: write it from a brief first.'));
        }

        return $document;
    }

    private function backWithErrors(Request $request, FormInterface $form, CatalogueItem $item, Lang $lang): RedirectResponse
    {
        foreach ($form->getErrors(true) as $error) {
            $this->flash($request, 'danger', (string) $error->getMessage());
        }

        if (!$form->isSubmitted()) {
            $this->flash($request, 'danger', $this->translate('Nothing was sent.'));
        }

        return $this->back($item, $lang);
    }

    private function back(CatalogueItem $item, Lang $lang): RedirectResponse
    {
        return new RedirectResponse($this->screenUrl($item, $lang));
    }

    private function screenUrl(CatalogueItem $item, Lang $lang): string
    {
        return $this->urls->generate('admin.cms.catalogue.content.edit', ['type' => $item->type->value, 'id' => $item->id, EditLanguage::PARAMETER => $lang->getId()]);
    }

    private function itemOrFail(string $type, int $id, Lang $lang): CatalogueItem
    {
        $item = $this->items->find(CatalogueItemType::from($type), $id, (string) $lang->getLocale());

        if (!$item instanceof CatalogueItem) {
            throw new NotFoundHttpException();
        }

        return $item;
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
