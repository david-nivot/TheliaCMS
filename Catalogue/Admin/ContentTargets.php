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

use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Pipeline\PlaceholderContentChecker;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Admin\PageContentTarget;
use TheliaCMS\Page\Admin\PageRevisionStore;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Search\SearchTextExtractor;

/**
 * Builds the `ContentTarget` of a page or of a catalogue item for the shared
 * writer, with the services each family needs. It names both families, so it
 * lives outside `Content/`, on the side that already depends on `Page`.
 */
final readonly class ContentTargets
{
    public function __construct(
        private CmsActivityLog $activityLog,
        private PageInvalidator $invalidator,
        private PageFields $fields,
        private PlaceholderContentChecker $placeholders,
        private SearchTextExtractor $searchText,
        private PageRevisionStore $pageRevisions,
        private ItemContentRepository $itemContents,
        private CachePurger $httpCache,
        private ItemRevisionStore $itemRevisions,
        private CmsUrlService $urls,
    ) {
    }

    public function forPage(CmsPage $page, string $locale): PageContentTarget
    {
        return new PageContentTarget($page, $locale, $this->activityLog, $this->invalidator, $this->fields, $this->placeholders, $this->searchText, $this->pageRevisions, $this->urls);
    }

    public function forItem(CatalogueItem $item): ItemContentTarget
    {
        return $this->forItemKey($item->type, $item->id, $item->locale);
    }

    /** For the callers that hold the type, the id and the language, not the item. */
    public function forItemKey(CatalogueItemType $type, int $id, string $locale): ItemContentTarget
    {
        return new ItemContentTarget($type, $id, $locale, $this->itemContents, $this->activityLog, $this->httpCache, $this->itemRevisions);
    }
}
