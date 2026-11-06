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

namespace TheliaCMS\Tests\Unit\Zone;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Zone\HeaderZoneThemeHook;

final class HeaderZoneThemeHookTest extends TestCase
{
    public function testItAnswersTheHeaderHookOfTheThemeAndNothingElse(): void
    {
        $hook = (new \ReflectionClass(HeaderZoneThemeHook::class))->newInstanceWithoutConstructor();

        self::assertTrue($hook->supports('layout.header.bottom'));
        self::assertFalse($hook->supports('layout.footer.top'));
        self::assertFalse($hook->supports('layout.header.top'));
        // A hook it does not answer never reaches the database.
        self::assertSame('', $hook->render('layout.footer.top', []));
    }
}
