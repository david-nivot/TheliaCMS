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

namespace TheliaCMS\Front;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Http\ShownTags;
use TheliaCMS\Page\Document\PageDocumentDelivery;
use TheliaCMS\Page\Document\PageDocumentRepository;
use TheliaCMS\Page\Document\PageDocumentStorage;

/**
 * Serves the files attached to the pages to visitors.
 *
 * The address names the row and the stored name together, and both have to
 * agree. A page offline, or never published, serves its files all the same:
 * the back office links a file from the page it is writing, and the name
 * holds eight random digits, so an address is only known to who was given it.
 * The trash is the exception: a page deleted takes its files out of reach
 * until it is restored or purged; a cache keeps the answer five minutes, not more.
 */
final readonly class PageDocumentController
{
    public function __construct(
        private PageDocumentRepository $documents,
        private PageDocumentStorage $storage,
        private ShownTags $shownTags,
    ) {
    }

    #[Route('/cms/documents/{id}/{file}', name: 'cms.page_document', requirements: ['id' => '\d+', 'file' => '[^/]+'], methods: ['GET'])]
    public function __invoke(Request $request, int $id, string $file): BinaryFileResponse
    {
        // Before any read: a name out of shape is never a path, nor a query.
        if (!PageDocumentStorage::isStoredName($file)) {
            throw new NotFoundHttpException('Not the name of a page document.');
        }

        $document = $this->documents->findForDownload($id, $file);

        if (null === $document || !$this->storage->exists($file)) {
            throw new NotFoundHttpException('No such page document.');
        }

        $response = new BinaryFileResponse($this->storage->path($file));

        // The type of the extension, not the one recorded at upload, and the
        // browser told not to look any further: a document is never a page.
        $response->headers->set('Content-Type', (string) PageDocumentDelivery::contentType($document->extension));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            PageDocumentDelivery::isInline($document->extension) ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->downloadName($document->originalName, $file),
            $this->asciiFallback($document->originalName, $document->extension),
        );

        // The file behind an address never changes, but the address may stop
        // answering (document deleted, page binned). Five minutes in any cache,
        // then it asks again: a 304 while the document is there, a 404 once it
        // is not. When the shop turns on the shared cache of the core, the
        // response is also filed under the tag of its page, which the changes
        // of the page purge at once.
        $response->setPublic();
        $response->setMaxAge(300);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setAutoEtag();
        $response->setAutoLastModified();

        // Whatever the file holds, it is never given the origin of the site: a
        // sandboxed document has no cookie, no storage, no script.
        $response->headers->set('Content-Security-Policy', 'sandbox');

        $pageId = $this->documents->pageIdOf($id);

        if (null !== $pageId) {
            $this->shownTags->add(CacheTags::page($pageId));
        }

        // Without this, the session listener rewrites the header above to
        // `private` on the way out, because a session was opened during the
        // request. Symfony strips the marker itself before sending.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        $response->isNotModified($request);

        return $response;
    }

    /**
     * The name the file is saved under: the one it was sent under, without
     * what a header or a path would take for something else.
     */
    private function downloadName(string $originalName, string $storedName): string
    {
        $name = trim(str_replace(['/', '\\', "\r", "\n", "\0"], '_', $originalName));

        return '' !== $name ? $name : $storedName;
    }

    /**
     * What a client that reads no UTF-8 name saves the file under.
     */
    private function asciiFallback(string $originalName, string $extension): string
    {
        $bare = preg_replace('/[^A-Za-z0-9._-]+/', '_', transliterator_transliterate('Any-Latin; Latin-ASCII', pathinfo($originalName, \PATHINFO_FILENAME)) ?: '') ?? '';
        $bare = trim($bare, '_.');

        return ('' !== $bare ? $bare : 'document').'.'.$extension;
    }
}
