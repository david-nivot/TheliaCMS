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

/**
 * Where the versions of one family of contents are stored. Every read and
 * every write is bound to a key, so a revision of one content is never found
 * under another.
 */
interface RevisionStore
{
    /** The draft the content holds now; null when it has none. */
    public function draftOf(ContentKey $key): ?ContentSnapshot;

    public function add(ContentKey $key, ContentSnapshot $snapshot, ?string $label, ?int $createdBy, ?ConnectionInterface $connection = null): void;

    /**
     * The revisions of a key, the latest first; the label null selects the
     * publications.
     *
     * @return list<Revision>
     */
    public function revisions(ContentKey $key, ?string $label, int $offset = 0, ?int $limit = null, ?ConnectionInterface $connection = null): array;

    /** One revision of this key and label; null when the id belongs to another key or label. */
    public function find(ContentKey $key, ?string $label, int $revisionId): ?Revision;

    public function delete(ContentKey $key, int $revisionId, ?ConnectionInterface $connection = null): void;
}
