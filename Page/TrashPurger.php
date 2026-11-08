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

namespace TheliaCMS\Page;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Editing\ContentKey;
use TheliaCMS\Model\CmsContentStepQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Document\PageDocumentWriter;
use TheliaCMS\Settings\CmsSettings;

/**
 * Deletes for good the pages that have been in the bin longer than the site
 * keeps them.
 *
 * Runs from `maintenance:purge` — the command a Thelia site is already told to
 * schedule — and from a command of its own for a purge on demand.
 */
final readonly class TrashPurger
{
    public function __construct(
        private CmsSettings $settings,
        private PageDocumentWriter $documents,
    ) {
    }

    /**
     * @return int how many pages were deleted
     */
    public function purge(?\DateTimeImmutable $now = null): int
    {
        $cutoff = TrashRetention::cutoff($this->settings->trashRetentionDays(), $now);

        if (null === $cutoff) {
            return 0;
        }

        $pages = CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNOTNULL)
            ->filterByDeletedAt($cutoff, Criteria::LESS_THAN)
            ->find();

        if (0 === $pages->count()) {
            return 0;
        }

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();
        $files = [];

        try {
            foreach ($pages as $page) {
                // Binning a page already drops its addresses. Doing it again
                // here costs one statement and covers a page that reached the
                // bin by another route — a leftover address would route a
                // visitor to a page that no longer resolves, which is a 500
                // and not a 404.
                RewritingUrlQuery::create()
                    ->filterByView($page->getRewrittenUrlViewName())
                    ->filterByViewId((string) $page->getId())
                    ->delete($connection);

                // The rows of its documents cascade, their files do not: they
                // are removed once the deletion is committed, so a rollback
                // keeps a page whose documents still open.
                array_push($files, ...$this->documents->filesOf((int) $page->getId(), $connection));

                // The steps of Undo name the page without a foreign key.
                CmsContentStepQuery::create()
                    ->filterByContentKind(ContentKey::PAGE)
                    ->filterByContentId((int) $page->getId())
                    ->delete($connection);

                // Contents, revisions, search payload, documents and
                // translations go with the page: the foreign keys cascade.
                $page->delete($connection);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->documents->deleteFiles($files);

        return $pages->count();
    }

    /**
     * The pages the next purge is going to delete, so a caller can say what it
     * is about to do before doing it.
     *
     * @return list<CmsPage>
     */
    public function due(?\DateTimeImmutable $now = null): array
    {
        $cutoff = TrashRetention::cutoff($this->settings->trashRetentionDays(), $now);

        if (null === $cutoff) {
            return [];
        }

        return iterator_to_array(
            CmsPageQuery::create()
                ->filterByDeletedAt(null, Criteria::ISNOTNULL)
                ->filterByDeletedAt($cutoff, Criteria::LESS_THAN)
                ->orderByDeletedAt()
                ->find(),
            preserve_keys: false,
        );
    }
}
