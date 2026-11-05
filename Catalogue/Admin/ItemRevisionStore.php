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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Content\Editing\ContentKey;
use TheliaCMS\Content\Editing\ContentSnapshot;
use TheliaCMS\Content\Editing\Revision;
use TheliaCMS\Content\Editing\RevisionStore;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsItemRevision;
use TheliaCMS\Model\CmsItemRevisionQuery;

/**
 * The versions of the documents of catalogue items, in `cms_item_revision`.
 */
final readonly class ItemRevisionStore implements RevisionStore
{
    public function draftOf(ContentKey $key): ?ContentSnapshot
    {
        $content = CmsItemContentQuery::create()->filterByItemType($key->kind)->filterByItemId($key->id)->filterByLocale($key->locale)->findOne();

        return null === $content ? null : new ContentSnapshot($content->getDraftDocument(), $content->getDraftHtml(), $content->getDraftProvenance());
    }

    public function add(ContentKey $key, ContentSnapshot $snapshot, ?string $label, ?int $createdBy, ?ConnectionInterface $connection = null): void
    {
        (new CmsItemRevision())
            ->setItemType($key->kind)
            ->setItemId($key->id)
            ->setLocale($key->locale)
            ->setDocument($snapshot->document)
            ->setHtml($snapshot->html)
            ->setProvenance($snapshot->provenance)
            ->setLabel($label)
            ->setCreatedBy($createdBy)
            ->save($connection);
    }

    public function revisions(ContentKey $key, ?string $label, int $offset = 0, ?int $limit = null, ?ConnectionInterface $connection = null): array
    {
        $query = $this->query($key, $label)->orderByCreatedAt(Criteria::DESC)->orderById(Criteria::DESC)->offset($offset);

        if (null !== $limit) {
            $query->limit($limit);
        }

        $revisions = [];

        foreach ($query->find($connection) as $row) {
            $revisions[] = $this->revision($row);
        }

        return $revisions;
    }

    public function find(ContentKey $key, ?string $label, int $revisionId): ?Revision
    {
        $row = $this->query($key, $label)->filterById($revisionId)->findOne();

        return null === $row ? null : $this->revision($row);
    }

    public function delete(ContentKey $key, int $revisionId, ?ConnectionInterface $connection = null): void
    {
        CmsItemRevisionQuery::create()->filterByItemType($key->kind)->filterByItemId($key->id)->filterByLocale($key->locale)->filterById($revisionId)->delete($connection);
    }

    private function query(ContentKey $key, ?string $label): CmsItemRevisionQuery
    {
        return CmsItemRevisionQuery::create()
            ->filterByItemType($key->kind)
            ->filterByItemId($key->id)
            ->filterByLocale($key->locale)
            ->filterByLabel($label, null === $label ? Criteria::ISNULL : Criteria::EQUAL);
    }

    private function revision(CmsItemRevision $row): Revision
    {
        return new Revision((int) $row->getId(), $row->getCreatedAt(), new ContentSnapshot($row->getDocument(), $row->getHtml(), $row->getProvenance()));
    }
}
