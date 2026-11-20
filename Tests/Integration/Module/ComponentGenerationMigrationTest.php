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
 * The update to 2.41.0 gives a component of the site the call its version
 * came from: a column, its index and a key that empties it when the call goes.
 */
final class ComponentGenerationMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    protected function tearDown(): void
    {
        // A test that stopped halfway leaves the table as 2.40.0 had it.
        (new TheliaCMS())->update('2.40.0', '2.41.0', $this->getPropelConnection());

        parent::tearDown();
    }

    /**
     * Sabotage: delete the `information_schema` checks of
     * `Config/update/2.41.0.sql`: the second play fails on the column. Write
     * `ON DELETE CASCADE`: the rule read is not SET NULL.
     */
    public function testTheUpdateAddsTheCallOfTheVersionAndCanBePlayedTwice(): void
    {
        $this->backTo240();
        self::assertSame([], $this->rows("SHOW COLUMNS FROM cms_component LIKE 'generation_id'"), 'The site is as 2.40.0 left it.');

        $module = new TheliaCMS();
        $module->update('2.40.0', '2.41.0', $this->getPropelConnection());
        $module->update('2.40.0', '2.41.0', $this->getPropelConnection());

        $column = $this->rows("SHOW COLUMNS FROM cms_component LIKE 'generation_id'");
        self::assertCount(1, $column);
        self::assertStringStartsWith('int', strtolower((string) $column[0]['Type']));
        self::assertSame('YES', $column[0]['Null']);
        self::assertNotSame([], $this->rows("SHOW INDEX FROM cms_component WHERE Key_name = 'idx_cms_component_generation'"));

        $key = $this->rows("SELECT DELETE_RULE, REFERENCED_TABLE_NAME FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'fk_cms_component_generation'");
        self::assertCount(1, $key, 'One key, not one per play.');
        self::assertSame('cms_page_generation', $key[0]['REFERENCED_TABLE_NAME']);
        self::assertSame('SET NULL', $key[0]['DELETE_RULE'], 'A call deleted empties the mark, never the component.');
    }

    private function backTo240(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('ALTER TABLE cms_component DROP FOREIGN KEY fk_cms_component_generation');
        $connection->exec('ALTER TABLE cms_component DROP INDEX idx_cms_component_generation');
        $connection->exec('ALTER TABLE cms_component DROP COLUMN generation_id, ALGORITHM=COPY');
    }

    /**
     * Read again at each call: the table changes between two.
     *
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function rows(string $sql): array
    {
        return $this->getPropelConnection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
