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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageDocument;
use TheliaCMS\Page\Document\PageDocumentRepository;
use TheliaCMS\Page\Document\PageDocumentUpload;
use TheliaCMS\Page\Document\PageDocumentWriter;
use TheliaCMS\Security\CmsResources;

/**
 * The files of a page, from the Documents tab of its edit screen. Each action
 * is written at once, apart from the draft of the page; the token of the back
 * office is checked for every POST by CmsAdminGuard.
 */
#[Route('/admin/cms/pages/{id}/documents', name: 'admin.cms.pages.documents.', requirements: ['id' => '\d+'])]
final readonly class CmsPageDocumentController
{
    /** Characters of the title of a document, as the column holds them. */
    private const int TITLE_MAX_LENGTH = 255;

    /** Bytes of the description: a TEXT column holds 65535 bytes of UTF-8. */
    private const int DESCRIPTION_MAX_BYTES = 65535;

    public function __construct(
        private AdminControllerSupport $support,
        private CmsPageAdminRepository $pages,
        private PageDocumentWriter $writer,
        private PageDocumentRepository $documents,
        private EditLanguage $languages,
    ) {
    }

    /**
     * Files sent from the tab, all at once without JavaScript, one by one from
     * its script, which asks for JSON to show each file as it lands.
     */
    #[Route('', name: 'add', methods: ['POST'])]
    public function add(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $lang = $this->languages->resolve($request);
        $page = $this->livePageOrFail($id);

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter(
            (array) ($request->files->all()['files'] ?? []),
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));

        $upload = $this->writer->add((int) $page->getId(), $files);

        if (\in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return new JsonResponse([
                'stored' => array_map(static fn (CmsPageDocument $document): array => ['id' => (int) $document->getId(), 'name' => (string) $document->getOriginalName()], $upload->stored),
                'refused' => $this->refusals($upload),
            ], [] === $files ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }

        if ([] === $files) {
            $this->support->flash($request, 'warning', $this->support->translate('Choose at least one file to attach.'));
        }

        foreach ($this->refusals($upload) as $refusal) {
            $this->support->flash($request, 'warning', \sprintf('%s: %s', $refusal['name'], $refusal['reason']));
        }

        if ([] !== $upload->stored) {
            $this->support->flash($request, 'success', $this->support->translate('%count% file(s) attached.', ['%count%' => \count($upload->stored)]));
        }

        return $this->backToTab($request, (int) $page->getId(), $lang);
    }

    /**
     * The title and the description of one document, in the language being
     * edited: a document is one file for every language, its texts are not.
     */
    #[Route('/{documentId}', name: 'describe', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function describe(Request $request, int $id, int $documentId): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $lang = $this->languages->resolve($request);
        $this->documentOfPageOrFail($id, $documentId);

        $title = $request->request->getString('title');

        if (mb_strlen(trim($title)) > self::TITLE_MAX_LENGTH) {
            $this->support->flash($request, 'danger', $this->support->translate('The title is limited to %max% characters.', ['%max%' => self::TITLE_MAX_LENGTH]));

            return $this->backToTab($request, $id, $lang);
        }

        $description = $request->request->getString('description');

        if (\strlen(trim($description)) > self::DESCRIPTION_MAX_BYTES) {
            $this->support->flash($request, 'danger', $this->support->translate('The description is limited to %max% bytes.', ['%max%' => self::DESCRIPTION_MAX_BYTES]));

            return $this->backToTab($request, $id, $lang);
        }

        $this->writer->describe($documentId, $lang->getLocale(), $title, $description);
        $this->support->flash($request, 'success', $this->support->translate('Document saved.'));

        return $this->backToTab($request, $id, $lang);
    }

    /**
     * The documents in the order given, `documents[]` being their ids.
     */
    #[Route('/order', name: 'order', methods: ['POST'], priority: 1)]
    public function order(Request $request, int $id): Response
    {
        $this->denyUnless(AccessManager::UPDATE);
        $lang = $this->languages->resolve($request);
        $page = $this->livePageOrFail($id);

        try {
            $this->writer->reorder((int) $page->getId(), array_map('intval', $request->request->all('documents')));
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage(), $exception);
        }

        return $this->backToTab($request, $id, $lang);
    }

    #[Route('/{documentId}/delete', name: 'delete', requirements: ['documentId' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id, int $documentId): Response
    {
        $this->denyUnless(AccessManager::DELETE);
        $lang = $this->languages->resolve($request);
        $this->documentOfPageOrFail($id, $documentId);

        $this->writer->delete($documentId);
        $this->support->flash($request, 'success', $this->support->translate('Document deleted.'));

        return $this->backToTab($request, $id, $lang);
    }

    /**
     * @return list<array{name: string, reason: string}>
     */
    private function refusals(PageDocumentUpload $upload): array
    {
        return array_map(fn (array $refusal): array => [
            'name' => $refusal['name'],
            'reason' => $this->support->translate($refusal['reason']->message()),
        ], $upload->refused);
    }

    private function livePageOrFail(int $id): CmsPage
    {
        return $this->pages->findLive($id) ?? throw new NotFoundHttpException();
    }

    /**
     * A document of this page, or a 404: the address names both, and a
     * document of another page is not reachable through this one.
     */
    private function documentOfPageOrFail(int $pageId, int $documentId): void
    {
        $this->livePageOrFail($pageId);

        if (!$this->documents->belongsToPage($documentId, $pageId)) {
            throw new NotFoundHttpException();
        }
    }

    private function denyUnless(string $access): void
    {
        $this->support->denyUnless(CmsResources::PAGE, $access, 'You are not allowed to change CMS pages.');
    }

    private function backToTab(Request $request, int $pageId, Lang $lang): RedirectResponse
    {
        return $this->support->redirectInLanguage('admin.cms.pages.edit', $lang->getId(), ['id' => $pageId, ...PageFilters::returnQuery($request)], 'tab-documents');
    }
}
