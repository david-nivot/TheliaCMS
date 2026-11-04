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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Ai\AiProvenance;
use Thelia\Model\LangQuery;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\EmptyContentChecker;
use TheliaCMS\Content\Pipeline\HeadingChecker;
use TheliaCMS\Content\Pipeline\ImageRewriter;
use TheliaCMS\Content\Pipeline\PageContentNormalizer;
use TheliaCMS\Content\Pipeline\PlaceholderContentChecker;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageRevision;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Model\CmsPageSearch;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Model\Map\CmsPageI18nTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Document\PageDocumentWriter;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Search\SearchTextExtractor;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\TheliaCMS;

/**
 * Every write to a page goes through here, so the side effects that must travel
 * with a change — rewritten URLs, revision snapshots, search payload — cannot
 * be forgotten by a caller.
 */
final readonly class CmsPageWriter
{
    /** Revisions kept per (page, locale) before the oldest ones are dropped. */
    private const int REVISION_RETENTION = 20;

    /** Drafts a generation replaced, kept per (page, locale) to put one back. */
    private const int DRAFT_RETENTION = 5;

    /** The label of a revision holding a draft, rather than a publication. */
    public const string DRAFT_LABEL = 'draft';

    public function __construct(
        private CmsUrlService $urls,
        private SecurityContext $securityContext,
        private CmsActivityLog $activityLog,
        private PageContentNormalizer $normalizer,
        private PublishedContentSanitizer $sanitizer,
        private EmptyContentChecker $emptiness,
        private PlaceholderContentChecker $placeholders,
        private ImageRewriter $images,
        private SearchTextExtractor $searchText,
        private DocumentValidator $validator,
        private DocumentRenderer $renderer,
        private HeadingChecker $headings,
        private CmsSettings $settings,
        // Menus, lists of pages and a shared cache in front of the site hold
        // what this writer changes, and none of them can know on its own.
        private PageInvalidator $invalidator,
        private PageFields $fields,
        private PageTypeRepository $types,
        private PageDocumentWriter $documents,
    ) {
    }

    public function saveDraft(CmsPage $page, string $locale, PageDraft $draft): void
    {
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $adminId = $this->securityContext->getAdminUser()?->getId();
            $wasNew = $page->isNew();
            // Read before anything is written: it is what says whether this save
            // moved the page, and therefore whether the pages under it have to
            // follow.
            $formerAddress = $wasNew ? null : $page->getRewrittenUrl($locale);

            if ($wasNew) {
                $page->setCreatedBy($adminId);
            }

            $page->setUpdatedBy($adminId);
            // The summary and the description are HTML a theme prints as it
            // is: they go through the filter the page content goes through.
            $mayPublishCustomCode = $this->mayPublishCustomCode();
            $page->setLocale($locale)
                ->setTitle($draft->title)
                ->setChapo($this->sanitizer->html($page->getChapo(), $mayPublishCustomCode))
                ->setDescription($this->sanitizer->html($page->getDescription(), $mayPublishCustomCode));
            // Read before the save clears it: whether the menus and the
            // breadcrumbs that name this page have to follow.
            $presenceChanged = $this->presenceChanges($page);
            $this->assertSoleModel($page, $connection);
            // Written on every save, values or not: a page whose type changed
            // drops here the values the new type does not define.
            $this->fields->write($page, $locale, $draft->fields ?? []);
            $page->save($connection);

            // The content itself belongs to the builder screen. The row is
            // still created here: it is what tells the module which locales a
            // page exists in, when URLs are regenerated.
            $content = $this->contentFor($page, $locale);

            if ($content->isNew()) {
                $content->save($connection);
            }

            $address = $this->urls->refresh($page, $locale, $draft->slug);

            // The page changed parent, or was renamed: either way the addresses
            // of the pages under it were built from the one it no longer has, and
            // so were its own addresses in the other languages. Done inside the
            // transaction of the save, because a subtree half re-addressed is a
            // site where some pages answer and others do not.
            if (null !== $formerAddress && $formerAddress !== $address) {
                $this->urls->rebuildSubtree($page);
            } elseif (null === $formerAddress && !$wasNew) {
                // A first address in this locale: the pages under it translated
                // before it answer at the root of that language until then.
                $this->urls->rebuildDescendants($page, $locale);
            }

            $connection->commit();

            // The settings of a page are live as soon as they are saved — its
            // type picks the template it is served with, its SEO fields are in
            // its head — so the copy a shared cache holds is stale already. A
            // new page was served nowhere yet.
            if ($wasNew) {
                // Nothing to drop.
            } elseif ($formerAddress !== $address) {
                // The pages under it moved with it.
                $this->invalidator->presenceChanged(...array_map(static fn (CmsPage $node): int => (int) $node->getId(), $this->branchOf($page)));
            } elseif ($presenceChanged) {
                $this->invalidator->presenceChanged((int) $page->getId());
            } else {
                $this->invalidator->contentChanged((int) $page->getId());
            }

            $this->activityLog->record($wasNew ? 'CREATE' : 'UPDATE', (int) $page->getId(), \sprintf('CMS page "%s" saved in %s', $draft->title, $locale));
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * Stores the document and its rendering, without touching the published
     * snapshot: saving never changes what visitors see.
     *
     * A generation hands in its provenance, which replaces the mark of the
     * draft. A person editing the draft hands in none, and the mark stays:
     * a section rewritten by hand is still part of a page the model wrote,
     * and nothing here can tell when that stops being true.
     *
     * @throws InvalidDocumentException when the document cannot be stored
     */
    public function saveContent(CmsPage $page, string $locale, Document $document, ?AiProvenance $provenance = null): void
    {
        $adminId = $this->securityContext->getAdminUser()?->getId();
        $document = $this->validator->validate($document);

        // Drafts are filtered too, not only what goes online: the draft is
        // shown by the preview and loaded back in the back office of whoever
        // opens the page next, so a script left in it would run there.
        $html = $this->sanitizer->html($this->normalizer->html($this->renderer->render($document, $locale)), $this->mayPublishCustomCode());

        $content = $this->contentFor($page, $locale)
            ->setDraftDocument($document->toJson())
            ->setDraftHtml($html)
            ->setDraftUpdatedBy($adminId);

        if (null !== $provenance) {
            $content->setDraftProvenance(ProvenanceMark::encode($provenance));
        }

        $content->save();

        $this->activityLog->record('UPDATE', (int) $page->getId(), \sprintf('CMS page #%d content edited in %s', $page->getId(), $locale));
    }

    /**
     * Writes the title and the description search engines show, as the
     * generator proposed them, in the language given: only where the page
     * holds none yet, so what an editor wrote is never replaced. Like the
     * other settings of a page, they are live as soon as they are saved.
     *
     * @param array<string, string> $proposed `meta_title` and `meta_description`
     *
     * @return list<string> the columns written
     */
    public function saveSeo(CmsPage $page, string $locale, array $proposed): array
    {
        $page->setLocale($locale);
        $written = [];

        if ('' === trim((string) $page->getMetaTitle()) && '' !== ($proposed['meta_title'] ?? '')) {
            $page->setMetaTitle($proposed['meta_title']);
            $written[] = 'meta_title';
        }

        if ('' === trim((string) $page->getMetaDescription()) && '' !== ($proposed['meta_description'] ?? '')) {
            $page->setMetaDescription($proposed['meta_description']);
            $written[] = 'meta_description';
        }

        if ([] === $written) {
            return [];
        }

        $page->setUpdatedBy($this->securityContext->getAdminUser()?->getId())->save();

        $this->invalidator->contentChanged((int) $page->getId());
        $this->activityLog->record('UPDATE', (int) $page->getId(), \sprintf('CMS page #%d %s written in %s', $page->getId(), implode(' and ', $written), $locale));

        return $written;
    }

    /**
     * Keeps the draft as it is before a generation replaces it: a revision
     * labelled "draft", which `restoreDraft()` puts back. An empty draft is
     * not worth keeping.
     */
    public function keepDraft(CmsPage $page, string $locale): void
    {
        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($locale)->findOne();
        $document = $content instanceof CmsPageContent ? $this->documentOf($content->getDraftDocument()) : null;

        if (!$document instanceof Document || $document->isEmpty()) {
            return;
        }

        (new CmsPageRevision())
            ->setPageId($page->getId())
            ->setLocale($locale)
            ->setDocument($content->getDraftDocument())
            ->setHtml($content->getDraftHtml())
            ->setProvenance($content->getDraftProvenance())
            ->setLabel(self::DRAFT_LABEL)
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId())
            ->save();

        $this->prune($page, $locale, self::DRAFT_LABEL, self::DRAFT_RETENTION);
    }

    /**
     * The drafts kept for this page in this language, the latest first.
     *
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    public function previousDrafts(CmsPage $page, string $locale): array
    {
        $drafts = [];

        foreach ($this->draftRevisions($page, $locale)->orderByCreatedAt(Criteria::DESC)->orderById(Criteria::DESC)->find() as $revision) {
            $document = $this->documentOf($revision->getDocument());
            $drafts[] = ['id' => (int) $revision->getId(), 'date' => $revision->getCreatedAt(), 'sections' => $document instanceof Document ? \count($document->sections) : 0];
        }

        return $drafts;
    }

    /**
     * Puts a kept draft back as the draft, the current one kept in its
     * place: a restore is undone the same way.
     *
     * @throws \InvalidArgumentException when the revision is not a kept draft of this page in this language
     * @throws InvalidDocumentException  when the kept document no longer passes
     */
    public function restoreDraft(CmsPage $page, string $locale, int $revisionId): void
    {
        $revision = $this->draftRevisions($page, $locale)->filterById($revisionId)->findOne();
        $document = $revision instanceof CmsPageRevision ? Document::fromJson($revision->getDocument()) : null;

        if (!$document instanceof Document) {
            throw new \InvalidArgumentException(\sprintf('Revision #%d is not a kept draft of page #%d in %s.', $revisionId, $page->getId(), $locale));
        }

        $this->keepDraft($page, $locale);
        $this->saveContent($page, $locale, $document);
        // The draft put back brings its own mark, or none.
        $this->contentFor($page, $locale)->setDraftProvenance($revision->getProvenance())->save();
    }

    private function draftRevisions(CmsPage $page, string $locale): CmsPageRevisionQuery
    {
        return CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($locale)->filterByLabel(self::DRAFT_LABEL);
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
    private function prune(CmsPage $page, string $locale, ?string $label, int $keep, ?ConnectionInterface $connection = null): void
    {
        $obsolete = CmsPageRevisionQuery::create()
            ->filterByPageId($page->getId())
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
     * Sets the values of the fields of a page in this language, the others
     * kept: what the generator wrote along with the document. Like the other
     * settings of a page, they are live as soon as they are saved.
     *
     * @param array<string, mixed> $values
     *
     * @throws \TheliaCMS\Page\Field\InvalidFieldsException when a value is not one its field takes
     */
    public function saveFields(CmsPage $page, string $locale, array $values): void
    {
        $this->fields->write($page, $locale, $values);
        $page->setUpdatedBy($this->securityContext->getAdminUser()?->getId())->save();

        $this->invalidator->contentChanged((int) $page->getId());
        $this->activityLog->record('UPDATE', (int) $page->getId(), \sprintf('CMS page #%d fields written in %s', $page->getId(), $locale));
    }

    /**
     * Renders the draft again from its document, for a rendering the module
     * or the theme changed since it was saved: the document, its author and
     * what is online stay as they are.
     *
     * The right to custom code is the one of whoever runs this, nobody from
     * the command line: a rendering that would take out an iframe the stored
     * one holds is not written, and the page waits for a save by someone who
     * holds the right.
     *
     * @throws InvalidDocumentException when the document is no longer accepted by the catalogue
     */
    public function renderDraftAgain(CmsPage $page, string $locale): DraftRendering
    {
        $content = $this->contentFor($page, $locale);
        $document = Document::fromJson($content->getDraftDocument());

        if (null === $document) {
            return DraftRendering::NoDraft;
        }

        $stored = $content->getDraftHtml();
        $html = $this->sanitizer->html($this->normalizer->html($this->renderer->render($this->validator->validate($document), $locale)), $this->mayPublishCustomCode());

        if ($html === $stored) {
            return DraftRendering::Unchanged;
        }

        if ($this->sanitizer->customCodeCount($html) < $this->sanitizer->customCodeCount($stored)) {
            return DraftRendering::KeptForCustomCode;
        }

        $content->setDraftHtml($html)->save();

        return DraftRendering::Rendered;
    }

    /**
     * Renders what is online again from the published document, for a
     * rendering the module changed since the page was published (the `sizes`
     * of its images, say). Only the published HTML is written: the draft, the
     * kept versions, the date of the publication, the search entry and the
     * activity log stay as they are, and none of the publication checks runs,
     * a page online being online whatever those would say today.
     *
     * Like `renderDraftAgain()`, it holds nobody's right to custom code.
     *
     * @param bool $write false for a dry run: the outcome is the same, nothing is written
     *
     * @throws InvalidDocumentException  when the document is no longer accepted by the catalogue
     * @throws EmptyPageContentException when the new rendering would show nothing
     */
    public function renderPublishedAgain(CmsPage $page, string $locale, bool $write = true): PublishedRendering
    {
        $content = $this->contentFor($page, $locale);
        $document = Document::fromJson($content->getPublishedDocument());

        if (null === $document || null === $content->getPublishedAt()) {
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
            throw EmptyPageContentException::for((int) $page->getId(), $locale);
        }

        if ($write) {
            // `updated_at` follows the draft: this is not an edit.
            $content->setPublishedHtml($html)->keepUpdateDateUnchanged()->save();
            $this->invalidator->contentChanged((int) $page->getId());
        }

        return PublishedRendering::Rendered;
    }

    /**
     * Promotes the draft to the published snapshot and takes a revision, in one
     * transaction: a half-published page would be served to visitors.
     *
     * @throws EmptyPageContentException       when the draft would put nothing online
     * @throws PlaceholderPageContentException when the draft is still the sample text of a seeded page
     * @throws HeadingStructureException       when the headings are broken and the site keeps such a page offline
     * @throws ModelPagePublicationException   when the page is the model of its type
     * @throws MissingPageFieldsException      when a field the type requires is empty
     * @throws InvalidDocumentException        when the draft is not a document the catalogue accepts
     */
    public function publish(CmsPage $page, string $locale): void
    {
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $withCustomCode = $this->mayPublishCustomCode();

            $content = $this->contentFor($page, $locale);
            $html = $this->publishableHtml($page, $locale, $content, $withCustomCode);
            $wasOnline = null !== $content->getPublishedAt();

            $content->setPublishedDocument($content->getDraftDocument())
                ->setPublishedHtml($html)
                ->setPublishedProvenance($content->getDraftProvenance())
                ->setPublishedAt(new \DateTimeImmutable())
                ->save($connection);

            $this->indexForSearch($page, $locale, $html, $connection);
            $this->snapshot($page, $locale, $content, $connection);

            $connection->commit();

            // Published again, the page only changes at its own address; for
            // the first time in this language, it appears in its menus too.
            if ($wasOnline) {
                $this->invalidator->contentChanged((int) $page->getId());
            } else {
                $this->invalidator->presenceChanged((int) $page->getId());
            }

            $this->activityLog->record('PUBLISH', (int) $page->getId(), \sprintf(
                'CMS page #%d published in %s%s',
                $page->getId(),
                $locale,
                // Traced: this page went online through the wider allow list.
                $withCustomCode ? ' (custom code allowed)' : '',
            ));
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * Says whether this draft could go online, without writing anything.
     *
     * Runs the checks through the same pipeline `publish()` uses, so that a
     * caller able to publish in bulk can report what will be refused instead of
     * announcing a count it will not reach.
     *
     * @throws EmptyPageContentException       when the draft would put nothing online
     * @throws PlaceholderPageContentException when the draft is still the sample text of a seeded page
     * @throws HeadingStructureException       when the headings are broken and the site keeps such a page offline
     * @throws ModelPagePublicationException   when the page is the model of its type
     * @throws MissingPageFieldsException      when a field the type requires is empty
     * @throws InvalidDocumentException        when the draft is not a document the catalogue accepts
     */
    public function assertPublishable(CmsPage $page, string $locale): void
    {
        $this->publishableHtml($page, $locale, $this->contentFor($page, $locale), $this->mayPublishCustomCode());
    }

    /**
     * What would actually be served, or the reason it must not be.
     *
     * The refusals are decided here, on the filtered HTML, because that is what
     * a visitor would have been given and not what the editor holds. The page
     * is rendered again from its document rather than read from the draft
     * HTML, so a component whose template changed since the last save is
     * published the way it looks today.
     *
     * @throws EmptyPageContentException       when the draft would put nothing online
     * @throws PlaceholderPageContentException when the draft is still the sample text of a seeded page
     * @throws HeadingStructureException       when the headings are broken and the site keeps such a page offline
     * @throws ModelPagePublicationException   when the page is the model of its type
     * @throws MissingPageFieldsException      when a field the type requires is empty
     * @throws InvalidDocumentException        when the draft is not a document the catalogue accepts
     */
    private function publishableHtml(CmsPage $page, string $locale, CmsPageContent $content, bool $withCustomCode): string
    {
        if (1 === $page->getModel()) {
            throw ModelPagePublicationException::for((int) $page->getId());
        }

        // Checked before the content: a list of the type sorts and filters
        // on these values, and a page without them has no place in it.
        $definitions = $this->fields->definitionsOf($page);
        $missing = $definitions->missingIn($this->fields->valuesOf($page, $locale, $definitions));

        if ([] !== $missing) {
            throw MissingPageFieldsException::for((int) $page->getId(), $locale, array_map(static fn ($field): string => $field->label($locale), $missing));
        }

        $document = Document::fromJson($content->getDraftDocument());

        if (null === $document) {
            throw EmptyPageContentException::for((int) $page->getId(), $locale);
        }

        // Validated here as on every other path that renders a document: a
        // draft written by a script or an import never went through the
        // editor, and the renderer is only meant to meet what the validator
        // let through.
        $html = $this->images->rewrite(
            $this->sanitizer->html($this->normalizer->html($this->renderer->render($this->validator->validate($document), $locale)), $withCustomCode),
        );

        // Refused rather than stored: the column would be null, the front would
        // answer 404, and the back office would still read "published".
        if ($this->emptiness->isEmpty($html)) {
            throw EmptyPageContentException::for((int) $page->getId(), $locale);
        }

        // Checked on every path a page goes online by — the editor, the
        // settings screen, the command — and not only in the editor, or the
        // mode that keeps a broken page offline would only hold there.
        $problems = $this->headings->check($html, underTitle: true);

        if ([] !== $problems && $this->settings->headingCheckBlocks()) {
            throw HeadingStructureException::for((int) $page->getId(), $locale, $problems);
        }

        // The legal pages are seeded as drafts holding instructions, on purpose.
        // Putting that text online tells visitors, and search engines, things
        // about the site that are not true. Refused in the one place every
        // publication goes through, so that a command asked to publish
        // everything cannot undo the precaution.
        $sentence = $this->placeholders->placeholderSentenceIn($html);

        if (null !== $sentence) {
            throw PlaceholderPageContentException::for((int) $page->getId(), $locale, $sentence);
        }

        return $html;
    }

    public function unpublish(CmsPage $page, string $locale): void
    {
        $content = CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->findOne();

        $wasOnline = null !== $content?->getPublishedAt();

        // The snapshot goes with the date: nothing online means nothing kept
        // that a later read could mistake for a page.
        $content?->setPublishedAt(null)
            ->setPublishedDocument(null)
            ->setPublishedHtml(null)
            ->setPublishedProvenance(null)
            ->save();

        if ($wasOnline) {
            $this->invalidator->presenceChanged((int) $page->getId());
        }

        $this->activityLog->record('UNPUBLISH', (int) $page->getId(), \sprintf('CMS page #%d unpublished in %s', $page->getId(), $locale));
    }

    /**
     * Soft delete, cascading down the subtree.
     *
     * The cascade is done here rather than by a database constraint: a real
     * `ON DELETE CASCADE` on the parent would hard-delete the children behind
     * the bin. Leaving descendants alone is not an option either — they would
     * vanish from the tree (their parent is gone from it) while still being
     * served on the front.
     *
     * Rewritten URLs go immediately: kept, they would route visitors to a page
     * that no longer resolves, which is a 500 and not a 404.
     *
     * @return list<int> the pages actually binned, deepest first
     *
     * @throws ModelPageSettingException when the page is the model of its type
     */
    public function moveToTrash(CmsPage $page): array
    {
        if (1 === $page->getModel()) {
            throw ModelPageSettingException::trash((int) $page->getId());
        }

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $deletedAt = new \DateTimeImmutable();
            $branch = $this->branchOf($page);

            foreach ($branch as $node) {
                $node->setDeletedAt($deletedAt)->save($connection);

                RewritingUrlQuery::create()
                    ->filterByView($node->getRewrittenUrlViewName())
                    ->filterByViewId((string) $node->getId())
                    ->delete($connection);
            }

            $connection->commit();

            $ids = array_map(static fn (CmsPage $node): int => (int) $node->getId(), $branch);
            $this->invalidator->presenceChanged(...$ids);
            $this->activityLog->record('DELETE', (int) $page->getId(), \sprintf('CMS pages moved to the bin: %s', implode(', ', $ids)));

            return $ids;
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * Restores a page and everything binned underneath it. A descendant whose
     * own parent is still in the bin stays there: restoring it would put it
     * back in the tree under a page nobody can see.
     */
    public function restore(CmsPage $page, string $locale): void
    {
        if (!$this->isRestorable($page)) {
            throw new \DomainException('Restore the parent page first: this page would come back under a page that is still in the bin.');
        }

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $restored = [];

            foreach ($this->branchOf($page, includeDeleted: true) as $node) {
                $node->setDeletedAt(null)->save($connection);
                $restored[] = (int) $node->getId();

                // Put back on the address it had, not on one derived from its
                // title: a page comes out of the bin the way it went in, edited
                // slug included.
                foreach ($this->publishedLocalesOf($node) as $contentLocale) {
                    $this->urls->rebuild($node, $contentLocale);
                }

                $this->urls->rebuild($node, $locale);
            }

            $connection->commit();

            $this->invalidator->presenceChanged(...$restored);
            $this->activityLog->record('RESTORE', (int) $page->getId(), \sprintf('CMS page #%d restored from the bin', $page->getId()));
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * Copies a page and its content in every locale, as a draft.
     *
     * The copy is deliberately never published and never inherits the
     * publication window: duplicating a live page must not put a second live
     * page online behind the editor's back.
     */
    public function duplicate(CmsPage $page, string $locale, string $titleSuffix): CmsPage
    {
        $copy = $this->copyOf($page, ' '.$titleSuffix, visible: false);

        $this->activityLog->record('CREATE', (int) $copy->getId(), \sprintf('CMS page #%d duplicated from #%d', $copy->getId(), $page->getId()));

        return $copy;
    }

    /**
     * The model of a page type: a page of that type nobody publishes, holding
     * the demo content its pages start from. Kept at the root of the tree,
     * offline, out of the page list.
     *
     * @throws \LogicException when the type has a model already
     */
    public function createModel(string $pageType, string $locale, string $title): CmsPage
    {
        if (null !== $this->types->modelOf($pageType)) {
            throw new \LogicException(\sprintf('The page type "%s" has a model already.', $pageType));
        }

        $model = new CmsPage();
        $model->setParent(0)
            ->setPosition(0)
            ->setVisible(0)
            ->setPageType($pageType)
            ->setModel(1);

        $this->saveDraft($model, $locale, new PageDraft(title: $title));

        return $model;
    }

    /**
     * A new page of the type of this model: its content in every language,
     * its fields, its settings, as a draft at the root of the tree, named
     * in this language as the editor asked. Online once published, like any
     * page created in the back office.
     *
     * @throws \LogicException when the page is not a model
     */
    public function startFromModel(CmsPage $model, string $locale, string $title): CmsPage
    {
        if (1 !== $model->getModel()) {
            throw new \LogicException(\sprintf('CMS page #%d is not the model of a type.', $model->getId()));
        }

        $page = $this->copyOf($model, '', visible: true, titles: [$locale => trim($title)]);

        $this->activityLog->record('CREATE', (int) $page->getId(), \sprintf('CMS page #%d started from the model of the type "%s"', $page->getId(), $model->getPageType()));

        return $page;
    }

    /**
     * @param array<string, string> $titles the title of the copy in a language, over the one of the page
     */
    private function copyOf(CmsPage $page, string $titleSuffix, bool $visible, array $titles = []): CmsPage
    {
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $copy = new CmsPage();
            $copy->setParent($page->getParent())
                ->setPosition($page->getPosition() + 1)
                ->setVisible($visible ? 1 : 0)
                ->setPageType($page->getPageType())
                ->setFields($page->getFields())
                ->setTagId($page->getTagId())
                ->setImageId($page->getImageId())
                ->setCreatedBy($this->securityContext->getAdminUser()?->getId());

            foreach (LangQuery::create()->filterByActive(1)->find() as $lang) {
                $source = $page->setLocale($lang->getLocale());
                $title = trim((string) $source->getTitle());

                if ('' === $title && !isset($titles[$lang->getLocale()])) {
                    continue;
                }

                $copy->setLocale($lang->getLocale())
                    ->setTitle($titles[$lang->getLocale()] ?? $title.$titleSuffix)
                    ->setChapo($source->getChapo())
                    ->setDescription($source->getDescription())
                    ->setMetaTitle($source->getMetaTitle())
                    ->setMetaDescription($source->getMetaDescription())
                    ->setOgTitle($source->getOgTitle())
                    ->setOgDescription($source->getOgDescription())
                    ->setOgImageId($source->getOgImageId())
                    ->setTwitterCard($source->getTwitterCard())
                    ->setNoindex($source->getNoindex())
                    ->setNofollow($source->getNofollow())
                    ->setTranslatedFields($source->getTranslatedFields());
                // `canonical` is not copied: it points at this exact page.
            }

            $copy->save($connection);

            $contents = CmsPageContentQuery::create()->filterByPageId($page->getId())->find();

            foreach ($contents as $content) {
                (new CmsPageContent())
                    ->setPageId($copy->getId())
                    ->setLocale($content->getLocale())
                    ->setDraftDocument($content->getDraftDocument() ?? $content->getPublishedDocument())
                    ->setDraftHtml($content->getDraftHtml() ?? $content->getPublishedHtml())
                    ->setDraftProvenance(null !== $content->getDraftDocument() ? $content->getDraftProvenance() : $content->getPublishedProvenance())
                    ->setDraftUpdatedBy($this->securityContext->getAdminUser()?->getId())
                    ->save($connection);

                $this->urls->refresh($copy, (string) $content->getLocale());
            }

            // Last before the commit: the files it copies are not undone by a
            // rollback, so nothing that may still fail comes after it.
            $this->documents->copyAll((int) $page->getId(), (int) $copy->getId(), $connection);

            $connection->commit();

            return $copy;
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * A type has one model: two would leave the site guessing which one new
     * pages start from.
     */
    private function assertSoleModel(CmsPage $page, ConnectionInterface $connection): void
    {
        if (1 !== $page->getModel()) {
            return;
        }

        $model = $this->types->modelOf((string) $page->getPageType(), $connection);

        if (null !== $model && (int) $model->getId() !== (int) $page->getId()) {
            throw new \LogicException(\sprintf('The page type "%s" has a model already.', $page->getPageType()));
        }
    }

    /**
     * Whether the title of the page, or what decides that it is online, is
     * about to change: a link to it, in a menu or a breadcrumb, shows both.
     */
    private function presenceChanges(CmsPage $page): bool
    {
        return $page->isColumnModified(CmsPageTableMap::COL_VISIBLE)
            || $page->isColumnModified(CmsPageTableMap::COL_PUBLISH_AT)
            || $page->isColumnModified(CmsPageTableMap::COL_UNPUBLISH_AT)
            || $page->getCurrentTranslation()->isColumnModified(CmsPageI18nTableMap::COL_TITLE);
    }

    /**
     * Makes this page the site home page.
     */
    public function setAsHome(CmsPage $page): void
    {
        $formerHome = (int) TheliaCMS::getConfigValue('home_page_id', '0');
        TheliaCMS::setConfigValue('home_page_id', (string) $page->getId());

        // The root of the site was filed under the former home page, and the
        // address of the new one now redirects there. Menus link the home page
        // to the root: the old home page would keep that link, the new one
        // would keep its own address.
        if ($formerHome !== (int) $page->getId()) {
            $this->invalidator->presenceChanged(...array_filter([$formerHome, (int) $page->getId()]));
        }

        $this->activityLog->record('UPDATE', (int) $page->getId(), \sprintf('CMS page #%d set as the home page', $page->getId()));
    }

    /**
     * A binned page can come back only if its parent is still in the tree —
     * otherwise it would reappear nowhere the editor can reach it.
     */
    public function isRestorable(CmsPage $page): bool
    {
        $parentId = (int) $page->getParent();

        if (0 === $parentId) {
            return true;
        }

        $parent = CmsPageQuery::create()->findPk($parentId);

        return null === $parent || null === $parent->getDeletedAt();
    }

    /**
     * The page and all of its descendants, parents before children.
     *
     * The walk stops on the pages it has already seen rather than after a fixed
     * number of levels. A depth limit looks like a safety net and is not one: it
     * is the pages below it that get left behind, still addressed and still
     * served, under a parent that has just gone to the bin. What has to be
     * bounded is a tree holding a cycle, which no depth a site could reach ever
     * describes.
     *
     * @return list<CmsPage>
     */
    private function branchOf(CmsPage $page, bool $includeDeleted = false): array
    {
        $branch = [$page];
        $walked = [(int) $page->getId() => true];
        $frontier = [(int) $page->getId()];

        while ([] !== $frontier) {
            $query = CmsPageQuery::create()->filterByParent($frontier);

            if (!$includeDeleted) {
                $query->filterByDeletedAt(null, Criteria::ISNULL);
            }

            $children = iterator_to_array($query->find(), false);
            $frontier = [];

            foreach ($children as $child) {
                $id = (int) $child->getId();

                if (isset($walked[$id])) {
                    continue;
                }

                $walked[$id] = true;
                $branch[] = $child;
                $frontier[] = $id;
            }
        }

        return $branch;
    }

    /**
     * @return list<string>
     */
    private function publishedLocalesOf(CmsPage $page): array
    {
        $locales = CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->select(['Locale'])
            ->find()
            ->toArray();

        return array_values(array_unique(array_map(strval(...), $locales)));
    }

    /**
     * @throws ModelPageSettingException when the page is the model of its type
     */
    public function toggleVisibility(CmsPage $page): void
    {
        if (1 === $page->getModel()) {
            throw ModelPageSettingException::visibility((int) $page->getId());
        }

        $page->setVisible(1 === $page->getVisible() ? 0 : 1)->save();
        $this->invalidator->presenceChanged((int) $page->getId());

        $this->activityLog->record(
            'UPDATE',
            (int) $page->getId(),
            \sprintf('CMS page #%d is now %s', $page->getId(), 1 === $page->getVisible() ? 'online' : 'offline')
        );
    }

    /**
     * Moves a page one slot up or down among its siblings.
     */
    public function move(CmsPage $page, int $direction): void
    {
        $siblings = CmsPageQuery::create()
            ->filterByParent($page->getParent())
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->orderByPosition()
            ->find();

        $ordered = iterator_to_array($siblings, false);
        $index = null;

        foreach ($ordered as $offset => $sibling) {
            if ($sibling->getId() === $page->getId()) {
                $index = $offset;
                break;
            }
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        if (null === $index || $target < 0 || $target >= \count($ordered)) {
            return;
        }

        [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];

        foreach ($ordered as $offset => $sibling) {
            $sibling->setPosition($offset + 1)->save();
        }

        // A list of pages may run in the order of the tree.
        $this->invalidator->listsChanged();
    }

    private function contentFor(CmsPage $page, string $locale): CmsPageContent
    {
        $content = CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->findOne();

        return $content ?? (new CmsPageContent())->setPageId($page->getId())->setLocale($locale);
    }

    /**
     * Whether the author may go beyond the standard allow list — embedding an
     * iframe, essentially. Anything else the editor produces is filtered the
     * same way for everyone.
     */
    private function mayPublishCustomCode(): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::CUSTOM_CODE], [], [AccessManager::UPDATE]);
    }

    /**
     * Refreshes the full-text payload of the page, so the front-office search
     * queries plain text and never the HTML columns.
     */
    private function indexForSearch(CmsPage $page, string $locale, ?string $html, ConnectionInterface $connection): void
    {
        $index = CmsPageSearchQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->findOne()
            ?? (new CmsPageSearch())->setPageId($page->getId())->setLocale($locale);

        $page->setLocale($locale);

        $index->setContent(trim($page->getTitle().' '.$this->searchText->extract($html)))
            ->save($connection);
    }

    private function snapshot(CmsPage $page, string $locale, CmsPageContent $content, ConnectionInterface $connection): void
    {
        (new CmsPageRevision())
            ->setPageId($page->getId())
            ->setLocale($locale)
            ->setDocument($content->getPublishedDocument())
            ->setHtml($content->getPublishedHtml())
            ->setProvenance($content->getPublishedProvenance())
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId())
            ->save($connection);

        $this->prune($page, $locale, null, self::REVISION_RETENTION, $connection);
    }
}
