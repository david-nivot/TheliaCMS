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

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;

/**
 * The versions kept of a content, per language: one snapshot per publication,
 * and the drafts a generation replaced. Both live in the revision store of
 * the family of the content, told apart by the label, and are counted and
 * pruned apart.
 */
final readonly class ContentVersions
{
    /** Publications kept per (content, locale) before the oldest ones are dropped. */
    private const int PUBLICATION_RETENTION = 20;

    /** Drafts a generation replaced, kept per (content, locale) to put one back. */
    private const int DRAFT_RETENTION = 5;

    /** The label of a revision holding a draft, rather than a publication. */
    public const string DRAFT_LABEL = 'draft';

    public function __construct(
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * Keeps what has just been published, inside the transaction of the
     * publication.
     */
    public function keepPublication(ContentTarget $target, ContentSnapshot $published, ConnectionInterface $connection): void
    {
        $target->revisions()->add($target->key(), $published, null, $this->securityContext->getAdminUser()?->getId(), $connection);

        $this->prune($target, null, self::PUBLICATION_RETENTION, $connection);
    }

    /**
     * Keeps the draft as it is, a revision labelled "draft". An empty draft
     * is not worth keeping.
     */
    public function keepDraft(ContentTarget $target): void
    {
        $key = $target->key();
        $store = $target->revisions();
        $draft = $store->draftOf($key);
        $document = null === $draft ? null : $this->documentOf($draft->document);

        if (null === $draft || !$document instanceof Document || $document->isEmpty()) {
            return;
        }

        $store->add($key, $draft, self::DRAFT_LABEL, $this->securityContext->getAdminUser()?->getId());

        $this->prune($target, self::DRAFT_LABEL, self::DRAFT_RETENTION);
    }

    /**
     * The drafts kept for this content in this language, the latest first.
     *
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    public function drafts(ContentTarget $target): array
    {
        return $this->listed($target, self::DRAFT_LABEL);
    }

    /**
     * The publications kept for this content in this language, the latest first.
     *
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    public function publications(ContentTarget $target): array
    {
        return $this->listed($target, null);
    }

    /**
     * A kept draft of this content in this language; null when the id is
     * another content's, another language's, or a publication's.
     */
    public function draft(ContentTarget $target, int $revisionId): ?Revision
    {
        return $target->revisions()->find($target->key(), self::DRAFT_LABEL, $revisionId);
    }

    /**
     * A kept publication of this content in this language; null when the id
     * is another content's, another language's, or a draft's.
     */
    public function publication(ContentTarget $target, int $revisionId): ?Revision
    {
        return $target->revisions()->find($target->key(), null, $revisionId);
    }

    /**
     * The document of a kept draft, ready to be put back as the draft; null
     * when the id is not a kept draft of this content in this language.
     *
     * @throws InvalidDocumentException when the kept document no longer passes
     */
    public function restorable(ContentTarget $target, int $revisionId): ?RestorableDraft
    {
        $revision = $this->draft($target, $revisionId);
        $document = null === $revision ? null : Document::fromJson($revision->snapshot->document);

        return null === $revision || !$document instanceof Document ? null : new RestorableDraft($document, $revision->snapshot->provenance);
    }

    /**
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    private function listed(ContentTarget $target, ?string $label): array
    {
        $versions = [];

        foreach ($target->revisions()->revisions($target->key(), $label) as $revision) {
            $document = $this->documentOf($revision->snapshot->document);
            $versions[] = ['id' => $revision->id, 'date' => $revision->date, 'sections' => $document instanceof Document ? \count($document->sections) : 0];
        }

        return $versions;
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
    private function prune(ContentTarget $target, ?string $label, int $keep, ?ConnectionInterface $connection = null): void
    {
        $key = $target->key();
        $store = $target->revisions();

        foreach ($store->revisions($key, $label, $keep, 1000, $connection) as $revision) {
            $store->delete($key, $revision->id, $connection);
        }
    }
}
