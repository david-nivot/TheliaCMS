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

namespace TheliaCMS\Catalogue;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Thelia\Core\Event\Category\CategoryDeleteEvent;
use Thelia\Core\Event\Content\ContentDeleteEvent;
use Thelia\Core\Event\Folder\FolderDeleteEvent;
use Thelia\Core\Event\Product\ProductDeleteEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * A deleted item takes its documents with it. Listened to after the core has
 * deleted it (a negative priority), so a deletion the core refuses leaves the
 * documents in place.
 */
final readonly class ItemDeletionListener
{
    public function __construct(
        private ItemContentRepository $contents,
    ) {
    }

    #[AsEventListener(event: TheliaEvents::PRODUCT_DELETE, priority: -128)]
    public function onProductDelete(ProductDeleteEvent $event): void
    {
        $this->contents->forget(CatalogueItemType::Product, $event->getProductId());
    }

    #[AsEventListener(event: TheliaEvents::CATEGORY_DELETE, priority: -128)]
    public function onCategoryDelete(CategoryDeleteEvent $event): void
    {
        $this->contents->forget(CatalogueItemType::Category, (int) $event->getCategoryId());
    }

    #[AsEventListener(event: TheliaEvents::CONTENT_DELETE, priority: -128)]
    public function onContentDelete(ContentDeleteEvent $event): void
    {
        $this->contents->forget(CatalogueItemType::Content, (int) $event->getContentId());
    }

    #[AsEventListener(event: TheliaEvents::FOLDER_DELETE, priority: -128)]
    public function onFolderDelete(FolderDeleteEvent $event): void
    {
        $this->contents->forget(CatalogueItemType::Folder, $event->getFolderId());
    }

    /**
     * The event of the Dealer module is named by its string and typed loosely:
     * its class is absent on a site without the module.
     */
    #[AsEventListener(event: DealerStores::DELETED_EVENT)]
    public function onStoreDelete(object $event): void
    {
        if (!method_exists($event, 'getDealer')) {
            return;
        }

        $id = $event->getDealer()?->getId();

        if (null !== $id) {
            $this->contents->forget(CatalogueItemType::Dealer, (int) $id);
        }
    }
}
