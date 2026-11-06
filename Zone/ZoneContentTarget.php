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

namespace TheliaCMS\Zone;

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Content\Editing\ContentKey;
use TheliaCMS\Content\Editing\ContentRow;
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Content\Editing\RevisionStore;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Admin\PageContentTarget;

/**
 * The header zone in one language, for the shared writer: a page for
 * everything (draft, versions, checks), except what a page does when it goes
 * online. The zone has no address, is not searched, is in no list and no
 * menu, so none of that is written; what it does reach is every page of the
 * site, which carries it in its header: publishing, taking it offline or
 * rendering it again drops the whole site from the shared cache.
 */
final readonly class ZoneContentTarget implements ContentTarget
{
    public function __construct(
        private PageContentTarget $page,
        private CmsPage $zone,
        private string $locale,
        private CmsActivityLog $activityLog,
        private CachePurger $httpCache,
    ) {
    }

    public function key(): ContentKey
    {
        return $this->page->key();
    }

    public function row(): ContentRow
    {
        return $this->page->row();
    }

    public function revisions(): RevisionStore
    {
        return $this->page->revisions();
    }

    public function refuseBeforeRendering(): void
    {
        $this->page->refuseBeforeRendering();
    }

    public function refuseRendered(string $html): void
    {
        $this->page->refuseRendered($html);
    }

    public function emptyContent(): \Throwable
    {
        return $this->page->emptyContent();
    }

    public function notAKeptDraft(int $revisionId): \InvalidArgumentException
    {
        return $this->page->notAKeptDraft($revisionId);
    }

    public function brokenHeadings(array $problems): \Throwable
    {
        return $this->page->brokenHeadings($problems);
    }

    public function inaccessible(array $problems): \Throwable
    {
        return $this->page->inaccessible($problems);
    }

    /** Neither an address nor a line of the search index. */
    public function duringPublication(string $html, ConnectionInterface $connection): void
    {
    }

    public function afterSaved(): void
    {
        $this->page->afterSaved();
    }

    public function afterPublished(bool $wasOnline, bool $withCustomCode): void
    {
        $this->page->sign();
        $this->httpCache->purge([CacheTags::SITE]);
        $this->activityLog->record('PUBLISH', $this->id(), \sprintf('CMS header zone published in %s%s', $this->locale, $withCustomCode ? ' (custom code allowed)' : ''));
    }

    public function afterRenderedAgain(): void
    {
        $this->httpCache->purge([CacheTags::SITE]);
    }

    public function afterUnpublished(bool $wasOnline): void
    {
        $this->page->sign();
        $this->httpCache->purge([CacheTags::SITE]);
        $this->activityLog->record('UNPUBLISH', $this->id(), \sprintf('CMS header zone unpublished in %s', $this->locale));
    }

    private function id(): int
    {
        return (int) $this->zone->getId();
    }
}
