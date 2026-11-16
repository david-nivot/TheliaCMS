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

namespace TheliaCMS\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\StatementInterface;
use TheliaCMS\TheliaCMS;

/**
 * `module:refresh` updates every module whose version changed, active or not. On a
 * site that never activated TheliaCMS, none of its tables exist: the update files
 * altered them anyway, and the refresh stopped on the first one (2.19.0,
 * `cms_form_submission`). The update then plays nothing; the first activation
 * creates the schema and every update file.
 */
final class UpdateWithoutSchemaTest extends TestCase
{
    public function testAnUpdateOnASiteWithoutTheTablesOfTheModulePlaysNothing(): void
    {
        $schemaCheck = $this->createMock(StatementInterface::class);
        $schemaCheck->method('fetchColumn')->willReturn('0');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('prepare')->willReturn($schemaCheck);
        $connection->expects(self::never())->method('exec');
        $connection->expects(self::never())->method('query');

        (new TheliaCMS())->update('2.18.0', '2.29.0', $connection);
    }
}
