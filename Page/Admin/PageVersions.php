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
use Thelia\Core\Security\SecurityContext;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevision;
use TheliaCMS\Model\CmsPageRevisionQuery;

/**
 * The versions kept of a page, per language: one snapshot per publication,
 * and the drafts a generation replaced. Both live in the revision table,
 * told apart by the label, and are counted and pruned apart.
 */
final readonly class PageVersions
{
    /** Publications kept per (page, locale) before the oldest ones are dropped. */
    private const int PUBLICATION_RETENTION = 20;

    /** Drafts a generation replaced, kept per (page, locale) to put one back. */
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
    public function keepPublication(CmsPage $page, string $locale, CmsPageContent $content, ConnectionInterface $connection): void
    {
        (new CmsPageRevision())
            ->setPageId($page->getId())
            ->setLocale($locale)
            ->setDocument($content->getPublishedDocument())
            ->setHtml($content->getPublishedHtml())
            ->setProvenance($content->getPublishedProvenance())
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId())
            ->save($connection);

        $this->prune($page, $locale, null, self::PUBLICATION_RETENTION, $connection);
    }

    /**
     * Keeps the draft as it is, a revision labelled "draft". An empty draft
     * is not worth keeping.
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
    public function drafts(CmsPage $page, string $locale): array
    {
        return $this->listed($page, $locale, self::DRAFT_LABEL);
    }

    /**
     * The publications kept for this page in this language, the latest first.
     *
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    public function publications(CmsPage $page, string $locale): array
    {
        return $this->listed($page, $locale, null);
    }

    /**
     * A kept draft of this page in this language; null when the id is another
     * page's, another language's, or a publication's.
     */
    public function draft(CmsPage $page, string $locale, int $revisionId): ?CmsPageRevision
    {
        return $this->revisions($page, $locale, self::DRAFT_LABEL)->filterById($revisionId)->findOne();
    }

    /**
     * A kept publication of this page in this language; null when the id is
     * another page's, another language's, or a draft's.
     */
    public function publication(CmsPage $page, string $locale, int $revisionId): ?CmsPageRevision
    {
        return $this->revisions($page, $locale, null)->filterById($revisionId)->findOne();
    }

    /**
     * @return list<array{id: int, date: \DateTimeInterface|null, sections: int}>
     */
    private function listed(CmsPage $page, string $locale, ?string $label): array
    {
        $versions = [];

        foreach ($this->revisions($page, $locale, $label)->orderByCreatedAt(Criteria::DESC)->orderById(Criteria::DESC)->find() as $revision) {
            $document = $this->documentOf($revision->getDocument());
            $versions[] = ['id' => (int) $revision->getId(), 'date' => $revision->getCreatedAt(), 'sections' => $document instanceof Document ? \count($document->sections) : 0];
        }

        return $versions;
    }

    private function revisions(CmsPage $page, string $locale, ?string $label): CmsPageRevisionQuery
    {
        return CmsPageRevisionQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->filterByLabel($label, null === $label ? Criteria::ISNULL : Criteria::EQUAL);
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
        $obsolete = $this->revisions($page, $locale, $label)
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->offset($keep)
            ->limit(1000)
            ->find($connection);

        foreach ($obsolete as $revision) {
            $revision->delete($connection);
        }
    }
}
