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

namespace TheliaCMS\Form;

use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TheliaCMS\Model\Map\CmsFormThrottleTableMap;

/**
 * Counts, in the database, how often something happened within a window, and
 * says whether this time is still within the limit.
 *
 * One statement per count. Reading the counter and then writing it lets every
 * request that read the same value through, and a script posting in parallel
 * is precisely that: twenty posts on a fresh counter all read zero. The upsert
 * below is serialised by the primary key, and hands back the count it wrote
 * through `LAST_INSERT_ID(expr)`, which belongs to the connection that ran it.
 *
 * The window is fixed: it opens with the first hit and closes `seconds` later,
 * whatever happens in between, and the next hit after that counts from one.
 *
 * What is counted is stored as a keyed hash: an address in clear in a table of
 * counters is a list of who wrote to the site.
 */
final readonly class Throttle
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $applicationSecret,
    ) {
    }

    /**
     * Counts one more hit and says whether it is within the limit.
     *
     * @param string $subject what is counted, in clear: hashed here
     */
    public function hit(ThrottleScope $scope, string $subject, int $limit, int $seconds, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();
        $connection = $this->connection();

        // Both assignments read the old `expires_at`: MySQL assigns from left
        // to right and MariaDB may assign at once, and `hits` comes first.
        $statement = $connection->prepare(
            'INSERT INTO `cms_form_throttle` (`scope`, `subject`, `hits`, `expires_at`) '
            .'VALUES (:scope, :subject, LAST_INSERT_ID(1), :expires_at) '
            .'ON DUPLICATE KEY UPDATE '
            .'`hits` = LAST_INSERT_ID(IF(`expires_at` <= :now_for_hits, 1, `hits` + 1)), '
            .'`expires_at` = IF(`expires_at` <= :now_for_window, :renewed_expires_at, `expires_at`)',
        );

        $expiresAt = $this->format($now->modify(\sprintf('+%d seconds', max(1, $seconds))));
        $statement->execute([
            'scope' => $scope->value,
            'subject' => $this->hash($scope, $subject),
            'expires_at' => $expiresAt,
            'now_for_hits' => $this->format($now),
            'now_for_window' => $this->format($now),
            'renewed_expires_at' => $expiresAt,
        ]);

        $hits = (int) $connection->query('SELECT LAST_INSERT_ID()')->fetchColumn();

        return $hits <= $limit;
    }

    /**
     * Drops the counters whose window has closed.
     *
     * @return int how many were dropped, or would be on a dry run
     */
    public function purge(?\DateTimeImmutable $now = null, bool $dryRun = false): int
    {
        $now = $this->format($now ?? new \DateTimeImmutable());
        $connection = $this->connection();

        $statement = $connection->prepare($dryRun
            ? 'SELECT COUNT(*) FROM `cms_form_throttle` WHERE `expires_at` <= :now'
            : 'DELETE FROM `cms_form_throttle` WHERE `expires_at` <= :now');
        $statement->execute(['now' => $now]);

        return $dryRun ? (int) $statement->fetchColumn() : $statement->rowCount();
    }

    private function hash(ThrottleScope $scope, string $subject): string
    {
        return hash_hmac(
            'sha256',
            $scope->value.'|'.$subject,
            hash_hmac('sha256', 'thelia-cms.form-throttle', $this->applicationSecret),
        );
    }

    private function format(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    private function connection(): ConnectionInterface
    {
        return Propel::getWriteConnection(CmsFormThrottleTableMap::DATABASE_NAME);
    }
}
