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

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The update to 2.42.0 hands the reasoning effort to the core: the two
 * settings of the module become the effort of each usage it calls, and go.
 * The update to 2.42.1 does it again for a site the update to 2.42.0 failed on
 * after its version was recorded, and leaves alone a site it went through.
 */
final class EffortFromCoreMigrationTest extends CmsIntegrationTestCase
{
    private const array USAGES = ['theliacms.page_generation', 'theliacms.page_translation', 'theliacms.component', 'theliacms.text'];
    private const array KEYS = ['generator_effort', 'generator_text_effort'];

    protected bool $useTransaction = false;

    /** @var list<array<string, mixed>> */
    private array $rowsBefore = [];

    /** @var array<string, string|null> */
    private array $settingsBefore = [];

    /** @var array<string, string|null>|null */
    private ?array $valueColumn = null;

    protected function setUp(): void
    {
        parent::setUp();

        ModuleConfigQuery::resetConfigCache();
        $this->rowsBefore = $this->rows('SELECT * FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES));

        foreach (self::KEYS as $key) {
            $this->settingsBefore[$key] = TheliaCMS::getConfigValue($key);
        }

        $this->connection()->exec('DELETE FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES));
        $this->forgetSettings();
    }

    protected function tearDown(): void
    {
        $this->putTheValueColumnBack();

        $connection = $this->connection();
        $connection->exec('DELETE FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES));

        foreach ($this->rowsBefore as $row) {
            $columns = array_keys($row);
            $statement = $connection->prepare(\sprintf(
                'INSERT INTO ai_usage_setting (%s) VALUES (%s)',
                implode(',', array_map(static fn (string $column): string => '`'.$column.'`', $columns)),
                implode(',', array_fill(0, \count($columns), '?')),
            ));
            $statement->execute(array_values($row));
        }

        $this->forgetSettings();

        foreach ($this->settingsBefore as $key => $value) {
            if (null !== $value) {
                TheliaCMS::setConfigValue($key, $value);
            }
        }

        ModuleConfigQuery::resetConfigCache();

        parent::tearDown();
    }

    /**
     * Sabotage: write `@cms_effort_text` in place of `@cms_effort_page` in the
     * insert, or leave the three page usages on `high`.
     */
    public function testTheValuesOfTheModuleBecomeTheEffortOfEachUsage(): void
    {
        TheliaCMS::setConfigValue('generator_effort', 'max');
        TheliaCMS::setConfigValue('generator_text_effort', 'medium');

        $this->migrate();

        self::assertSame(
            ['theliacms.component' => 'max', 'theliacms.page_generation' => 'max', 'theliacms.page_translation' => 'max', 'theliacms.text' => 'medium'],
            $this->efforts(),
        );
        self::assertSame([], $this->settingKeys(), 'The two settings go.');
    }

    /**
     * A row that holds nothing but the effort reads as no row at all (the
     * defaults of the table are the defaults the core takes for a usage
     * without one): that is checked by reading every column back.
     *
     * Sabotage: add `enabled` set to 0 to the insert.
     */
    public function testAUsageWithoutARowGetsARowWithTheEffortAndNothingElse(): void
    {
        TheliaCMS::setConfigValue('generator_effort', 'xhigh');

        $this->migrate();

        $row = $this->rows("SELECT * FROM ai_usage_setting WHERE usage_id = 'theliacms.component'")[0];
        self::assertSame('xhigh', $row['effort']);
        self::assertSame('1', (string) $row['enabled']);
        self::assertSame('1', (string) $row['transparency_notice']);
        self::assertSame('0', (string) $row['provenance_mark']);
        self::assertSame('0', (string) $row['store_prompt']);
        self::assertSame('USD', $row['currency']);
        self::assertNotNull($row['created_at']);

        foreach (['tier', 'monthly_budget', 'requests_per_minute', 'requests_per_day', 'max_output_tokens', 'max_input_characters', 'pinned_connection_id', 'pinned_model_id', 'guardrails', 'prompt_retention_days'] as $column) {
            self::assertNull($row[$column], $column.' is left to the core.');
        }
    }

    /**
     * Sabotage: delete the `COALESCE(…, 'high')` and `'low'` of the file.
     */
    public function testTheFormerDefaultsAreWrittenWhenTheModuleHeldNothingOrNonsense(): void
    {
        $this->migrate();

        self::assertSame(
            ['theliacms.component' => 'high', 'theliacms.page_generation' => 'high', 'theliacms.page_translation' => 'high', 'theliacms.text' => 'low'],
            $this->efforts(),
            'A fresh install has no setting: the defaults of the module are the effort.',
        );

        $this->connection()->exec('DELETE FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES));
        TheliaCMS::setConfigValue('generator_effort', 'extreme');
        TheliaCMS::setConfigValue('generator_text_effort', 'HIGH');

        $this->migrate();

        self::assertSame(
            ['theliacms.component' => 'high', 'theliacms.page_generation' => 'high', 'theliacms.page_translation' => 'high', 'theliacms.text' => 'low'],
            $this->efforts(),
            'What the module never accepted as a level is no level.',
        );
    }

    /**
     * Sabotage: drop the `AND (effort IS NULL OR effort = '')` of the update,
     * or the NOT EXISTS of the insert.
     */
    public function testAnEffortTheMerchantAlreadySetInTheCoreIsKept(): void
    {
        $connection = $this->connection();
        $connection->exec("INSERT INTO ai_usage_setting (usage_id, effort, tier, monthly_budget, created_at, updated_at) VALUES ('theliacms.component', 'low', 'fast', '5.000000', NOW(), NOW())");
        $connection->exec("INSERT INTO ai_usage_setting (usage_id, effort, monthly_budget, created_at, updated_at) VALUES ('theliacms.page_translation', 'xhigh', '7.000000', NOW(), NOW())");
        $connection->exec("INSERT INTO ai_usage_setting (usage_id, effort, monthly_budget, created_at, updated_at) VALUES ('theliacms.page_generation', NULL, '9.000000', NOW(), NOW())");
        $connection->exec("INSERT INTO ai_usage_setting (usage_id, effort, created_at, updated_at) VALUES ('theliacms.text', '', NOW(), NOW())");
        TheliaCMS::setConfigValue('generator_effort', 'max');
        TheliaCMS::setConfigValue('generator_text_effort', 'medium');

        $this->migrate();

        self::assertSame(
            ['theliacms.component' => 'low', 'theliacms.page_generation' => 'max', 'theliacms.page_translation' => 'xhigh', 'theliacms.text' => 'medium'],
            $this->efforts(),
            'Set in the core: kept. Empty (follows the range): filled.',
        );
        $kept = $this->rows("SELECT tier, monthly_budget FROM ai_usage_setting WHERE usage_id = 'theliacms.component'")[0];
        self::assertSame(['fast', '5.000000'], [$kept['tier'], $kept['monthly_budget']], 'The rest of a row is never touched.');
        self::assertSame('9.000000', $this->rows("SELECT monthly_budget FROM ai_usage_setting WHERE usage_id = 'theliacms.page_generation'")[0]['monthly_budget']);
    }

    /**
     * Sabotage: delete the NOT EXISTS of the insert: the second play fails on
     * the unique key of the usage.
     */
    public function testPlayingTheUpdateAgainChangesNothing(): void
    {
        TheliaCMS::setConfigValue('generator_effort', 'max');

        $this->migrate();
        $first = $this->rows('SELECT * FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES).' ORDER BY usage_id');
        $this->migrate();

        self::assertSame($first, $this->rows('SELECT * FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES).' ORDER BY usage_id'));
        self::assertSame([], $this->settingKeys());
    }

    /**
     * A base that came from Thelia 2 keeps `module_config_i18n.value` in
     * utf8mb3: `COLLATE utf8mb4_bin` on that column is refused (error 1253)
     * and the whole update failed.
     *
     * Sabotage: put `COLLATE utf8mb4_bin` back on the bare column.
     */
    public function testTheValuesOfTheModuleAreReadFromAColumnInUtf8mb3(): void
    {
        $this->valueColumnIn('utf8mb3', 'utf8mb3_general_ci');
        TheliaCMS::setConfigValue('generator_effort', 'max');
        TheliaCMS::setConfigValue('generator_text_effort', 'medium');

        $this->migrate();

        self::assertSame(
            ['theliacms.component' => 'max', 'theliacms.page_generation' => 'max', 'theliacms.page_translation' => 'max', 'theliacms.text' => 'medium'],
            $this->efforts(),
        );
        self::assertSame([], $this->settingKeys());
    }

    /**
     * A site coming from before 2.41.0 had its version recorded although the
     * update to 2.42.0 failed: the ALTER of 2.41.0 commits on its own, the
     * version with it. Its settings are still there and no usage has an
     * effort: 2.42.1 carries them over.
     *
     * Sabotage: empty 2.42.1.sql.
     */
    public function testASiteLeftAt2420BeforeTheCarryOverGetsItFrom2421(): void
    {
        $this->valueColumnIn('utf8mb3', 'utf8mb3_general_ci');
        TheliaCMS::setConfigValue('generator_effort', 'max');
        TheliaCMS::setConfigValue('generator_text_effort', 'medium');

        $this->migrate('2.42.0', '2.42.1');

        self::assertSame(
            ['theliacms.component' => 'max', 'theliacms.page_generation' => 'max', 'theliacms.page_translation' => 'max', 'theliacms.text' => 'medium'],
            $this->efforts(),
        );
        self::assertSame([], $this->settingKeys());
    }

    /**
     * Sabotage: make the replay of 2.42.1 depend on a setting of the module
     * left over only.
     */
    public function testASiteLeftAt2420WithNoSettingGetsTheFormerDefaultsFrom2421(): void
    {
        $this->migrate('2.42.0', '2.42.1');

        self::assertSame(
            ['theliacms.component' => 'high', 'theliacms.page_generation' => 'high', 'theliacms.page_translation' => 'high', 'theliacms.text' => 'low'],
            $this->efforts(),
        );
    }

    /**
     * Once the carry-over went through, what the merchant changes in the core
     * afterwards is theirs: an effort emptied, a row removed.
     *
     * Sabotage: drop the condition `@cms_effort_pending` of 2.42.1.sql.
     */
    public function testASiteTheCarryOverWentThroughIsLeftAloneBy2421(): void
    {
        TheliaCMS::setConfigValue('generator_effort', 'max');
        $this->migrate();
        $connection = $this->connection();
        $connection->exec("UPDATE ai_usage_setting SET effort = 'low' WHERE usage_id = 'theliacms.component'");
        $connection->exec("UPDATE ai_usage_setting SET effort = '' WHERE usage_id = 'theliacms.text'");
        $connection->exec("DELETE FROM ai_usage_setting WHERE usage_id = 'theliacms.page_translation'");
        $before = $this->rows('SELECT * FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES).' ORDER BY usage_id');

        $this->migrate('2.42.0', '2.42.1');

        self::assertSame($before, $this->rows('SELECT * FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES).' ORDER BY usage_id'));
    }

    private function migrate(string $from = '2.41.0', string $to = '2.42.0'): void
    {
        (new TheliaCMS())->update($from, $to, $this->getPropelConnection());
        ModuleConfigQuery::resetConfigCache();
    }

    /**
     * Puts the column of the values of the settings in another character set,
     * as a base that came from Thelia 2 has it. An ALTER commits on its own:
     * the test runs outside a transaction, and tearDown() puts it back.
     */
    private function valueColumnIn(string $characterSet, string $collation): void
    {
        $this->valueColumn = $this->rows("SELECT COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'module_config_i18n' AND column_name = 'value'")[0];
        $this->connection()->exec(\sprintf(
            'ALTER TABLE module_config_i18n MODIFY `value` %s CHARACTER SET %s COLLATE %s %s',
            $this->valueColumn['COLUMN_TYPE'],
            $characterSet,
            $collation,
            'YES' === $this->valueColumn['IS_NULLABLE'] ? 'NULL' : 'NOT NULL',
        ));
    }

    private function putTheValueColumnBack(): void
    {
        if (null === $this->valueColumn) {
            return;
        }

        $column = $this->valueColumn;
        $this->valueColumn = null;
        $this->connection()->exec(\sprintf(
            'ALTER TABLE module_config_i18n MODIFY `value` %s CHARACTER SET %s COLLATE %s %s',
            $column['COLUMN_TYPE'],
            $column['CHARACTER_SET_NAME'],
            $column['COLLATION_NAME'],
            'YES' === $column['IS_NULLABLE'] ? 'NULL' : 'NOT NULL',
        ));
    }

    private function forgetSettings(): void
    {
        $this->connection()->exec("DELETE FROM module_config WHERE name IN ('generator_effort', 'generator_text_effort') AND module_id IN (SELECT id FROM module WHERE code = 'TheliaCMS')");
        ModuleConfigQuery::resetConfigCache();
    }

    /**
     * @return array<string, string|null>
     */
    private function efforts(): array
    {
        $efforts = [];

        foreach ($this->rows('SELECT usage_id, effort FROM ai_usage_setting WHERE usage_id IN '.self::inList(self::USAGES).' ORDER BY usage_id') as $row) {
            $efforts[(string) $row['usage_id']] = $row['effort'];
        }

        return $efforts;
    }

    /**
     * @return list<string>
     */
    private function settingKeys(): array
    {
        return array_column($this->rows("SELECT c.name FROM module_config c INNER JOIN module m ON m.id = c.module_id WHERE m.code = 'TheliaCMS' AND c.name IN ('generator_effort', 'generator_text_effort')"), 'name');
    }

    private function connection(): ConnectionInterface
    {
        return $this->getPropelConnection();
    }

    /**
     * @param list<string> $values
     */
    private static function inList(array $values): string
    {
        return "('".implode("','", $values)."')";
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function rows(string $sql): array
    {
        return $this->getPropelConnection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
