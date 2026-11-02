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
 * A site on 1.2.0 moving to tags: the table and the column appear, with the
 * constraint that detaches the pages of a deleted tag, and the update can be
 * played twice.
 *
 * Runs outside a transaction: an ALTER TABLE commits on its own, so a rollback
 * would undo nothing and the test would prove nothing. The schema is put back
 * the way 1.2.0 left it, the update is played, then checked. The table the
 * update makes is the one of 1.3.0: the later updates of `cms_tag` are played
 * after it, so the tests that follow find the schema of this version.
 *
 * A column is dropped with ALGORITHM=COPY: MariaDB drops it instantly
 * otherwise and keeps it in the rows, so about thirty runs leave the table
 * refusing the column back ("Row size too large").
 */
final class PageTagMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    /** @var list<array{id: int, name: string, color: string|null, text_color: string|null}> */
    private array $tagsBefore = [];

    /** @var array<int, int> */
    private array $pageTagsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tagsBefore = $this->rows('SELECT id, name, color, text_color FROM cms_tag');
        $this->pageTagsBefore = array_column($this->rows('SELECT id, tag_id FROM cms_page WHERE tag_id IS NOT NULL'), 'tag_id', 'id');
    }

    protected function tearDown(): void
    {
        $connection = $this->getPropelConnection();

        $module = new TheliaCMS();
        $module->update('1.2.0', '1.3.0', $connection);
        $module->update('2.4.0', '2.5.0', $connection);
        $module->update('2.6.3', '2.7.0', $connection);

        foreach ($this->tagsBefore as $tag) {
            $connection->prepare('INSERT IGNORE INTO cms_tag (id, name, color, text_color, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')->execute([$tag['id'], $tag['name'], $tag['color'], $tag['text_color']]);
        }

        foreach ($this->pageTagsBefore as $pageId => $tagId) {
            $connection->prepare('UPDATE cms_page SET tag_id = ? WHERE id = ?')->execute([$tagId, $pageId]);
        }

        parent::tearDown();
    }

    public function testTheTableAndTheColumnAppearAndTheUpdateCanBePlayedTwice(): void
    {
        $page = (int) $this->createPage('Page d’avant les étiquettes')->getId();
        $this->backTo120();

        $module = new TheliaCMS();
        $module->update('1.2.0', '1.3.0', $this->getPropelConnection());
        $module->update('1.2.0', '1.3.0', $this->getPropelConnection());

        self::assertSame([], $this->rows('SELECT id FROM cms_tag'));
        self::assertSame('int', strtolower((string) $this->rows("SELECT DATA_TYPE AS data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cms_page' AND column_name = 'tag_id'")[0]['data_type']));
        self::assertNull($this->rows(\sprintf('SELECT tag_id FROM cms_page WHERE id = %d', $page))[0]['tag_id']);

        $constraints = $this->rows("SELECT DELETE_RULE AS delete_rule FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'cms_page' AND constraint_name = 'fk_cms_page_tag'");
        self::assertSame('SET NULL', $constraints[0]['delete_rule'] ?? null, 'Deleting a tag detaches its pages.');

        $connection = $this->getPropelConnection();
        $connection->exec("INSERT INTO cms_tag (name, created_at, updated_at) VALUES ('Étiquette de migration', NOW(), NOW())");
        $tagId = (int) $connection->lastInsertId();
        $connection->exec(\sprintf('UPDATE cms_page SET tag_id = %d WHERE id = %d', $tagId, $page));
        $connection->exec(\sprintf('DELETE FROM cms_tag WHERE id = %d', $tagId));

        self::assertNull($this->rows(\sprintf('SELECT tag_id FROM cms_page WHERE id = %d', $page))[0]['tag_id']);
    }

    private function backTo120(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('ALTER TABLE cms_page DROP FOREIGN KEY fk_cms_page_tag');
        $connection->exec('ALTER TABLE cms_page DROP COLUMN tag_id, ALGORITHM=COPY');
        $connection->exec('DROP TABLE cms_tag');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->getPropelConnection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
