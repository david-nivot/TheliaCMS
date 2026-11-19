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
 * The version of a draft, read under a row lock inside the transaction of the
 * write: a second writer waits for the first to commit, then reads the version
 * it left. Read in SQL, never from the model, which the instance pool of Propel
 * may hold as it was when the request first read it.
 */
final class DraftLock
{
    /** What a screen request that posted no version is taken to have read: no version matches it. */
    public const int NOT_POSTED = -1;

    /**
     * @param string   $table       `cms_page_content` or `cms_item_content`
     * @param int|null $readVersion the version the writer read; null writes whatever the draft holds
     *
     * @return int the version of the draft now, 0 for a row never saved
     *
     * @throws StaleDraftException when the version read is not the version of the draft
     */
    public static function claim(ConnectionInterface $connection, string $table, ?int $id, ?int $readVersion): int
    {
        $current = ['draft_version' => 0, 'draft_updated_by' => null, 'updated_at' => null];

        if (null !== $id) {
            $statement = $connection->prepare(\sprintf('SELECT draft_version, draft_updated_by, updated_at FROM `%s` WHERE id = :id FOR UPDATE', $table));
            $statement->bindValue(':id', $id, \PDO::PARAM_INT);
            $statement->execute();
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            $current = \is_array($row) ? $row : $current;
        }

        $version = (int) $current['draft_version'];

        if (null !== $readVersion && $readVersion !== $version) {
            throw new StaleDraftException($readVersion, $version, null === $current['draft_updated_by'] ? null : (int) $current['draft_updated_by'], null === $current['updated_at'] ? null : new \DateTimeImmutable((string) $current['updated_at']));
        }

        return $version;
    }
}
