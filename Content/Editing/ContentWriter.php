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

namespace TheliaCMS\Content\Editing;

use Propel\Runtime\Propel;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Ai\AiProvenance;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\AccessibilityChecker;
use TheliaCMS\Content\Pipeline\EmptyContentChecker;
use TheliaCMS\Content\Pipeline\HeadingChecker;
use TheliaCMS\Content\Pipeline\ImageRewriter;
use TheliaCMS\Content\Pipeline\PageContentNormalizer;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\CmsSettings;

/**
 * Every write to the document of a page or of a catalogue item goes through
 * here: validated, rendered and filtered, then published in one transaction
 * with its revision. What differs from one family to the other (refusals,
 * side effects, wording) is answered by the `ContentTarget`.
 */
final readonly class ContentWriter
{
    public function __construct(
        private SecurityContext $securityContext,
        private PageContentNormalizer $normalizer,
        private PublishedContentSanitizer $sanitizer,
        private EmptyContentChecker $emptiness,
        private ImageRewriter $images,
        private DocumentValidator $validator,
        private DocumentRenderer $renderer,
        private HeadingChecker $headings,
        private AccessibilityChecker $accessibility,
        private CmsSettings $settings,
        private ContentVersions $versions,
    ) {
    }

    /**
     * Stores the document and its rendering, without touching the published
     * snapshot: saving never changes what visitors see.
     *
     * A generation hands in its provenance, which replaces the mark of the
     * draft. A person editing the draft hands in none, and the mark stays:
     * a section rewritten by hand is still part of a document the model wrote,
     * and nothing here can tell when that stops being true.
     *
     * @throws InvalidDocumentException when the document cannot be stored
     */
    public function saveContent(ContentTarget $target, Document $document, ?AiProvenance $provenance = null): void
    {
        $document = $this->validator->validate($document);

        // Drafts are filtered too, not only what goes online: the draft is
        // shown by the preview and loaded back in the back office of whoever
        // opens the content next, so a script left in it would run there.
        $html = $this->sanitizer->html($this->normalizer->html($this->renderer->render($document, $target->key()->locale)), $this->mayPublishCustomCode());

        $target->row()->saveDraft(
            $document->toJson(),
            $html,
            $this->securityContext->getAdminUser()?->getId(),
            null === $provenance ? null : ProvenanceMark::encode($provenance),
        );

        $target->afterSaved();
    }

    /**
     * Keeps the draft as it is before a generation replaces it: a revision
     * labelled "draft", which `restoreDraft()` puts back. An empty draft is
     * not worth keeping.
     */
    public function keepDraft(ContentTarget $target): void
    {
        $this->versions->keepDraft($target);
    }

    /**
     * The drafts kept for this content in this language, the latest first.
     *
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    public function previousDrafts(ContentTarget $target): array
    {
        return $this->versions->drafts($target);
    }

    /**
     * Puts a kept draft back as the draft, the current one kept in its
     * place: a restore is undone the same way.
     *
     * @throws \InvalidArgumentException when the revision is not a kept draft of this content in this language
     * @throws InvalidDocumentException  when the kept document no longer passes
     */
    public function restoreDraft(ContentTarget $target, int $revisionId): void
    {
        $restorable = $this->versions->restorable($target, $revisionId);

        if (null === $restorable) {
            throw $target->notAKeptDraft($revisionId);
        }

        $this->keepDraft($target);
        $this->saveContent($target, $restorable->document);
        // The draft put back brings its own mark, or none.
        $target->row()->replaceDraftProvenance($restorable->provenance);
    }

    /**
     * Renders what is online again from the published document, for a
     * rendering the module changed since it was published (the `sizes` of its
     * images, say). Only the published HTML is written: the draft, the kept
     * versions, the date of the publication, the search entry and the activity
     * log stay as they are, and none of the publication checks runs, a content
     * online being online whatever those would say today.
     *
     * The right to custom code is the one of whoever runs this, nobody from
     * the command line: a rendering that would take out an iframe the stored
     * one holds is not written, and the content waits for a save by someone
     * who holds the right.
     *
     * @param bool $write false for a dry run: the outcome is the same, nothing is written
     *
     * @throws InvalidDocumentException when the document is no longer accepted by the catalogue
     * @throws \Throwable               the empty-content exception of the family when the new rendering would show nothing
     */
    public function renderPublishedAgain(ContentTarget $target, bool $write = true): PublishedRendering
    {
        $row = $target->row();
        $document = Document::fromJson($row->publishedDocument());

        if (null === $document || !$row->isOnline()) {
            return PublishedRendering::NotOnline;
        }

        $stored = $row->publishedHtml();
        $html = $this->images->rewrite(
            $this->sanitizer->html($this->normalizer->html($this->renderer->render($this->validator->validate($document), $target->key()->locale)), $this->mayPublishCustomCode()),
            $target->key()->locale,
        );

        if ($html === $stored) {
            return PublishedRendering::Unchanged;
        }

        if ($this->sanitizer->customCodeCount($html) < $this->sanitizer->customCodeCount($stored)) {
            return PublishedRendering::KeptForCustomCode;
        }

        if ($this->emptiness->isEmpty($html)) {
            throw $target->emptyContent();
        }

        if ($write) {
            $row->rewritePublishedHtml($html);
            $target->afterRenderedAgain();
        }

        return PublishedRendering::Rendered;
    }

    /**
     * Promotes the draft to the published snapshot and takes a revision, in
     * one transaction: a half-published content would be served to visitors.
     *
     * @return list<string> what the author should fix in what went online, in the language of the back office: empty when nothing, and always in `block` mode, which refuses instead
     *
     * @throws \Throwable               the refusals of the family, the empty-content, heading and accessibility exceptions
     * @throws InvalidDocumentException when the draft is not a document the catalogue accepts
     */
    public function publish(ContentTarget $target): array
    {
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $withCustomCode = $this->mayPublishCustomCode();

            $row = $target->row();
            ['html' => $html, 'warnings' => $warnings] = $this->publishableHtml($target, $row, $withCustomCode);
            $wasOnline = $row->isOnline();

            $snapshot = $row->publish($html, $connection);

            $target->duringPublication($html, $connection);
            $this->versions->keepPublication($target, $snapshot, $connection);

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $target->afterPublished($wasOnline, $withCustomCode);

        return $warnings;
    }

    /**
     * Says whether this draft could go online, without writing anything:
     * the checks `publish()` runs, so a caller able to publish in bulk can
     * report what will be refused instead of announcing a count it will not
     * reach.
     *
     * @return list<string> the warnings `publish()` would return
     *
     * @throws \Throwable               the refusals `publish()` would raise
     * @throws InvalidDocumentException when the draft is not a document the catalogue accepts
     */
    public function assertPublishable(ContentTarget $target): array
    {
        return $this->publishableHtml($target, $target->row(), $this->mayPublishCustomCode())['warnings'];
    }

    /**
     * Takes the content offline in this language: nothing online means
     * nothing kept that a later read could mistake for it, the draft stays.
     */
    public function unpublish(ContentTarget $target): void
    {
        $row = $target->row();
        $wasOnline = $row->isOnline();

        if (!$row->isNew()) {
            $row->takeOffline();
        }

        $target->afterUnpublished($wasOnline);
    }

    /**
     * Whether the author may go beyond the standard allow list: embedding an
     * iframe, essentially. Anything else the editor produces is filtered the
     * same way for everyone.
     */
    public function mayPublishCustomCode(): bool
    {
        return $this->securityContext->isGranted(['ADMIN'], [CmsResources::CUSTOM_CODE], [], [AccessManager::UPDATE]);
    }

    /**
     * What would actually be served, or the reason it must not be.
     *
     * The refusals are decided on the filtered HTML, because that is what a
     * visitor would have been given and not what the editor holds. The content
     * is rendered again from its document rather than read from the draft
     * HTML, so a component whose template changed since the last save is
     * published the way it looks today. Checked on every path a content goes
     * online by, and not only in the editor, or the mode that keeps a broken
     * content offline would only hold there. In `warn` mode the problems found
     * come back with the HTML, for the caller to tell the author.
     *
     * @return array{html: string, warnings: list<string>}
     */
    private function publishableHtml(ContentTarget $target, ContentRow $row, bool $withCustomCode): array
    {
        $target->refuseBeforeRendering();

        $document = Document::fromJson($row->draftDocument());

        if (null === $document) {
            throw $target->emptyContent();
        }

        // Validated here as on every other path that renders a document: a
        // draft written by a script or an import never went through the
        // editor, and the renderer is only meant to meet what the validator
        // let through.
        $html = $this->images->rewrite(
            $this->sanitizer->html($this->normalizer->html($this->renderer->render($this->validator->validate($document), $target->key()->locale)), $withCustomCode),
            $target->key()->locale,
        );

        // Refused rather than stored: the column would be null, the front would
        // answer 404, and the back office would still read "published".
        if ($this->emptiness->isEmpty($html)) {
            throw $target->emptyContent();
        }

        // The theme draws the title of the content as the level 1 heading
        // above the document: the sections start at level 2 (docs/adr/0010).
        $problems = $this->headings->check($html, underTitle: true);

        if ([] !== $problems && $this->settings->headingCheckBlocks()) {
            throw $target->brokenHeadings($problems);
        }

        // Images and links are judged on the HTML that goes online: the
        // library says whether an `alt=""` was chosen or forgotten.
        $inaccessible = $this->accessibility->check($html, $target->key()->locale);

        if ([] !== $inaccessible && $this->settings->accessibilityCheckBlocks()) {
            throw $target->inaccessible($inaccessible);
        }

        $target->refuseRendered($html);

        return ['html' => $html, 'warnings' => [...$problems, ...$inaccessible]];
    }
}
