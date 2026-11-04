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

use Thelia\Core\Install\Database;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * A site on 1.5.0 moving to documents: the columns of the former editor go,
 * the document columns come, the pages stay and their content does not, the
 * tables of the blocks and of the saved templates are renamed `cms_legacy_*`
 * with their rows, what the former editor wrote is copied to
 * `cms_legacy_page_content` and `cms_legacy_page_revision`, and the update can
 * be played twice.
 *
 * Runs outside a transaction: an ALTER TABLE commits on its own, so a rollback
 * would undo nothing and the test would prove nothing. The tables are put back
 * the way 1.5.0 left them, the update is played, and the shop ends up on the
 * shape it had before the test.
 *
 * A column is dropped with ALGORITHM=COPY: MariaDB drops it instantly
 * otherwise and keeps it in the rows, so about thirty runs leave the table
 * refusing the column back ("Row size too large").
 */
final class ContentMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    private const LEGACY_TABLES = [
        'cms_legacy_item_block',
        'cms_legacy_block_content',
        'cms_legacy_block_i18n',
        'cms_legacy_block',
        'cms_legacy_page_template',
        'cms_legacy_page_content',
        'cms_legacy_page_revision',
    ];

    protected function tearDown(): void
    {
        // Whatever the test did, leave the tables in the 2.0.0 shape: playing
        // the update once more is exactly what does that.
        (new TheliaCMS())->update('1.5.0', '2.0.0', $this->getPropelConnection());
        $this->dropLegacyTables();

        parent::tearDown();
    }

    public function testThePagesStayTheirContentGoesAndTheUpdateCanBePlayedTwice(): void
    {
        $page = $this->createPage('Page écrite avec l’ancien éditeur', html: '<h2>Avant</h2><p>Un texte de 1.5.0.</p>');
        $pageId = (int) $page->getId();
        $locale = $this->locale();

        $this->backTo150($pageId, $locale);

        self::assertTrue($this->columnExists('cms_page_content', 'draft_project_data'));
        self::assertFalse($this->columnExists('cms_page_content', 'draft_document'));
        self::assertSame(1, $this->rowCount('cms_item_block'));
        self::assertSame(1, $this->rowCount('cms_page_template'));

        $module = new TheliaCMS();
        $module->update('1.5.0', '2.0.0', $this->getPropelConnection());
        $module->update('1.5.0', '2.0.0', $this->getPropelConnection());

        self::assertTrue($this->columnExists('cms_page_content', 'draft_document'));
        self::assertTrue($this->columnExists('cms_page_content', 'published_document'));
        self::assertFalse($this->columnExists('cms_page_content', 'draft_project_data'));
        self::assertFalse($this->columnExists('cms_page_content', 'draft_css'));
        self::assertFalse($this->columnExists('cms_page_content', 'published_css'));

        foreach (['cms_block', 'cms_block_i18n', 'cms_block_content', 'cms_item_block', 'cms_page_template'] as $table) {
            self::assertFalse($this->tableExists($table), $table.' is no longer a table of the module.');
            self::assertTrue($this->tableExists(str_replace('cms_', 'cms_legacy_', $table)), $table.' is kept under its legacy name.');
        }

        self::assertSame(['product-1'], array_column($this->rows('SELECT code FROM cms_legacy_block'), 'code'));
        self::assertSame(['<p>Bloc</p>'], array_column($this->rows('SELECT draft_html FROM cms_legacy_block_content'), 'draft_html'));
        self::assertSame([['item_type' => 'product', 'item_id' => 1]], $this->rows('SELECT item_type, item_id FROM cms_legacy_item_block'));
        self::assertSame(1, $this->rowCount('cms_legacy_page_template'));

        $legacyContent = $this->rows(\sprintf("SELECT draft_html, draft_project_data, draft_css, published_css FROM cms_legacy_page_content WHERE page_id = %d AND locale = '%s'", $pageId, $locale));
        self::assertCount(1, $legacyContent);
        self::assertStringContainsString('<h2>Avant</h2><p>Un texte de 1.5.0.</p>', (string) $legacyContent[0]['draft_html']);
        self::assertSame('{"pages":[{"frames":[]}]}', $legacyContent[0]['draft_project_data']);
        self::assertSame('body{color:red}', $legacyContent[0]['draft_css']);
        self::assertSame('.cms-page-content{color:red}', $legacyContent[0]['published_css']);

        $legacyRevision = $this->rows(\sprintf('SELECT html, project_data, css FROM cms_legacy_page_revision WHERE page_id = %d AND label = \'v1\'', $pageId));
        self::assertCount(1, $legacyRevision);
        self::assertSame('<p>Révision de 1.5.0</p>', $legacyRevision[0]['html']);

        self::assertTrue($this->columnExists('cms_page_revision', 'document'));
        self::assertFalse($this->columnExists('cms_page_revision', 'project_data'));
        self::assertFalse($this->columnExists('cms_page_revision', 'css'));

        $row = $this->rows(\sprintf("SELECT draft_html, published_html, published_at, draft_document FROM cms_page_content WHERE page_id = %d AND locale = '%s'", $pageId, $locale));

        self::assertCount(1, $row, 'The content row stays, emptied: the page keeps its place in every language it had.');
        self::assertNull($row[0]['draft_html']);
        self::assertNull($row[0]['published_html']);
        self::assertNull($row[0]['published_at']);
        self::assertNull($row[0]['draft_document']);

        $title = $this->rows(\sprintf("SELECT title FROM cms_page_i18n WHERE id = %d AND locale = '%s'", $pageId, $locale));
        self::assertSame('Page écrite avec l’ancien éditeur', $title[0]['title'] ?? null, 'The page itself is kept.');

        self::assertSame(0, $this->rowCount(\sprintf('cms_page_revision WHERE page_id = %d', $pageId)));
        self::assertSame(0, $this->rowCount(\sprintf('cms_page_search WHERE page_id = %d', $pageId)));
    }

    public function testAFreshInstallEndsWithNoLegacyTable(): void
    {
        // What the core does on a fresh install: every update replayed, the
        // ones of 1.x included, which create the tables of the blocks empty.
        (new TheliaCMS())->update('0.0.0', '2.0.0', $this->getPropelConnection());

        foreach (array_merge(self::LEGACY_TABLES, ['cms_block', 'cms_block_i18n', 'cms_block_content', 'cms_item_block', 'cms_page_template']) as $table) {
            self::assertFalse($this->tableExists($table), $table.' has nothing to keep on a fresh install.');
        }
    }

    public function testTablesWithNoRowAreDroppedWhereThereIsNothingToKeep(): void
    {
        $page = $this->createPage('Page sans bloc', html: '<p>Texte</p>');
        $this->backTo150((int) $page->getId(), $this->locale());

        $connection = $this->getPropelConnection();
        $connection->exec('DELETE FROM cms_item_block');
        $connection->exec('DELETE FROM cms_block_content');
        $connection->exec('DELETE FROM cms_block_i18n');
        $connection->exec('DELETE FROM cms_block');
        $connection->exec('DELETE FROM cms_page_template');

        (new TheliaCMS())->update('1.5.0', '2.0.0', $connection);

        foreach (['cms_block', 'cms_block_i18n', 'cms_block_content', 'cms_item_block', 'cms_page_template'] as $table) {
            self::assertFalse($this->tableExists($table), $table.' is gone.');
            self::assertFalse($this->tableExists(str_replace('cms_', 'cms_legacy_', $table)), $table.' held nothing: no legacy table for it.');
        }

        self::assertTrue($this->tableExists('cms_legacy_page_content'), 'The content of the pages is kept whether or not there are blocks.');
    }

    public function testAPageTableOfNothingLeavesNoLegacyCopyAndOneWithContentKeepsIt(): void
    {
        $page = $this->createPage('Page sans contenu');
        $pageId = (int) $page->getId();
        $connection = $this->getPropelConnection();

        $this->backTo150($pageId, $this->locale());
        $connection->exec('DELETE FROM cms_page_revision');
        $connection->exec('UPDATE cms_page_content SET draft_project_data = NULL, draft_html = NULL, draft_css = NULL, published_html = NULL, published_css = NULL');

        (new TheliaCMS())->update('1.5.0', '2.0.0', $connection);

        self::assertFalse($this->tableExists('cms_legacy_page_content'), 'No page held anything written: nothing to keep.');
        self::assertFalse($this->tableExists('cms_legacy_page_revision'), 'No revision: nothing to keep.');

        $this->backTo150($pageId, $this->locale());
        $connection->exec('UPDATE cms_page_content SET draft_project_data = NULL, draft_html = NULL, draft_css = NULL, published_html = NULL, published_css = NULL');

        (new TheliaCMS())->update('1.5.0', '2.0.0', $connection);

        self::assertFalse($this->tableExists('cms_legacy_page_content'), 'Empty pages: nothing to keep, whatever the revisions hold.');
        self::assertTrue($this->tableExists('cms_legacy_page_revision'), 'A revision with markup is kept.');
    }

    public function testAFreshInstallIsLeftAlone(): void
    {
        $page = $this->createPage('Page écrite en 2.0.0', html: '<h2>Après</h2><p>Un texte de 2.0.0.</p>');
        $pageId = (int) $page->getId();

        // The shape is already the one of 2.0.0: nothing is purged.
        (new TheliaCMS())->update('1.5.0', '2.0.0', $this->getPropelConnection());

        $row = $this->rows(\sprintf('SELECT published_html, published_document FROM cms_page_content WHERE page_id = %d', $pageId));

        self::assertStringContainsString('Un texte de 2.0.0.', (string) $row[0]['published_html']);
        self::assertNotNull($row[0]['published_document']);
    }

    private function backTo150(int $pageId, string $locale): void
    {
        $connection = $this->getPropelConnection();

        $this->dropLegacyTables();

        $connection->exec('ALTER TABLE cms_page_content DROP COLUMN draft_document, DROP COLUMN published_document, ADD COLUMN draft_project_data LONGTEXT AFTER locale, ADD COLUMN draft_css LONGTEXT AFTER draft_html, ADD COLUMN published_css LONGTEXT AFTER published_html, ALGORITHM=COPY');

        // The tables of the blocks and of the saved templates as 1.5.0 had
        // them, with a block of a product in them.
        $updates = \dirname(__DIR__, 3).'/Config/update/';
        (new Database($connection))->insertSql(null, [$updates.'0.3.0.sql', $updates.'0.6.0.sql', $updates.'1.4.0.sql']);
        $connection->exec("INSERT INTO cms_block (code, created_at, updated_at) VALUES ('product-1', NOW(), NOW())");
        $connection->exec("INSERT INTO cms_block_content (block_id, locale, draft_html) VALUES (LAST_INSERT_ID(), 'fr_FR', '<p>Bloc</p>')");
        $connection->exec("INSERT INTO cms_item_block (item_type, item_id, block_id, created_at, updated_at) SELECT 'product', 1, id, NOW(), NOW() FROM cms_block WHERE code = 'product-1'");

        $connection->exec('ALTER TABLE cms_page_revision DROP COLUMN document, ADD COLUMN project_data LONGTEXT AFTER locale, ADD COLUMN css LONGTEXT AFTER html, ALGORITHM=COPY');

        $connection->prepare('INSERT INTO cms_page_revision (page_id, locale, project_data, html, css, label, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$pageId, $locale, '{"revision":1}', '<p>Révision de 1.5.0</p>', '.old{}', 'v1']);

        $connection->prepare('UPDATE cms_page_content SET draft_project_data = ?, draft_css = ?, published_css = ? WHERE page_id = ? AND locale = ?')
            ->execute(['{"pages":[{"frames":[]}]}', 'body{color:red}', '.cms-page-content{color:red}', $pageId, $locale]);

        $connection->prepare('INSERT INTO cms_page_template (code, title, payload, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
            ->execute(['test-'.bin2hex(random_bytes(4)), 'Modèle de 1.5.0', '{"format":"thelia-cms","version":1,"pages":[]}']);
    }

    private function dropLegacyTables(): void
    {
        $connection = $this->getPropelConnection();

        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::LEGACY_TABLES as $table) {
            $connection->exec('DROP TABLE IF EXISTS '.$table);
        }
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function tableExists(string $table): bool
    {
        return [] !== $this->rows(\sprintf("SHOW TABLES LIKE '%s'", $table));
    }

    private function columnExists(string $table, string $column): bool
    {
        return [] !== $this->rows(\sprintf("SHOW COLUMNS FROM %s LIKE '%s'", $table, $column));
    }

    private function rowCount(string $from): int
    {
        return (int) $this->rows('SELECT COUNT(*) AS n FROM '.$from)[0]['n'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->getPropelConnection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
