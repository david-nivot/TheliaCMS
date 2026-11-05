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

use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsItemContent;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsItemRevisionQuery;

/**
 * Reads the documents of the items of the catalogue, and forgets them with the item.
 */
final readonly class ItemContentRepository
{
    public function find(CatalogueItemType $type, int $id, string $locale): ?CmsItemContent
    {
        return CmsItemContentQuery::create()
            ->filterByItemType($type->value)
            ->filterByItemId($id)
            ->filterByLocale($locale)
            ->findOne();
    }

    public function draftDocument(CatalogueItemType $type, int $id, string $locale): ?Document
    {
        return Document::fromJson($this->find($type, $id, $locale)?->getDraftDocument());
    }

    /**
     * What the front shows of the item in this language: the published
     * rendering, or nothing while no document went online.
     */
    public function publishedHtml(CatalogueItemType $type, int $id, string $locale): ?string
    {
        $html = $this->find($type, $id, $locale)?->getPublishedHtml();

        return null === $html || '' === trim($html) ? null : $html;
    }

    /**
     * @return array<string, string|null> the draft document of the item by locale
     */
    public function draftsByLocale(CatalogueItemType $type, int $id): array
    {
        $drafts = [];

        foreach (CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->find() as $content) {
            $drafts[(string) $content->getLocale()] = $content->getDraftDocument();
        }

        return $drafts;
    }

    /**
     * Forgets everything the module holds of an item the shop deleted: no
     * foreign key ties the two (docs/adr/0011), so nothing else would.
     */
    public function forget(CatalogueItemType $type, int $id): void
    {
        CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->delete();
        CmsItemRevisionQuery::create()->filterByItemType($type->value)->filterByItemId($id)->delete();
    }
}
