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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Ai\AiProvenance;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\EmptyContentChecker;
use TheliaCMS\Content\Pipeline\HeadingChecker;
use TheliaCMS\Content\Pipeline\ImageRewriter;
use TheliaCMS\Content\Pipeline\PageContentNormalizer;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Model\CmsItemContent;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsItemRevision;
use TheliaCMS\Model\CmsItemRevisionQuery;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Admin\PublishedRendering;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\CmsSettings;

/**
 * Every write to the document of a catalogue item goes through here. The
 * document is validated, rendered and filtered the way the content of a page
 * is (CmsPageWriter), and published the same way: a draft that visitors never
 * see, then a snapshot taken in one transaction with its revision.
 */
final readonly class ItemContentWriter
{
    /** Revisions kept per item and locale before the oldest ones are dropped. */
    private const int REVISION_RETENTION = 20;

    /** Drafts a generation replaced, kept per item and locale to put one back. */
    private const int DRAFT_RETENTION = 5;

    /** The label of a revision holding a draft, rather than a publication. */
    public const string DRAFT_LABEL = 'draft';

    public function __construct(
        private SecurityContext $securityContext,
        private CmsActivityLog $activityLog,
        private PageContentNormalizer $normalizer,
        private PublishedContentSanitizer $sanitizer,
        private EmptyContentChecker $emptiness,
        private ImageRewriter $images,
        private DocumentValidator $validator,
        private DocumentRenderer $renderer,
        private HeadingChecker $headings,
        private CmsSettings $settings,
        private CachePurger $httpCache,
        private ItemContentRepository $contents,
    ) {
    }

    /**
     * Stores the document and its rendering, without touching the published
     * snapshot: saving never changes what visitors see.
     *
     * A generation hands in its provenance, which replaces the mark of the
     * draft; a person editing the draft hands in none and the mark stays.
     *
     * @throws InvalidDocumentException when the document cannot be stored
     */
    public function saveContent(CatalogueItemType $type, int $id, string $locale, Document $document, ?AiProvenance $provenance = null): void
    {
        $document = $this->validator->validate($document);

        // Filtered like the draft of a page: the preview shows it and the
        // back office loads it back.
        $html = $this->sanitizer->html($this->normalizer->html($this->renderer->render($document, $locale)), $this->mayPublishCustomCode());

        $content = $this->contentFor($type, $id, $locale)
            ->setDraftDocument($document->toJson())
            ->setDraftHtml($html)
            ->setDraftUpdatedBy($this->securityContext->getAdminUser()?->getId());

        if (null !== $provenance) {
            $content->setDraftProvenance(ProvenanceMark::encode($provenance));
        }

        $content->save();

        $this->activityLog->record('UPDATE', $id, \sprintf('CMS content of the %s #%d edited in %s', $type->value, $id, $locale));
    }

    /**
     * Keeps the draft as it is before a generation replaces it: a revision
     * labelled "draft", which `restoreDraft()` puts back. An empty draft is
     * not worth keeping.
     */
    public function keepDraft(CatalogueItemType $type, int $id, string $locale): void
    {
        $content = CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->filterByLocale($locale)->findOne();
        $document = $content instanceof CmsItemContent ? $this->documentOf($content->getDraftDocument()) : null;

        if (!$document instanceof Document || $document->isEmpty()) {
            return;
        }

        (new CmsItemRevision())
            ->setItemType($type->value)
            ->setItemId($id)
            ->setLocale($locale)
            ->setDocument($content->getDraftDocument())
            ->setHtml($content->getDraftHtml())
            ->setProvenance($content->getDraftProvenance())
            ->setLabel(self::DRAFT_LABEL)
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId())
            ->save();

        $this->prune($type, $id, $locale, self::DRAFT_LABEL, self::DRAFT_RETENTION);
    }

    /**
     * The drafts kept for this item in this language, the latest first.
     *
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    public function previousDrafts(CatalogueItemType $type, int $id, string $locale): array
    {
        $drafts = [];

        foreach ($this->draftRevisions($type, $id, $locale)->orderByCreatedAt(Criteria::DESC)->orderById(Criteria::DESC)->find() as $revision) {
            $document = $this->documentOf($revision->getDocument());
            $drafts[] = ['id' => (int) $revision->getId(), 'date' => $revision->getCreatedAt(), 'sections' => $document instanceof Document ? \count($document->sections) : 0];
        }

        return $drafts;
    }

    /**
     * Puts a kept draft back as the draft, the current one kept in its
     * place: a restore is undone the same way.
     *
     * @throws \InvalidArgumentException when the revision is not a kept draft of this item in this language
     * @throws InvalidDocumentException  when the kept document no longer passes
     */
    public function restoreDraft(CatalogueItemType $type, int $id, string $locale, int $revisionId): void
    {
        $revision = $this->draftRevisions($type, $id, $locale)->filterById($revisionId)->findOne();
        $document = $revision instanceof CmsItemRevision ? Document::fromJson($revision->getDocument()) : null;

        if (!$document instanceof Document) {
            throw new \InvalidArgumentException(\sprintf('Revision #%d is not a kept draft of the %s #%d in %s.', $revisionId, $type->value, $id, $locale));
        }

        $this->keepDraft($type, $id, $locale);
        $this->saveContent($type, $id, $locale, $document);
        // The draft put back brings its own mark, or none.
        $this->contentFor($type, $id, $locale)->setDraftProvenance($revision->getProvenance())->save();
    }

    private function draftRevisions(CatalogueItemType $type, int $id, string $locale): CmsItemRevisionQuery
    {
        return CmsItemRevisionQuery::create()->filterByItemType($type->value)->filterByItemId($id)->filterByLocale($locale)->filterByLabel(self::DRAFT_LABEL);
    }

    /**
     * The document a revision holds; null when it no longer reads, which
     * only an older row could.
     */
    private function documentOf(?string $json): ?Document
    {
        try {
            return Document::fromJson($json);
        } catch (InvalidDocumentException) {
            return null;
        }
    }

    /**
     * Drops the oldest revisions of one kind beyond what is kept: the
     * publications and the drafts are counted apart.
     */
    private function prune(CatalogueItemType $type, int $id, string $locale, ?string $label, int $keep, ?ConnectionInterface $connection = null): void
    {
        $obsolete = CmsItemRevisionQuery::create()
            ->filterByItemType($type->value)
            ->filterByItemId($id)
            ->filterByLocale($locale)
            ->filterByLabel($label, null === $label ? Criteria::ISNULL : Criteria::EQUAL)
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->offset($keep)
            ->limit(1000)
            ->find($connection);

        foreach ($obsolete as $revision) {
            $revision->delete($connection);
        }
    }

    /**
     * Promotes the draft to the published snapshot and takes a revision, in
     * one transaction.
     *
     * @throws EmptyItemContentException     when the draft would put nothing online
     * @throws ItemHeadingStructureException when the headings are broken and the site keeps such a document offline
     * @throws InvalidDocumentException      when the draft is not a document the catalogue accepts
     */
    public function publish(CatalogueItemType $type, int $id, string $locale): void
    {
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $withCustomCode = $this->mayPublishCustomCode();
            $content = $this->contentFor($type, $id, $locale);
            $html = $this->publishableHtml($type, $id, $locale, $content, $withCustomCode);

            $content->setPublishedDocument($content->getDraftDocument())
                ->setPublishedHtml($html)
                ->setPublishedProvenance($content->getDraftProvenance())
                ->setPublishedAt(new \DateTimeImmutable())
                ->save($connection);

            $this->snapshot($content, $connection);

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->httpCache->purge([$type->cacheKey($id)]);
        $this->activityLog->record('PUBLISH', $id, \sprintf(
            'CMS content of the %s #%d published in %s%s',
            $type->value,
            $id,
            $locale,
            $withCustomCode ? ' (custom code allowed)' : '',
        ));
    }

    /**
     * The published counterpart of `CmsPageWriter::renderPublishedAgain()`:
     * what is online is rendered again from the published document, and only
     * the published HTML is written (see there for what is left alone).
     *
     * @param bool $write false for a dry run
     *
     * @throws InvalidDocumentException  when the document is no longer accepted by the catalogue
     * @throws EmptyItemContentException when the new rendering would show nothing
     */
    public function renderPublishedAgain(CatalogueItemType $type, int $id, string $locale, bool $write = true): PublishedRendering
    {
        $content = $this->contents->find($type, $id, $locale);
        $document = Document::fromJson($content?->getPublishedDocument());

        if (null === $content || null === $document || null === $content->getPublishedAt()) {
            return PublishedRendering::NotOnline;
        }

        $stored = $content->getPublishedHtml();
        $html = $this->images->rewrite(
            $this->sanitizer->html($this->normalizer->html($this->renderer->render($this->validator->validate($document), $locale)), $this->mayPublishCustomCode()),
        );

        if ($html === $stored) {
            return PublishedRendering::Unchanged;
        }

        if ($this->sanitizer->customCodeCount($html) < $this->sanitizer->customCodeCount($stored)) {
            return PublishedRendering::KeptForCustomCode;
        }

        if ($this->emptiness->isEmpty($html)) {
            throw EmptyItemContentException::for($type, $id, $locale);
        }

        if ($write) {
            $content->setPublishedHtml($html)->keepUpdateDateUnchanged()->save();
            $this->httpCache->purge([$type->cacheKey($id)]);
        }

        return PublishedRendering::Rendered;
    }

    /**
     * Takes the document of the item offline in this language: the page of
     * the item shows nothing of it any more, the draft stays.
     */
    public function unpublish(CatalogueItemType $type, int $id, string $locale): void
    {
        $this->contents->find($type, $id, $locale)?->setPublishedAt(null)
            ->setPublishedDocument(null)
            ->setPublishedHtml(null)
            ->setPublishedProvenance(null)
            ->save();

        $this->httpCache->purge([$type->cacheKey($id)]);
        $this->activityLog->record('UNPUBLISH', $id, \sprintf('CMS content of the %s #%d unpublished in %s', $type->value, $id, $locale));
    }

    /**
     * Forgets everything the module holds of an item the shop deleted: no
     * foreign key ties the two (docs/adr/0011), so nothing else would.
     */
    public function forget(CatalogueItemType $type, int $id): void
    {
        CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->delete();
        CmsItemRevisionQuery::create()->filterByItemType($type->value)->filterByItemId($id)->delete();
    }

    /**
     * What would be served, or the reason it must not be: rendered again from
     * the document, like a page, so a component whose template changed since
     * the last save is published the way it looks today.
     */
    private function publishableHtml(CatalogueItemType $type, int $id, string $locale, CmsItemContent $content, bool $withCustomCode): string
    {
        $document = Document::fromJson($content->getDraftDocument());

        if (null === $document) {
            throw EmptyItemContentException::for($type, $id, $locale);
        }

        // Validated here as on every other path that renders a document, as
        // for a page: a draft written by a script or an import never went
        // through the editor.
        $html = $this->images->rewrite(
            $this->sanitizer->html($this->normalizer->html($this->renderer->render($this->validator->validate($document), $locale)), $withCustomCode),
        );

        if ($this->emptiness->isEmpty($html)) {
            throw EmptyItemContentException::for($type, $id, $locale);
        }

        // The theme draws the name of the item as the level 1 heading of its
        // page, above the document: the sections start at level 2, as on a
        // page of the CMS.
        $problems = $this->headings->check($html, underTitle: true);

        if ([] !== $problems && $this->settings->headingCheckBlocks()) {
            throw ItemHeadingStructureException::for($type, $id, $locale, $problems);
        }

        return $html;
    }

    private function contentFor(CatalogueItemType $type, int $id, string $locale): CmsItemContent
    {
        return $this->contents->find($type, $id, $locale)
            ?? (new CmsItemContent())->setItemType($type->value)->setItemId($id)->setLocale($locale);
    }

    private function mayPublishCustomCode(): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::CUSTOM_CODE], [], [AccessManager::UPDATE]);
    }

    private function snapshot(CmsItemContent $content, ConnectionInterface $connection): void
    {
        (new CmsItemRevision())
            ->setItemType($content->getItemType())
            ->setItemId($content->getItemId())
            ->setLocale($content->getLocale())
            ->setDocument($content->getPublishedDocument())
            ->setHtml($content->getPublishedHtml())
            ->setProvenance($content->getPublishedProvenance())
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId())
            ->save($connection);

        $this->prune(CatalogueItemType::from((string) $content->getItemType()), (int) $content->getItemId(), (string) $content->getLocale(), null, self::REVISION_RETENTION, $connection);
    }
}
