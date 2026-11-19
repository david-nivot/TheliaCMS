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
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Content\Kind\ContentKinds;
use TheliaCMS\Content\Kind\UnknownContentKindException;
use TheliaCMS\Content\Pipeline\PlaceholderContentChecker;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Page\Admin\KindContentTarget;
use TheliaCMS\Page\Admin\PageContentTarget;
use TheliaCMS\Page\Admin\PageRevisionStore;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Page\PageAuthorship;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Search\SearchTextExtractor;
use TheliaCMS\Webhook\PageWebhooks;

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
        private PageAuthorship $authorship,
        private ContentKinds $kinds,
        private PageWebhooks $webhooks,
    ) {
    }

    /**
     * A content of a kind a module declares (the header zone is one) is a page
     * that goes online where its kind says, not at an address: it has a target
     * of its own. A hidden content whose kind no active module declares has
     * none: treated as a page it would be given an address and a line of the
     * search.
     *
     * @throws UnknownContentKindException when the page is a hidden content of a kind no active module declares
     */
    public function forPage(CmsPage $page, string $locale): ContentTarget
    {
        if ($this->kinds->isOrphan($page)) {
            throw UnknownContentKindException::forPage((int) $page->getId());
        }

        $target = $this->pageTarget($page, $locale);
        $kind = $this->kinds->of($page);

        return null === $kind ? $target : new KindContentTarget($target, $page, $kind, $locale, $this->activityLog, $this->httpCache);
    }

    private function pageTarget(CmsPage $page, string $locale): PageContentTarget
    {
        return new PageContentTarget($page, $locale, $this->activityLog, $this->invalidator, $this->fields, $this->placeholders, $this->searchText, $this->pageRevisions, $this->urls, $this->authorship, $this->webhooks);
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
