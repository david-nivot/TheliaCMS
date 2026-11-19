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

namespace TheliaCMS\Tests\Integration\Module;

use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Zone\HeaderZone;

/**
 * A site on 2.35.0 moving to the kinds of content: `cms_page.content_kind`
 * and its index are added, the header zone becomes the kind `header`, and the
 * update can be played twice (a fresh install plays it after `TheliaMain.sql`,
 * which has the column already).
 *
 * Runs outside a transaction: an ALTER TABLE commits on its own, so a rollback
 * would undo nothing. The column and the index are put back by hand if the test
 * stops halfway, and a zone the test made is deleted. A column is dropped with
 * ALGORITHM=COPY, as in `PageTypeMigrationTest`.
 */
final class ContentKindMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    private ?int $zoneMadeHere = null;

    private ?int $zone = null;

    protected function tearDown(): void
    {
        $connection = $this->getPropelConnection();

        if (!$this->columnExists()) {
            $connection->exec('ALTER TABLE cms_page ADD COLUMN content_kind VARCHAR(32) NULL AFTER model');
        }

        if (!$this->indexExists()) {
            $connection->exec('ALTER TABLE cms_page ADD INDEX idx_cms_page_content_kind (content_kind)');
        }

        // A test that stopped halfway leaves the zone with another kind, or none.
        if (null !== $this->zone) {
            $connection->exec(\sprintf("UPDATE cms_page SET content_kind = 'header' WHERE id = %d", $this->zone));
            $this->zone = null;
        }

        if (null !== $this->zoneMadeHere) {
            $connection->exec(\sprintf('DELETE FROM cms_page WHERE id = %d', $this->zoneMadeHere));
            $this->zoneMadeHere = null;
        }

        parent::tearDown();
    }

    /**
     * Sabotage: delete the final `UPDATE` of `Config/update/2.36.0.sql`: the
     * zone keeps a NULL kind and the first assertions after the update are
     * red. Delete the `AND content_kind IS NULL`: the zone given another kind
     * is turned back into the header, and the last assertion is red. Delete
     * the `information_schema` checks: the second play fails on the column.
     */
    public function testTheUpdateGivesTheZoneItsKindAndCanBePlayedTwice(): void
    {
        $zones = $this->getService(HeaderZone::class);
        $existed = null !== $zones->find();
        $zone = (int) $zones->ensure()->getId();
        $this->zone = $zone;
        $this->zoneMadeHere = $existed ? null : $zone;
        $page = (int) $this->createPage('Page ordinaire de la montée', published: false)->getId();

        $this->backTo235();
        self::assertFalse($this->columnExists(), 'The site is as 2.35.0 left it.');
        self::assertFalse($this->indexExists());

        $module = new TheliaCMS();
        $module->update('2.35.0', '2.36.0', $this->getPropelConnection());
        $module->update('2.35.0', '2.36.0', $this->getPropelConnection());

        $column = $this->rows("SHOW COLUMNS FROM cms_page LIKE 'content_kind'");
        self::assertCount(1, $column);
        self::assertSame('varchar(32)', strtolower((string) $column[0]['Type']));
        self::assertSame('YES', $column[0]['Null']);
        self::assertTrue($this->indexExists(), 'The kind is indexed.');
        self::assertSame('header', $this->kindOf($zone), 'The zone, the only hidden page there was, is the kind header.');
        self::assertNull($this->kindOf($page), 'A page has no kind.');

        // A zone the update meets with no kind (a dump restored from before it): the update gives it.
        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET content_kind = NULL WHERE id = %d', $zone));
        $module->update('2.35.0', '2.36.0', $this->getPropelConnection());
        self::assertSame('header', $this->kindOf($zone));

        // A hidden page already of another kind is not turned into the zone.
        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page SET content_kind = 'test_guide' WHERE id = %d", $zone));
        $module->update('2.35.0', '2.36.0', $this->getPropelConnection());
        self::assertSame('test_guide', $this->kindOf($zone));

        $this->getPropelConnection()->exec(\sprintf("UPDATE cms_page SET content_kind = 'header' WHERE id = %d", $zone));
    }

    private function backTo235(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('ALTER TABLE cms_page DROP INDEX idx_cms_page_content_kind');
        $connection->exec('ALTER TABLE cms_page DROP COLUMN content_kind, ALGORITHM=COPY');
    }

    private function kindOf(int $pageId): ?string
    {
        $kind = $this->getPropelConnection()->query(\sprintf('SELECT content_kind FROM cms_page WHERE id = %d', $pageId))->fetchColumn();

        return \is_string($kind) ? $kind : null;
    }

    private function columnExists(): bool
    {
        return [] !== $this->rows("SHOW COLUMNS FROM cms_page LIKE 'content_kind'");
    }

    private function indexExists(): bool
    {
        return [] !== $this->rows("SHOW INDEX FROM cms_page WHERE Key_name = 'idx_cms_page_content_kind'");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->getPropelConnection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
