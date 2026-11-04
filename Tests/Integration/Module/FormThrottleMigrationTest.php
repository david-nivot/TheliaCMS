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
 * A site on 2.9 moving to the counters of the module: the table appears, the
 * rows the module kept in the core table go and the others stay, and the
 * update can be played twice.
 *
 * Runs outside a transaction: the table is dropped and created again.
 */
final class FormThrottleMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    protected function tearDown(): void
    {
        $connection = $this->getPropelConnection();
        (new TheliaCMS())->update('2.9.0', '2.10.0', $connection);
        $connection->exec("DELETE FROM form_firewall WHERE form_name IN ('cms_form_contact-migration', 'thelia_front_contact_migration')");

        parent::tearDown();
    }

    public function testTheTableAppearsTheOldCountsOfTheModuleGoAndTheUpdateCanBePlayedTwice(): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec('DROP TABLE IF EXISTS cms_form_throttle');
        $connection->exec("INSERT INTO form_firewall (form_name, ip_address, attempts, created_at, updated_at) VALUES ('cms_form_contact-migration', 'abc', 3, NOW(), NOW()), ('thelia_front_contact_migration', 'abc', 3, NOW(), NOW())");

        $module = new TheliaCMS();
        $module->update('2.9.0', '2.10.0', $connection);
        $module->update('2.9.0', '2.10.0', $connection);

        $columns = $connection->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cms_form_throttle' ORDER BY ordinal_position")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['scope', 'subject', 'hits', 'expires_at'], array_map(strtolower(...), $columns));

        $left = $connection->query("SELECT form_name FROM form_firewall WHERE form_name IN ('cms_form_contact-migration', 'thelia_front_contact_migration')")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['thelia_front_contact_migration'], $left, 'Only the rows of the module go.');
    }
}
