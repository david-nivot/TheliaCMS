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

/**
 * A site on 2.12 moving to the link of a translation with its source: the two
 * columns appear on the content of the pages and on the one of the items,
 * the rows already there keep what they hold and get nothing in them (not
 * followed), and the update can be played twice.
 *
 * Runs outside a transaction, as TagFreeColorMigrationTest does and for the
 * same reasons, the columns dropped with ALGORITHM=COPY included.
 */
final class TranslationLinkMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    private const array TABLES = ['cms_page_content', 'cms_item_content'];

    private const string DELETE_ITEM_CONTENT = "DELETE FROM cms_item_content WHERE item_type = 'content' AND item_id = 999999";

    protected function tearDown(): void
    {
        $connection = $this->getPropelConnection();

        try {
            // Playing the update is what puts the columns back in the shape the shop has.
            (new TheliaCMS())->update('2.12.0', '2.13.0', $connection);
        } finally {
            $connection->exec(self::DELETE_ITEM_CONTENT);

            parent::tearDown();
        }
    }

    public function testTheColumnsComeToBothTablesTheRowsKeepTheirContentAndTheUpdateCanBePlayedTwice(): void
    {
        $page = $this->createPage('Page écrite en 2.12', html: '<h2>Avant</h2><p>Un texte de 2.12.</p>');
        $connection = $this->getPropelConnection();
        $connection->exec(self::DELETE_ITEM_CONTENT);
        $connection->exec("INSERT INTO cms_item_content (item_type, item_id, locale, draft_document, created_at, updated_at) VALUES ('content', 999999, 'fr_FR', '{\"version\":1,\"sections\":[]}', NOW(), NOW())");

        foreach (self::TABLES as $table) {
            $connection->exec(\sprintf('ALTER TABLE %s DROP COLUMN translated_from, DROP COLUMN source_fingerprint, ALGORITHM=COPY', $table));
            self::assertSame([], $this->columns($table), $table.' starts as 2.12 left it.');
        }

        $module = new TheliaCMS();
        $module->update('2.12.0', '2.13.0', $connection);
        $module->update('2.12.0', '2.13.0', $connection);

        foreach (self::TABLES as $table) {
            self::assertSame(
                ['translated_from' => ['YES', 'varchar', 5], 'source_fingerprint' => ['YES', 'longtext', null]],
                $this->columns($table),
                $table.' gets the source language and the fingerprint, both optional.',
            );
        }

        $row = $this->rows(\sprintf('SELECT draft_document, translated_from, source_fingerprint FROM cms_page_content WHERE page_id = %d', $page->getId()));
        self::assertStringContainsString('Un texte de 2.12.', (string) $row[0]['draft_document'], 'The content of the row is kept.');
        self::assertNull($row[0]['translated_from'], 'A row that exists is not followed.');
        self::assertNull($row[0]['source_fingerprint']);

        $item = $this->rows("SELECT draft_document, translated_from, source_fingerprint FROM cms_item_content WHERE item_type = 'content' AND item_id = 999999");
        self::assertSame('{"version":1,"sections":[]}', $item[0]['draft_document']);
        self::assertNull($item[0]['translated_from']);
        self::assertNull($item[0]['source_fingerprint']);
    }

    /**
     * @return array<string, array{string, string, int|null}> nullable, type and size, by column
     */
    private function columns(string $table): array
    {
        $columns = [];

        foreach ($this->rows(\sprintf("SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, DATA_TYPE AS type, CHARACTER_MAXIMUM_LENGTH AS size FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name IN ('translated_from', 'source_fingerprint') ORDER BY ORDINAL_POSITION", $table)) as $column) {
            $columns[(string) $column['name']] = [(string) $column['nullable'], (string) $column['type'], 'longtext' === $column['type'] ? null : (int) $column['size']];
        }

        return $columns;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->getPropelConnection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
