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

namespace TheliaCMS\Page\Admin;

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Notice\NativeContentNotice;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Document\PageDocumentFormat;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\FixedAddressException;
use TheliaCMS\Page\PageTagRepository;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\ReservedSlugException;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\TheliaCMS;
use Twig\Environment;

/**
 * Page tree back office. The controller only resolves input, delegates the
 * write to CmsPageWriter and redirects; nothing is persisted here.
 */
#[Route('/admin/cms/pages', name: 'admin.cms.pages.')]
final readonly class CmsPageAdminController
{
    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/pages/list.html.twig';
    private const string EDIT_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/pages/edit.html.twig';
    private const string TRASH_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/pages/trash.html.twig';

    /** Same query parameter as the back-office language switcher component. */
    private const string EDIT_LANGUAGE_PARAMETER = EditLanguage::PARAMETER;

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
        private AdminControllerSupport $support,
        private TranslatorInterface $translator,
        private CmsPageAdminRepository $pages,
        private CmsPageWriter $writer,
        private EditLanguage $languages,
        private CmsUrlService $addresses,
        private PageListPresenter $listing,
        private NativeContentNotice $nativeContents,
        private CmsMediaLibrary $library,
        private CmsMediaWriter $media,
        private PageTypeRepository $pageTypes,
        private PageTagRepository $tags,
        private PageFields $fields,
        private PageDocumentRows $documentRows,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            ...$this->listing->present(PageFilters::fromRequest($request), $this->editLang($request)),
            'native_content_count' => $this->nativeContents->bannerCount(),
        ]));
    }

    /**
     * A blank page; `?page_type=recipe` starts it with that type, and the
     * fields of the type.
     */
    #[Route('/new', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        $this->denyUnless(AccessManager::CREATE);

        $page = new CmsPage();
        $type = (string) $request->query->get('page_type', '');

        if ($this->pageTypes->exists($type)) {
            $page->setPageType($type);
        }

        return $this->handleForm($request, $page);
    }

    /**
     * A new page of a type, named as the editor asked: a copy of the model of
     * the type when it has one, a blank page of the type otherwise.
     */
    #[Route('/from-type', name: 'from_type', methods: ['POST'], priority: 1)]
    public function fromType(Request $request): Response
    {
        $this->denyUnless(AccessManager::CREATE);
        $lang = $this->editLang($request);
        $code = (string) $request->request->get('page_type', '');

        if (!$this->pageTypes->exists($code)) {
            throw new NotFoundHttpException();
        }

        $title = trim((string) $request->request->get('title', ''));

        if ('' === $title) {
            $this->support->flash($request, 'danger', $this->support->translate('Give the new page a title.'));

            return $this->backToList($request);
        }

        $model = $this->pageTypes->modelOf($code);

        if (null !== $model) {
            $page = $this->writer->startFromModel($model, $lang->getLocale(), $title);
        } else {
            $page = (new CmsPage())->setParent(0)->setPosition(0)->setVisible(1)->setPageType($code);
            $this->writer->saveDraft($page, $lang->getLocale(), new PageDraft(title: mb_substr($title, 0, 255)));
        }

        return $this->backToEdit((int) $page->getId(), $lang);
    }

    /**
     * The address the edit screen offers for a title, a slug and a parent not
     * saved yet, in the language being edited: from the slug when one is typed,
     * from the title otherwise, and the segment the title alone would give, so
     * the screen can say when the address no longer follows the title. Nothing
     * is written: the address changes when the form is saved.
     */
    #[Route('/address-proposal', name: 'address_proposal', methods: ['POST'], priority: 1)]
    public function addressProposal(Request $request): JsonResponse
    {
        $pageId = $request->request->getInt('page');
        $this->denyUnless(0 === $pageId ? AccessManager::CREATE : AccessManager::UPDATE);

        if (0 !== $pageId) {
            $this->livePageOrFail($pageId);
        }

        $title = trim((string) $request->request->get('title', ''));
        $slug = trim((string) $request->request->get('slug', ''));

        if ('' === $title && '' === $slug) {
            return new JsonResponse(['error' => $this->support->translate('Give the page a title first.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $locale = $this->editLang($request)->getLocale();
        $parent = $request->request->getInt('parent');

        try {
            $proposal = $this->addresses->propose($pageId, $parent, $locale, $title, '' === $slug ? null : $slug);
            $fromTitle = '' === $title ? null : $this->addresses->propose($pageId, $parent, $locale, $title);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'segment' => $proposal->segment,
            'address' => $proposal->address,
            'suffixed' => $proposal->suffixed,
            'title_segment' => $fromTitle?->segment,
        ]);
    }

    /**
     * Whether a fixed address typed on the edit screen can be the page's, as
     * the save would decide it, without saving: a fixed address is kept as
     * typed, so it is checked, never regenerated.
     */
    #[Route('/fixed-address-check', name: 'fixed_address_check', methods: ['POST'], priority: 1)]
    public function fixedAddressCheck(Request $request): JsonResponse
    {
        $pageId = $request->request->getInt('page');
        $this->denyUnless(0 === $pageId ? AccessManager::CREATE : AccessManager::UPDATE);

        if (0 !== $pageId) {
            $this->livePageOrFail($pageId);
        }

        try {
            $address = $this->addresses->checkFixedAddress((string) $request->request->get('address', ''), $pageId, $this->editLang($request)->getLocale());
        } catch (FixedAddressException $exception) {
            return new JsonResponse(['error' => $exception->text->trans($this->translator)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['address' => $address]);
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        return $this->handleForm($request, $this->livePageOrFail($id));
    }

    #[Route('/{id}/publish', name: 'publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $lang = $this->editLang($request);

        try {
            $warnings = $this->writer->publish($this->livePageOrFail($id), $lang->getLocale());

            foreach ($warnings as $warning) {
                $this->support->flash($request, 'warning', $warning);
            }
        } catch (HeadingStructureException $exception) {
            foreach ($exception->problems as $problem) {
                $this->support->flash($request, 'danger', $problem);
            }

            $this->support->flash($request, 'danger', $this->support->translate('The page was not published: fix the heading structure first.'));
        } catch (InvalidDocumentException $exception) {
            foreach ($exception->translated($this->translator) as $problem) {
                $this->support->flash($request, 'danger', $problem);
            }

            $this->support->flash($request, 'danger', $this->support->translate('The page was not published: its content is no longer valid. Open it in the editor to see what to fix.'));
        } catch (EmptyPageContentException) {
            $this->support->flash($request, 'danger', $this->support->translate('This page has no content yet: add at least one block in the editor before publishing it.'));
        } catch (PlaceholderPageContentException) {
            $this->support->flash($request, 'danger', $this->support->translate('This page still holds the sample text it was created with: write your own before publishing it.'));
        } catch (ModelPagePublicationException) {
            $this->support->flash($request, 'danger', $this->support->translate('This page is the model of its type: it is never published. Start a new page from it instead.'));
        } catch (MissingPageFieldsException $exception) {
            $this->support->flash($request, 'danger', $this->support->translate('Fill in the fields this type requires before publishing: %fields%.', ['%fields%' => implode(', ', $exception->labels)]));
        }

        return $this->backToEdit($id, $lang);
    }

    #[Route('/{id}/unpublish', name: 'unpublish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unpublish(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $lang = $this->editLang($request);

        $this->writer->unpublish($this->livePageOrFail($id), $lang->getLocale());

        return $this->backToEdit($id, $lang);
    }

    #[Route('/{id}/remove-ai-mark', name: 'remove_ai_mark', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function removeAiMark(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $lang = $this->editLang($request);

        if ($this->writer->removeProvenance($this->livePageOrFail($id), $lang->getLocale())) {
            $this->support->flash($request, 'success', $this->support->translate('The AI mark has been removed from this page in this language.'));
        }

        return $this->backToEdit($id, $lang);
    }

    #[Route('/{id}/visibility', name: 'visibility', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleVisibility(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);

        try {
            $this->writer->toggleVisibility($this->livePageOrFail($id));
        } catch (ModelPageSettingException) {
            $this->support->flash($request, 'danger', $this->support->translate('This page is the model of its template: it is never online.'));
        }

        return $this->backToList($request);
    }

    #[Route('/{id}/move/{direction}', name: 'move', requirements: ['id' => '\d+', 'direction' => 'up|down'], methods: ['POST'])]
    public function move(Request $request, int $id, string $direction): Response
    {
        $this->denyUnless(AccessManager::UPDATE);

        $this->writer->move($this->livePageOrFail($id), 'up' === $direction ? -1 : 1);

        return $this->backToList($request);
    }

    #[Route('/{id}/duplicate', name: 'duplicate', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function duplicate(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::CREATE);
        $lang = $this->editLang($request);

        $copy = $this->writer->duplicate(
            $this->livePageOrFail($id),
            $lang->getLocale(),
            $this->support->translate('(copy)'),
        );

        return $this->backToEdit((int) $copy->getId(), $lang);
    }

    #[Route('/{id}/set-as-home', name: 'set_as_home', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function setAsHome(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);

        $this->writer->setAsHome($this->livePageOrFail($id));

        return $this->backToList($request);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::DELETE);

        try {
            $this->writer->moveToTrash($this->livePageOrFail($id));
        } catch (ModelPageSettingException) {
            $this->support->flash($request, 'danger', $this->support->translate('This page is the model of its template: it is deleted with its template, under CMS > Page templates, never moved to the bin.'));
        }

        return $this->backToList($request);
    }

    #[Route('/trash', name: 'trash', methods: ['GET'], priority: 1)]
    public function trash(Request $request): Response
    {
        $lang = $this->editLang($request);
        $filters = PageFilters::fromRequest($request);

        return new Response($this->twig->render(self::TRASH_TEMPLATE, [
            'pages' => $this->pages->trash($lang->getLocale(), $filters),
            'search' => $filters->search,
            // The whole bin, not the rows a search left: a badge that counts the
            // matches says the bin holds one page when it holds a hundred.
            'trash_count' => $this->pages->countInTrash(),
            'live_count' => $this->pages->countLive(),
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
        ]));
    }

    #[Route('/{id}/restore', name: 'restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $lang = $this->editLang($request);

        $page = $this->pages->findDeleted($id);

        if (!$page instanceof CmsPage) {
            throw new NotFoundHttpException();
        }

        try {
            $this->writer->restore($page, $lang->getLocale());
        } catch (\DomainException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return $this->backToList($request);
    }

    private function handleForm(Request $request, CmsPage $page): Response
    {
        $lang = $this->editLang($request);
        $locale = $lang->getLocale();
        $form = $this->buildForm($page, $lang);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyUnless($page->isNew() ? AccessManager::CREATE : AccessManager::UPDATE);

            try {
                $this->applyTo($page, $locale, $form);

                return $this->backToEdit((int) $page->getId(), $lang);
            } catch (InvalidFieldsException $exception) {
                foreach ($exception->translated($this->translator) as $problem) {
                    $form->addError(new FormError($problem));
                }
            } catch (FixedAddressException $exception) {
                $form->get('fixedUrl')->addError(new FormError($exception->text->trans($this->translator)));
            } catch (ReservedSlugException $exception) {
                $form->get('slug')->addError(new FormError($exception->text->trans($this->translator)));
            } catch (\InvalidArgumentException $exception) {
                $form->get('slug')->addError(new FormError($exception->getMessage()));
            }
        }

        $type = $this->pageTypes->details((string) $page->getPageType());

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            'is_model' => 1 === $page->getModel(),
            'page_type_title' => $type->title($locale),
            'has_fields' => !$type->fields()->isEmpty(),
            'form' => $form->createView(),
            'page' => $page,
            'status' => $page->isNew() ? PageStatus::Draft : $this->pages->statusOf($page, $locale),
            'edit_locale' => $locale,
            'edit_language_id' => $lang->getId(),
            'ai_marked' => !$page->isNew() && $this->pages->isAiMarked($page, $locale),
            'is_home' => !$page->isNew() && (int) $page->getId() === (int) TheliaCMS::getConfigValue('home_page_id', 0),
            'preview_url' => $page->isNew() ? null : $page->getUrl($locale),
            'documents' => $page->isNew() ? [] : $this->documentRows->forPage((int) $page->getId(), $locale),
            'document_extensions' => PageDocumentFormat::extensions(),
            'document_max_size' => (int) UploadedFile::getMaxFilesize(),
            'document_max_size_label' => PageDocumentSize::human((int) UploadedFile::getMaxFilesize(), $locale),
            'document_max_files' => max(1, (int) \ini_get('max_file_uploads')),
            'may_change_documents' => $this->support->isGranted(CmsResources::PAGE, AccessManager::UPDATE),
            'may_delete_documents' => $this->support->isGranted(CmsResources::PAGE, AccessManager::DELETE),
            // The browser keeps the script until its version changes.
            'assets_version' => substr(hash('xxh3', (string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/page-address.js').(string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/media-picker.js').(string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/page-documents.js')), 0, 12),
        ]));
    }

    private function buildForm(CmsPage $page, Lang $lang): FormInterface
    {
        $locale = $lang->getLocale();

        if (!$page->isNew()) {
            $page->setLocale($locale);
        }

        $definitions = $this->fields->definitionsOf($page);
        $typeLabels = [];

        foreach ($this->pageTypes->allDetails() as $code => $details) {
            $title = $details->title($locale);
            $typeLabels[$code] = $title === $code ? $code : \sprintf('%s (%s)', $title, $code);
        }

        return $this->forms->create(CmsPageType::class, [
            'title' => $page->isNew() ? '' : (string) $page->getTitle(),
            // The segment of the address this page owns, not the whole path:
            // the ancestors are prefixed again on save, so showing the path
            // would fold it into the segment on the next save and move the page
            // to `parent/parent-child`.
            'slug' => $page->isNew() ? null : $this->addresses->slugOf($page, $locale),
            'fixedUrl' => $page->isNew() ? null : $this->addresses->fixedAddressOf($page, $locale),
            'chapo' => $page->isNew() ? null : $page->getChapo(),
            'description' => $page->isNew() ? null : $page->getDescription(),
            'parent' => (int) $page->getParent(),
            'pageType' => $page->getPageType(),
            'tag' => $page->getTagId(),
            'image' => $page->getImageId(),
            // A model is never online, whatever an older version of the module
            // or an import left in the row.
            'visible' => $page->isNew() ? 1 : (1 === $page->getModel() ? 0 : $page->getVisible()),
            'publishAt' => $page->getPublishAt(),
            'unpublishAt' => $page->getUnpublishAt(),
            'metaTitle' => $page->isNew() ? null : $page->getMetaTitle(),
            'metaDescription' => $page->isNew() ? null : $page->getMetaDescription(),
            'ogTitle' => $page->isNew() ? null : $page->getOgTitle(),
            'ogDescription' => $page->isNew() ? null : $page->getOgDescription(),
            'ogImage' => $page->isNew() ? null : $page->getOgImageId(),
            'twitterCard' => $page->isNew() ? null : $page->getTwitterCard(),
            'canonical' => $page->isNew() ? null : $page->getCanonical(),
            'noindex' => $page->isNew() ? 0 : $page->getNoindex(),
            'nofollow' => $page->isNew() ? 0 : $page->getNofollow(),
            'fields' => $this->fields->valuesOf($page, $locale, $definitions),
        ], [
            'parent_choices' => $this->pages->parentChoices($locale, $page->isNew() ? null : (int) $page->getId()),
            'image_picker_url' => $this->urls->generate('admin.cms.media.picker'),
            'image_upload_url' => $this->urls->generate('admin.cms.media.add'),
            'page_type_choices' => array_map(strval(...), array_keys($typeLabels)),
            'page_type_labels' => $typeLabels,
            'is_model' => 1 === $page->getModel(),
            'field_definitions' => $definitions,
            'field_locale' => $locale,
            'tag_choices' => $this->tags->namesById(),
            'allow_image_upload' => $this->support->isGranted(CmsResources::MEDIA, AccessManager::CREATE),
            'address_proposal_url' => $this->urls->generate('admin.cms.pages.address_proposal', [self::EDIT_LANGUAGE_PARAMETER => $lang->getId()]),
            'fixed_address_check_url' => $this->urls->generate('admin.cms.pages.fixed_address_check', [self::EDIT_LANGUAGE_PARAMETER => $lang->getId()]),
            'page_id' => $page->isNew() ? 0 : (int) $page->getId(),
        ]);
    }

    private function applyTo(CmsPage $page, string $locale, FormInterface $form): void
    {
        $data = $form->getData();

        $page->setParent((int) $data['parent'])
            // A disabled field posts nothing: the model keeps its type.
            ->setPageType($data['pageType'] ?? $page->getPageType())
            ->setTagId(null === $data['tag'] ? null : (int) $data['tag'])
            ->setImageId($this->chosenImage($data, $locale))
            ->setVisible(1 === $page->getModel() ? 0 : (int) $data['visible'])
            ->setPublishAt($data['publishAt'])
            ->setUnpublishAt($data['unpublishAt']);

        // The i18n columns are written on the localized object before the
        // writer saves it, so a single save() covers page + translation.
        $page->setLocale($locale)
            ->setFixedUrl($this->addresses->normalizeFixedAddress($data['fixedUrl'] ?? null))
            ->setChapo($data['chapo'])
            ->setDescription($data['description'])
            ->setMetaTitle($data['metaTitle'])
            ->setMetaDescription($data['metaDescription'])
            ->setOgTitle($data['ogTitle'])
            ->setOgDescription($data['ogDescription'])
            ->setOgImageId($this->ownedImage($data['ogImage'] ?? null))
            ->setTwitterCard($data['twitterCard'])
            ->setCanonical($data['canonical'])
            ->setNoindex((int) $data['noindex'])
            ->setNofollow((int) $data['nofollow']);

        $this->writer->saveDraft($page, $locale, new PageDraft(
            title: $data['title'],
            slug: $data['slug'],
            fields: \is_array($data['fields'] ?? null) ? $data['fields'] : [],
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function chosenImage(array $data, string $locale): ?int
    {
        if (($data['imageUpload'] ?? null) instanceof UploadedFile) {
            $stored = $this->media->add([$data['imageUpload']], $locale)->stored;

            if (isset($stored[0])) {
                return (int) $stored[0]->getId();
            }
        }

        return $this->ownedImage($data['image'] ?? null);
    }

    /**
     * An id the screen sent is an image of the library of the CMS, or none.
     */
    private function ownedImage(mixed $id): ?int
    {
        $id = (int) $id;

        return $id > 0 && null !== $this->library->ownedImage($id) ? $id : null;
    }

    private function livePageOrFail(int $id): CmsPage
    {
        $page = $this->pages->findLive($id);

        if (!$page instanceof CmsPage) {
            throw new NotFoundHttpException();
        }

        return $page;
    }

    private function denyUnless(string $access): void
    {
        $this->support->denyUnless(CmsResources::PAGE, $access, 'You are not allowed to change CMS pages.');
    }

    private function editLang(Request $request): Lang
    {
        return $this->languages->resolve($request);
    }

    /**
     * Back to the listing as the editor had it: same search, same folded
     * branches, same page of results. Moving a page that landed them back on a
     * folded tree would make reordering a subtree a fight with the screen.
     */
    private function backToList(Request $request): RedirectResponse
    {
        return $this->support->redirect('admin.cms.pages.list', [
            self::EDIT_LANGUAGE_PARAMETER => $this->editLang($request)->getId(),
            ...PageFilters::fromRequest($request)->toQueryParams(),
        ]);
    }

    private function backToEdit(int $id, Lang $lang): RedirectResponse
    {
        return $this->support->redirectInLanguage('admin.cms.pages.edit', $lang->getId(), ['id' => $id]);
    }
}
