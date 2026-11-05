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

namespace TheliaCMS\Media\Admin;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\MediaFilter;
use TheliaCMS\Media\MediaOrder;
use TheliaCMS\Media\Svg\SvgUpload;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaCMS\Security\CmsResources;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Model\LibraryImage;
use Twig\Environment;

/**
 * The media library, as an editor works with it: a grid to look through, and
 * one screen per image to describe it.
 */
#[Route('/admin/cms/media', name: 'admin.cms.media.')]
final readonly class CmsMediaAdminController
{
    private const string LIST_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/media/list.html.twig';
    private const string EDIT_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/media/edit.html.twig';
    private const string DESCRIBE_TEMPLATE = '@TheliaCMSModule/backOffice/default-twig/media/describe.html.twig';

    private const string EDIT_LANGUAGE_PARAMETER = EditLanguage::PARAMETER;

    public function __construct(
        private Environment $twig,
        private FormFactoryInterface $forms,
        private AdminControllerSupport $support,
        private CmsMediaLibrary $library,
        private MediaCatalog $catalog,
        private MediaUsageFinder $usages,
        private CmsMediaWriter $writer,
        private EditLanguage $languages,
        private CatalogueItems $items,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $lang = $this->editLang($request);
        $query = MediaQuery::fromRequest($request);
        $tally = $this->catalog->tally($lang->getLocale());

        return new Response($this->twig->render(self::LIST_TEMPLATE, [
            'grid' => $this->catalog->browse($query, $lang->getLocale()),
            'tally' => $tally,
            'query' => $query,
            'filters' => MediaFilter::cases(),
            'orders' => MediaOrder::cases(),
            'tags' => $this->library->tagCounts(),
            'has_library' => $tally->total > 0,
            'accepted_mime_types' => implode(',', CmsMediaType::LIBRARY_ACCEPTED_TYPES),
            // The script puts the token of a prepared SVG where this one stands.
            'token_placeholder' => SvgPreparationController::TOKEN_PLACEHOLDER,
            'max_upload_size' => min(CmsMediaType::MAX_IMAGE_BYTES, (int) UploadedFile::getMaxFilesize()),
            // The screen refuses a file above that size before sending it: it
            // names the limit that stops it, the ceiling of the module or PHP's.
            'max_upload_refusal' => (CmsMediaType::MAX_IMAGE_BYTES <= (int) UploadedFile::getMaxFilesize() ? MediaUploadRefusal::TooHeavy : MediaUploadRefusal::TooLarge)->message(),
            'assets_version' => substr(hash('xxh3', (string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/media-upload.js').(string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/media-list.js')), 0, 12),
            'edit_locale' => $lang->getLocale(),
            'edit_language_id' => $lang->getId(),
        ]));
    }

    /**
     * The images a picker shows, a page of the library read as the list reads
     * it, or the one image `id` names: what a section or a page holds, drawn
     * before anything is picked.
     */
    #[Route('/picker', name: 'picker', methods: ['GET'])]
    public function picker(Request $request): JsonResponse
    {
        $locale = $this->editLang($request)->getLocale();
        $id = $request->query->getInt('id');

        if ($id > 0) {
            $image = $this->library->ownedImage($id);
            $item = null === $image ? null : $this->catalog->item($image, $locale, 0);

            return new JsonResponse(['items' => null === $item ? [] : [self::picked($item)]], null === $item ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);
        }

        $grid = $this->catalog->browse(MediaQuery::fromRequest($request), $locale, withUsage: false);

        return new JsonResponse([
            'items' => array_map(self::picked(...), $grid->items),
            'total' => $grid->total,
            'page' => $grid->page,
            'pageCount' => $grid->pageCount(),
            'tags' => $this->library->tagCounts(),
        ]);
    }

    /**
     * @return array{id: int, title: string, thumbnail: ?string, url: string, dimensions: ?string, width: ?int, height: ?int, alt: string, described: bool, decorative: bool}
     */
    private static function picked(MediaItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'thumbnail' => $item->thumbnailUrl,
            'url' => $item->url,
            'dimensions' => $item->dimensions(),
            'width' => $item->width,
            'height' => $item->height,
            'alt' => trim((string) $item->alt),
            'described' => $item->isDescribed(),
            'decorative' => $item->decorative,
        ];
    }

    /**
     * Files sent from the list, all at once without JavaScript, one by one
     * from its script, which asks for JSON to show each file as it lands.
     */
    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->denyUnless(AccessManager::CREATE);
        $lang = $this->editLang($request);

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter(
            $request->files->all()['files'] ?? [],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        $upload = $this->writer->add($files, $lang->getLocale());

        if (\in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return new JsonResponse([
                'stored' => array_map(static fn (LibraryImage $image): array => [
                    'id' => (int) $image->getId(),
                    'title' => (string) $image->setLocale($lang->getLocale())->getTitle(),
                ], $upload->stored),
                'prepared' => $upload->prepared,
                // The picker of the editor opens this screen in a frame, which
                // hands the image added back to it.
                'prepare' => [] === $upload->prepared ? null : $this->urls->generate('admin.cms.media.svg.show', SvgPreparationController::parameters(
                    $upload->preparedTokens(),
                    $upload->storedIds(),
                    null,
                    $lang->getId(),
                    picker: $request->query->getBoolean('picker'),
                )),
                'refused' => $this->refusals($upload),
            ], [] === $files ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }

        if ([] === $files) {
            return $this->backToList($request, $lang, $this->support->translate('Choose at least one image to add.'), 'warning');
        }

        if ([] !== $upload->refused) {
            $this->support->flash($request, 'warning', $this->support->translate(
                'Not added: %files%.',
                ['%files%' => implode(', ', array_map(static fn (array $refusal): string => $refusal['name'].' ('.$refusal['reason'].')', $this->refusals($upload)))],
            ));
        }

        // An SVG is shown to the editor before it enters the library: the
        // screen of the first one, then the others, then the images that were
        // stored (they are carried along to the end of the way).
        if ([] !== $upload->prepared) {
            return $this->support->redirect('admin.cms.media.svg.show', SvgPreparationController::parameters(
                $upload->preparedTokens(),
                $upload->storedIds(),
                null,
                $lang->getId(),
            ));
        }

        if ([] === $upload->stored) {
            return $this->backToList($request, $lang);
        }

        // Straight to the description screen for a single upload: an image with
        // no alternative text is not publishable, and the moment it lands is
        // the moment the editor knows what it shows.
        if (1 === \count($upload->stored)) {
            return $this->support->redirectInLanguage('admin.cms.media.edit', $lang->getId(), ['id' => $upload->stored[0]->getId()]);
        }

        // Several: the describe queue of these images, one after the other.
        $this->support->flash($request, 'success', $this->support->translate(
            '%count% images added. Describe each of them before using it in a page.',
            ['%count%' => \count($upload->stored)],
        ));

        return $this->support->redirect('admin.cms.media.describe', [
            self::EDIT_LANGUAGE_PARAMETER => $lang->getId(),
            'images' => implode(',', $upload->storedIds()),
        ]);
    }

    /**
     * @return list<array{name: string, reason: string}>
     */
    private function refusals(MediaUpload $upload): array
    {
        return array_map(fn (array $refusal): array => [
            'name' => $refusal['name'],
            'reason' => $this->support->translate($refusal['refusal']->message()),
        ], $upload->refused);
    }

    /**
     * The images of a list that say nothing in the language, one at a time:
     * what each shows, or that it is decorative, then the next one. Saving
     * the description of 34 images is 34 answers, not 34 sheets opened.
     */
    #[Route('/describe', name: 'describe', methods: ['GET', 'POST'])]
    public function describe(Request $request): Response
    {
        $lang = $this->editLang($request);
        $locale = $lang->getLocale();
        $query = MediaQuery::fromRequest($request);
        $queue = $this->catalog->toDescribe($query, $locale, $request->query->getInt('at'));
        $position = $queue->page - 1;

        // The image answered is the one the screen showed, named in the
        // address: the queue may have moved since.
        $image = $request->isMethod('POST')
            ? $this->ownedImageOrFail($request->query->getInt('image'))
            : $this->library->ownedImage($queue->items[0]->id ?? 0);

        if (!$image instanceof LibraryImage) {
            return $this->backToList($request, $lang, $this->support->translate('Every image of this list is described.'));
        }

        $form = $this->forms->create(MediaDescriptionType::class, ['alt' => null, 'decorative' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyUnless(AccessManager::UPDATE);
            $data = $form->getData();
            $this->writer->describe($image, $locale, $data['alt'], true === $data['decorative']);

            // Described, it left the queue: the next one is at its position.
            return $this->support->redirect('admin.cms.media.describe', [
                self::EDIT_LANGUAGE_PARAMETER => $lang->getId(),
                'at' => $position,
            ] + $query->queueParameters());
        }

        return new Response($this->twig->render(self::DESCRIBE_TEMPLATE, [
            'form' => $form->createView(),
            'item' => $this->catalog->item($image, $locale),
            'position' => $position,
            'total' => $queue->total,
            'list_parameters' => $query->parameters(),
            'queue_parameters' => $query->queueParameters(),
            'edit_locale' => $locale,
            'edit_language_id' => $lang->getId(),
        ]));
    }

    #[Route('/{id}', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $lang = $this->editLang($request);
        $locale = $lang->getLocale();
        $image = $this->ownedImageOrFail($id);

        $form = $this->buildForm($image, $locale);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->denyUnless(AccessManager::UPDATE);
            $data = $form->getData();
            $svg = $data['file'] instanceof UploadedFile && SvgUpload::isSvg($data['file']) ? $data['file'] : null;
            $this->writer->save($image, $locale, $data);

            if (null !== $svg) {
                return $this->replaceWithSvg($request, $lang, $id, $svg);
            }

            return $this->support->redirect('admin.cms.media.edit', [
                'id' => $id,
                self::EDIT_LANGUAGE_PARAMETER => $lang->getId(),
            ] + MediaQuery::fromRequest($request)->addressParameters());
        }

        return new Response($this->twig->render(self::EDIT_TEMPLATE, [
            // The list the image was opened from, to go back to it.
            'list_parameters' => MediaQuery::fromRequest($request)->addressParameters(),
            'descriptions' => $this->catalog->descriptionsOf($image, $locale),
            'known_tags' => array_keys($this->library->tagCounts()),
            'assets_version' => substr(hash('xxh3', (string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/media-sheet.js')), 0, 12),
            'form' => $form->createView(),
            'item' => $this->catalog->item($image, $locale),
            'usages' => $this->usages->pagesUsing($id),
            'item_usages' => $this->itemUsages($id, $locale),
            'edit_locale' => $locale,
            'edit_language_id' => $lang->getId(),
        ]));
    }

    /**
     * The other fields are saved; the file is shown on the preparation screen,
     * and replaces the image once the editor has seen what is stored.
     */
    private function replaceWithSvg(Request $request, Lang $lang, int $id, UploadedFile $file): RedirectResponse
    {
        try {
            $token = $this->writer->prepareSvg($file);
            $refusal = null === $token ? MediaUploadRefusal::Failed : null;
        } catch (UnreadableSvgException $exception) {
            $token = null;
            $refusal = MediaUploadRefusal::forUnreadableSvg($exception->reason);
        }

        if (null === $token) {
            $this->support->flash($request, 'warning', $this->support->translate(
                'Saved, but the file was not replaced: %reason%',
                ['%reason%' => $this->support->translate(($refusal ?? MediaUploadRefusal::Failed)->message())],
            ));

            return $this->support->redirect('admin.cms.media.edit', ['id' => $id, self::EDIT_LANGUAGE_PARAMETER => $lang->getId()]);
        }

        return $this->support->redirect('admin.cms.media.svg.show', SvgPreparationController::parameters([$token], [], $id, $lang->getId()));
    }

    /**
     * One answer for several images of the list: a tag given or taken off,
     * decorative or not, or deleted. An image a page or an item still shows
     * is not deleted, and the screen names it.
     */
    #[Route('/bulk', name: 'bulk', methods: ['POST'])]
    public function bulk(Request $request): RedirectResponse
    {
        $lang = $this->editLang($request);
        $tag = trim($request->request->getString('tag'));
        $images = [];

        foreach (array_unique(array_map('intval', $request->request->all('images'))) as $id) {
            $image = $this->library->ownedImage($id);

            if ($image instanceof LibraryImage) {
                $images[] = $image;
            }
        }

        if ([] === $images) {
            return $this->backToList($request, $lang, $this->support->translate('Select at least one image.'), 'warning');
        }

        $count = ['%count%' => \count($images), '%tag%' => $tag];

        switch ($request->request->getString('action')) {
            case 'tag-add':
            case 'tag-remove':
                $this->denyUnless(AccessManager::UPDATE);

                if ('' === $tag) {
                    return $this->backToList($request, $lang, $this->support->translate('Name the tag.'), 'warning');
                }

                $adding = 'tag-add' === $request->request->getString('action');

                foreach ($images as $image) {
                    $adding ? $this->library->addTag($image, $tag) : $this->library->removeTag($image, $tag);
                }

                return $this->backToList($request, $lang, $this->support->translate($adding ? '%count% image(s) tagged “%tag%”.' : '%count% image(s) no longer tagged “%tag%”.', $count));
            case 'decorative':
            case 'not-decorative':
                $this->denyUnless(AccessManager::UPDATE);
                $decorative = 'decorative' === $request->request->getString('action');

                foreach ($images as $image) {
                    $this->writer->setDecorative($image, $decorative);
                }

                return $this->backToList($request, $lang, $this->support->translate($decorative ? '%count% image(s) marked decorative.' : '%count% image(s) no longer decorative.', $count));
            case 'delete':
                $this->denyUnless(AccessManager::DELETE);
                $uses = $this->usages->countsFor(array_map(static fn (LibraryImage $image): int => (int) $image->getId(), $images));
                $kept = [];
                $deleted = 0;

                foreach ($images as $image) {
                    if (($uses[(int) $image->getId()] ?? 0) > 0) {
                        $kept[] = (string) ($image->setLocale($lang->getLocale())->getTitle() ?: '#'.$image->getId());

                        continue;
                    }

                    $this->writer->delete($image);
                    ++$deleted;
                }

                if ([] !== $kept) {
                    $this->support->flash($request, 'warning', $this->support->translate('Kept, still used by a page or an item: %titles%.', ['%titles%' => implode(', ', $kept)]));
                }

                return $this->backToList($request, $lang, $this->support->translate('%count% image(s) deleted.', ['%count%' => $deleted]));
            default:
                return $this->backToList($request, $lang, $this->support->translate('Choose what to do with the images.'), 'warning');
        }
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): RedirectResponse
    {
        $this->denyUnless(AccessManager::DELETE);
        $lang = $this->editLang($request);
        $image = $this->ownedImageOrFail($id);

        $usageCount = $this->usages->useCount($id);

        if ($usageCount > 0) {
            return $this->backToList($request, $lang, $this->support->translate(
                'This image is still used by %count% page(s) or catalogue item(s). Remove it from them first.',
                ['%count%' => $usageCount],
            ), 'warning');
        }

        $this->writer->delete($image);

        return $this->backToList($request, $lang, $this->support->translate('Image deleted.'));
    }

    /**
     * The catalogue items whose document uses the image, named in the
     * language being edited.
     *
     * @return list<array{type: string, id: int, title: string, locales: list<string>}>
     */
    private function itemUsages(int $imageId, string $locale): array
    {
        $usages = [];

        foreach ($this->usages->itemsUsing($imageId) as $usage) {
            $usages[] = [
                'type' => $usage['type']->value,
                'id' => $usage['id'],
                'title' => $this->items->find($usage['type'], $usage['id'], $locale)?->title ?? '',
                'locales' => $usage['locales'],
            ];
        }

        return $usages;
    }

    private function buildForm(LibraryImage $image, string $locale): FormInterface
    {
        $image->setLocale($locale);

        return $this->forms->create(CmsMediaType::class, [
            'title' => $image->getTitle(),
            'alt' => $image->getAlt(),
            'caption' => $image->getCaption(),
            'decorative' => 1 === $image->getDecorative(),
            'tags' => implode(', ', $this->library->tagsOf($image)),
            'file' => null,
        ]);
    }

    private function ownedImageOrFail(int $id): LibraryImage
    {
        $image = $this->library->ownedImage($id);

        if (!$image instanceof LibraryImage) {
            throw new NotFoundHttpException();
        }

        return $image;
    }

    private function denyUnless(string $access): void
    {
        $this->support->denyUnless(CmsResources::MEDIA, $access, 'You are not allowed to change CMS media.');
    }

    private function editLang(Request $request): Lang
    {
        return $this->languages->resolve($request);
    }

    private function backToList(Request $request, Lang $lang, ?string $message = null, string $level = 'success'): RedirectResponse
    {
        if (null !== $message) {
            $this->support->flash($request, $level, $message);
        }

        return $this->support->redirect('admin.cms.media.list', [
            self::EDIT_LANGUAGE_PARAMETER => $lang->getId(),
        ] + MediaQuery::fromRequest($request)->addressParameters());
    }
}
