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
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\TheliaCMS;

/**
 * Removes the addresses of pages, and the redirections that lead to them.
 *
 * `rewriting_url.redirected` is a foreign key to `rewriting_url.id` that
 * restricts, and MySQL checks it row by row: one `DELETE` of the addresses of a
 * page fails (SQLSTATE 23000, 1451) as soon as a row left in the table still
 * points at one of them. Two cases do, and neither is rare:
 *
 * - an address of another view redirects to an address of the page, such as the
 *   former `content` address of a content taken over by a page;
 * - an address of the page redirects to an address of the page with a smaller
 *   id, which the statement deletes first: a page that went back to an earlier
 *   address, the newer row pointing at the older.
 *
 * A redirection to an address that no longer exists leads nowhere, so every row
 * that redirects to one of them goes with them, whatever its view, and so do
 * the rows that redirect to those. The whole set is detached (`redirected` set
 * to NULL), then deleted, so no row is deleted while another still points at
 * it.
 *
 * Runs on the connection of the caller, therefore in its transaction.
 */
final readonly class PageAddressRemover
{
    /**
     * @param int $chunkSize rows per statement, to keep an `IN` list within what MySQL parses fast
     */
    public function __construct(
        private int $chunkSize = 500,
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException('The size of a chunk is at least 1.');
        }
    }

    /**
     * @param list<int|string> $pageIds
     */
    public function removeOfPages(array $pageIds, ?ConnectionInterface $connection = null): RemovedAddresses
    {
        if ([] === $pageIds) {
            return new RemovedAddresses(0, 0);
        }

        $connection ??= Propel::getConnection('TheliaMain');
        $seeds = [];

        foreach (array_chunk(array_map('strval', $pageIds), $this->chunkSize) as $chunk) {
            $seeds[] = $this->ids(
                RewritingUrlQuery::create()
                    ->filterByView(TheliaCMS::PAGE_VIEW)
                    ->filterByViewId($chunk, Criteria::IN),
                $connection,
            );
        }

        return $this->removeWithRedirections(array_merge(...$seeds), $connection);
    }

    /**
     * Every address of every page of the module.
     */
    public function removeOfAllPages(?ConnectionInterface $connection = null): RemovedAddresses
    {
        $connection ??= Propel::getConnection('TheliaMain');

        return $this->removeWithRedirections(
            $this->ids(RewritingUrlQuery::create()->filterByView(TheliaCMS::PAGE_VIEW), $connection),
            $connection,
        );
    }

    /**
     * @param list<int> $seeds
     */
    private function removeWithRedirections(array $seeds, ConnectionInterface $connection): RemovedAddresses
    {
        $doomed = array_fill_keys($seeds, true);
        $frontier = array_keys($doomed);

        // Breadth first, each row once: a cycle of redirections ends the walk
        // instead of feeding it.
        while ([] !== $frontier) {
            $found = [];

            foreach (array_chunk($frontier, $this->chunkSize) as $chunk) {
                foreach ($this->ids(RewritingUrlQuery::create()->filterByRedirected($chunk, Criteria::IN), $connection) as $id) {
                    if (!isset($doomed[$id])) {
                        $doomed[$id] = true;
                        $found[] = $id;
                    }
                }
            }

            $frontier = $found;
        }

        $ids = array_keys($doomed);
        $deleted = 0;
        $ofOtherViews = 0;

        // Detaching then deleting is two steps: alone, a failure between them
        // would leave rows whose redirection was cleared.
        $connection->beginTransaction();

        try {
            foreach (array_chunk($ids, $this->chunkSize) as $chunk) {
                $ofOtherViews += RewritingUrlQuery::create()
                    ->filterById($chunk, Criteria::IN)
                    ->filterByView(TheliaCMS::PAGE_VIEW, Criteria::NOT_EQUAL)
                    ->count($connection);
                RewritingUrlQuery::create()->filterById($chunk, Criteria::IN)->update(['Redirected' => null], $connection);
            }

            foreach (array_chunk($ids, $this->chunkSize) as $chunk) {
                $deleted += RewritingUrlQuery::create()->filterById($chunk, Criteria::IN)->delete($connection);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        return new RemovedAddresses($deleted, $ofOtherViews);
    }

    /**
     * @return list<int>
     */
    private function ids(RewritingUrlQuery $query, ConnectionInterface $connection): array
    {
        return array_map('intval', $query->select('Id')->find($connection)->toArray());
    }
}
