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

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Editing\ContentKey;
use TheliaCMS\Content\Editing\ContentRow;
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Content\Editing\RevisionStore;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Model\CmsItemContent;
use TheliaCMS\Page\Admin\CmsActivityLog;

/**
 * A catalogue item in one language, for the shared writer. An item has no
 * refusal of its own and no search index: what it adds is the purge of its
 * key in a shared cache, on every publication, rendering and unpublication,
 * even when nothing was online. Its log entries are filed under the page
 * resource (the default of `CmsActivityLog::record()`) with the id of the item.
 */
final readonly class ItemContentTarget implements ContentTarget
{
    public function __construct(
        private CatalogueItemType $type,
        private int $id,
        private string $locale,
        private ItemContentRepository $contents,
        private CmsActivityLog $activityLog,
        private CachePurger $httpCache,
        private ItemRevisionStore $revisions,
    ) {
    }

    public function key(): ContentKey
    {
        return new ContentKey($this->type->value, $this->id, $this->locale);
    }

    public function row(): ContentRow
    {
        return new ItemContentRow(
            $this->contents->find($this->type, $this->id, $this->locale)
            ?? (new CmsItemContent())->setItemType($this->type->value)->setItemId($this->id)->setLocale($this->locale),
        );
    }

    public function revisions(): RevisionStore
    {
        return $this->revisions;
    }

    public function refuseBeforeRendering(): void
    {
    }

    public function refuseRendered(string $html): void
    {
    }

    public function emptyContent(): \Throwable
    {
        return EmptyItemContentException::for($this->type, $this->id, $this->locale);
    }

    public function notAKeptDraft(int $revisionId): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('Revision #%d is not a kept draft of the %s #%d in %s.', $revisionId, $this->type->value, $this->id, $this->locale));
    }

    public function brokenHeadings(array $problems): \Throwable
    {
        return ItemHeadingStructureException::for($this->type, $this->id, $this->locale, $problems);
    }

    public function duringPublication(string $html, ConnectionInterface $connection): void
    {
    }

    public function afterSaved(): void
    {
        $this->activityLog->record('UPDATE', $this->id, \sprintf('CMS content of the %s #%d edited in %s', $this->type->value, $this->id, $this->locale));
    }

    public function afterPublished(bool $wasOnline, bool $withCustomCode): void
    {
        $this->httpCache->purge([$this->type->cacheKey($this->id)]);
        $this->activityLog->record('PUBLISH', $this->id, \sprintf(
            'CMS content of the %s #%d published in %s%s',
            $this->type->value,
            $this->id,
            $this->locale,
            $withCustomCode ? ' (custom code allowed)' : '',
        ));
    }

    public function afterRenderedAgain(): void
    {
        $this->httpCache->purge([$this->type->cacheKey($this->id)]);
    }

    public function afterUnpublished(bool $wasOnline): void
    {
        $this->httpCache->purge([$this->type->cacheKey($this->id)]);
        $this->activityLog->record('UNPUBLISH', $this->id, \sprintf('CMS content of the %s #%d unpublished in %s', $this->type->value, $this->id, $this->locale));
    }
}
