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

namespace TheliaCMS\Tests\Integration\Generation;

use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The tool the container builds reads through the guarded client of the core,
 * not through a client of the application that would reach the inside.
 */
final class ReadUrlToolWiringTest extends CmsIntegrationTestCase
{
    public function testTheToolOfTheContainerRefusesTheInside(): void
    {
        $tool = $this->getService(ReadUrlTool::class);

        foreach (['http://127.0.0.1/', 'http://10.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://localhost/'] as $url) {
            self::assertSame('blocked_address', $tool->read($url)->errorCode, $url);
        }
    }

    public function testTheGeneratorOfTheContainerHoldsTheImageImporter(): void
    {
        $generator = $this->getService(\TheliaCMS\Generation\PageGenerator::class);
        $importer = (new \ReflectionProperty($generator, 'imageImporter'))->getValue($generator);

        self::assertInstanceOf(\TheliaCMS\Generation\Tool\ImportImageTool::class, $importer, 'Without it the tool would never be offered.');
    }
}
