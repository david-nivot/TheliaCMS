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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Domain\Ai\AiProvenance;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Security\CmsResources;

/**
 * The content of a page or of a catalogue item, on one screen: written by the
 * generator from a brief, changed by asking or by hand, read in the theme,
 * published. Each action answers on two addresses, the page's
 * (`/admin/cms/pages/{id}/content`) and the item's
 * (`/admin/cms/catalogue/{type}/{id}/content`); `$type` is null on the first.
 *
 * Opening the screen takes nothing beyond the guard of the module (the page
 * right to view). Asking the generator takes the right to generate, which
 * costs money. Every change by hand, the restore of a kept draft, the
 * publication and the withdrawal of an item take the right to change pages,
 * as everywhere else; no right of the core on the item itself is checked.
 */
final readonly class ContentController
{
    private const string PAGE = '/admin/cms/pages/{id}/content';
    private const string ITEM = '/admin/cms/catalogue/{type}/{id}/content';
    private const array PAGE_REQUIREMENTS = ['id' => '\d+'];
    private const array ITEM_REQUIREMENTS = ['type' => CatalogueItemType::ROUTE_REQUIREMENT, 'id' => '\d+'];

    public function __construct(
        private FormFactoryInterface $forms,
        private AdminControllerSupport $support,
        private ContentTargetResolver $resolver,
        private ContentWriter $writer,
        private ContentScreen $screen,
        private GenerationRunner $runner,
        private SectionUpdate $sectionUpdate,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(self::PAGE, name: 'admin.cms.pages.content.edit', methods: ['GET'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM, name: 'admin.cms.catalogue.content.edit', methods: ['GET'], requirements: self::ITEM_REQUIREMENTS)]
    public function edit(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);

        return new Response($this->screen->render($request, $target->subject()));
    }

    #[Route(self::PAGE.'/generate', name: 'admin.cms.pages.content.generate', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/generate', name: 'admin.cms.catalogue.content.generate', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function generate(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayGenerate();

        $form = $this->forms->create(BriefType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $target);
        }

        $data = $form->getData();
        $brief = new Brief((string) $data['text'], self::blankToNull($data['tone'] ?? null), self::blankToNull($data['audience'] ?? null));

        return $this->run($request, $target, GenerationRequest::fromBrief($target->generationTarget(), $target->locale(), $brief));
    }

    #[Route(self::PAGE.'/revise', name: 'admin.cms.pages.content.revise', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/revise', name: 'admin.cms.catalogue.content.revise', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function revise(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayGenerate();

        $form = $this->forms->create(ChangeType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $target);
        }

        $current = $this->documentOrFail($request, $target);

        if (!$current instanceof Document) {
            return $this->back($target);
        }

        return $this->run($request, $target, GenerationRequest::revision($target->generationTarget(), $target->locale(), $current, new Brief((string) $form->getData()['text'])));
    }

    #[Route(self::PAGE.'/section', name: 'admin.cms.pages.content.section', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/section', name: 'admin.cms.catalogue.content.section', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function section(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayGenerate();

        // The forms of the sections are named after their section, so that
        // two of them on one screen never share a field id.
        $form = $this->forms->createNamed(ContentScreen::postedSectionForm($request), ChangeType::class, null, ['section' => true]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $target);
        }

        $current = $this->documentOrFail($request, $target);

        if (!$current instanceof Document) {
            return $this->back($target);
        }

        $data = $form->getData();

        try {
            $generation = GenerationRequest::section($target->generationTarget(), $target->locale(), $current, (string) $data['section'], new Brief((string) $data['text']));
        } catch (\InvalidArgumentException) {
            $this->support->flash($request, 'danger', $this->support->translate('This section is no longer in the page: reload the screen.'));

            return $this->back($target);
        }

        return $this->run($request, $target, $generation);
    }

    /**
     * A section changed by hand: a word corrected costs no generation, and
     * takes the right to change pages, not the right to generate.
     */
    #[Route(self::PAGE.'/update', name: 'admin.cms.pages.content.update', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/update', name: 'admin.cms.catalogue.content.update', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function update(Request $request, int $id, ?string $type = null): Response
    {
        return $this->byHand($request, $id, $type, 'apply', 'The section is saved in the draft.');
    }

    #[Route(self::PAGE.'/remove', name: 'admin.cms.pages.content.remove', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/remove', name: 'admin.cms.catalogue.content.remove', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function remove(Request $request, int $id, ?string $type = null): Response
    {
        return $this->byHand($request, $id, $type, 'remove', 'The section is removed from the draft.');
    }

    #[Route(self::PAGE.'/move', name: 'admin.cms.pages.content.move', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/move', name: 'admin.cms.catalogue.content.move', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function move(Request $request, int $id, ?string $type = null): Response
    {
        return $this->byHand($request, $id, $type, 'move', 'The order of the sections is saved in the draft.');
    }

    #[Route(self::PAGE.'/duplicate', name: 'admin.cms.pages.content.duplicate', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/duplicate', name: 'admin.cms.catalogue.content.duplicate', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function duplicate(Request $request, int $id, ?string $type = null): Response
    {
        return $this->byHand($request, $id, $type, 'duplicate', 'The section is duplicated in the draft, right after the original.');
    }

    /**
     * A section added at the end of the draft: a content holding nothing yet
     * starts its draft with it.
     */
    #[Route(self::PAGE.'/add', name: 'admin.cms.pages.content.add', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/add', name: 'admin.cms.catalogue.content.add', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function add(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayChange();

        $problems = $this->sectionUpdate->add($request, $target->subject(), $target->draftDocument(), $this->saver($target));

        return $this->afterHand($request, $target, $problems, 'The section is added at the end of the draft, with example text: edit it by hand.');
    }

    /**
     * A kept draft put back, by whoever may change the content: the draft it
     * replaces is kept in its turn.
     */
    #[Route(self::PAGE.'/restore', name: 'admin.cms.pages.content.restore', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/restore', name: 'admin.cms.catalogue.content.restore', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function restore(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayChange();

        try {
            $this->writer->restoreDraft($target->contentTarget(), $request->request->getInt('revision'));
            $this->support->flash($request, 'success', $this->support->translate('The previous draft is back. The one it replaces is kept in its place.'));
        } catch (\InvalidArgumentException|InvalidDocumentException) {
            $this->support->flash($request, 'danger', $this->support->translate('This draft is no longer available.'));
        }

        return $this->back($target);
    }

    /**
     * This language written from the draft of another: the generator keeps
     * the sections and writes their texts for the new reader.
     */
    #[Route(self::PAGE.'/translate', name: 'admin.cms.pages.content.translate', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/translate', name: 'admin.cms.catalogue.content.translate', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function writeFromAnotherLanguage(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayGenerate();

        $sources = $target->translationSources();
        $form = $this->forms->create(TranslateType::class, null, ['sources' => array_column($sources, 'locale', 'label')]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->backWithErrors($request, $form, $target);
        }

        $sourceLocale = (string) $form->getData()['source'];
        $source = $target->translationSource($sourceLocale);

        try {
            $generation = GenerationRequest::translation($target->generationTarget(), $target->locale(), $source ?? new Document([]), $sourceLocale);
        } catch (\InvalidArgumentException) {
            $this->support->flash($request, 'danger', $this->support->translate('That language holds nothing to write from any more: reload the screen.'));

            return $this->back($target);
        }

        return $this->run($request, $target, $generation);
    }

    #[Route(self::PAGE.'/publish', name: 'admin.cms.pages.content.publish', methods: ['POST'], requirements: self::PAGE_REQUIREMENTS)]
    #[Route(self::ITEM.'/publish', name: 'admin.cms.catalogue.content.publish', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function publish(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayChange();

        try {
            $warnings = $this->writer->publish($target->contentTarget());
        } catch (InvalidDocumentException $exception) {
            foreach ($exception->translated($this->translator) as $problem) {
                $this->support->flash($request, 'danger', $problem);
            }

            $this->support->flash($request, 'danger', $target->invalidWhenPublishing());

            return $this->back($target);
        } catch (\Throwable $refusal) {
            // Only the refusals of the family are told; any other failure goes on.
            $messages = $target->explainRefusal($refusal) ?? throw $refusal;

            foreach ($messages as $message) {
                $this->support->flash($request, 'danger', $message);
            }

            return $this->back($target);
        }

        // In `warn` mode the content went online and its author is told what
        // to fix in it; the writer refused it in `block` mode above.
        foreach ($warnings as $warning) {
            $this->support->flash($request, 'warning', $warning);
        }

        $this->support->flash($request, 'success', $target->online());

        return $this->back($target);
    }

    /**
     * Taken offline from the tab of the edit screen of the item in the core:
     * a page is taken offline from its settings, so it has no address here.
     */
    #[Route(self::ITEM.'/unpublish', name: 'admin.cms.catalogue.content.unpublish', methods: ['POST'], requirements: self::ITEM_REQUIREMENTS)]
    public function unpublish(Request $request, int $id, ?string $type = null): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayChange();

        $this->writer->unpublish($target->contentTarget());
        $this->support->flash($request, 'success', $this->support->translate('The content is offline: the draft is kept.'));

        return $this->back($target);
    }

    /**
     * A change by hand through the section form: it takes the right to
     * change pages and costs no generation.
     */
    private function byHand(Request $request, int $id, ?string $type, string $change, string $done): Response
    {
        $target = $this->resolver->resolve($request, $id, $type);
        $this->denyUnlessMayChange();

        $current = $this->documentOrFail($request, $target);

        if (!$current instanceof Document) {
            return $this->back($target);
        }

        $save = $this->saver($target);
        $subject = $target->subject();

        $problems = match ($change) {
            'apply' => $this->sectionUpdate->apply($request, $subject, $current, $save),
            'remove' => $this->sectionUpdate->remove($request, $subject, $current, $save),
            'move' => $this->sectionUpdate->move($request, $subject, $current, $save),
            'duplicate' => $this->sectionUpdate->duplicate($request, $subject, $current, $save),
            default => throw new \LogicException(\sprintf('"%s" is not a change by hand.', $change)),
        };

        return $this->afterHand($request, $target, $problems, $done);
    }

    /**
     * @param list<string> $problems
     */
    private function afterHand(Request $request, ContentScreenTarget $target, array $problems, string $done): RedirectResponse
    {
        if ([] === $problems) {
            $this->support->flash($request, 'success', $this->support->translate($done));
        }

        foreach ($problems as $problem) {
            $this->support->flash($request, 'danger', $problem);
        }

        return $this->back($target);
    }

    private function run(Request $request, ContentScreenTarget $target, GenerationRequest $generation): Response
    {
        return $this->runner->respond(
            $request,
            $target->prepare($generation),
            function (Document $document, ?array $fields = null, ?AiProvenance $provenance = null, array $seo = []) use ($target): void {
                // The draft the answer replaces is kept first: it can be put back.
                $this->writer->keepDraft($target->contentTarget());
                $this->writer->saveContent($target->contentTarget(), $document, $provenance);
                $target->saveAlongside($fields, $seo);
            },
            $target->screenUrl(),
        );
    }

    private function saver(ContentScreenTarget $target): \Closure
    {
        return fn (Document $document) => $this->writer->saveContent($target->contentTarget(), $document);
    }

    private function documentOrFail(Request $request, ContentScreenTarget $target): ?Document
    {
        $document = $target->draftDocument();

        if (!$document instanceof Document) {
            $this->support->flash($request, 'danger', $target->nothingToChange());
        }

        return $document;
    }

    private function backWithErrors(Request $request, FormInterface $form, ContentScreenTarget $target): RedirectResponse
    {
        $this->support->flashFormErrors($request, $form);

        return $this->back($target);
    }

    private function back(ContentScreenTarget $target): RedirectResponse
    {
        return new RedirectResponse($target->screenUrl());
    }

    private function denyUnlessMayGenerate(): void
    {
        $this->support->denyUnless(CmsResources::GENERATE, AccessManager::UPDATE, 'You are not allowed to use the generator.');
    }

    private function denyUnlessMayChange(): void
    {
        $this->support->denyUnless(CmsResources::PAGE, AccessManager::UPDATE, 'You are not allowed to change CMS pages.');
    }

    private static function blankToNull(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
