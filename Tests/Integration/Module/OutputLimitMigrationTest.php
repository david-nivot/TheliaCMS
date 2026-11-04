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

use PHPUnit\Framework\Attributes\DataProvider;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The settings screen writes the output limit of the generator on every save:
 * a site that saved it once holds the former default, 16000, and would never
 * see the new one. Updating to 2.10.0 raises that value, and that value only.
 *
 * Runs outside a transaction: the update file creates a table, which commits
 * on its own. The setting is put back as it was.
 */
final class OutputLimitMigrationTest extends CmsIntegrationTestCase
{
    protected bool $useTransaction = false;

    private ?string $previous = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previous = $this->stored();
    }

    protected function tearDown(): void
    {
        TheliaCMS::setConfigValue(GeneratorSettings::MAX_TOKENS, $this->previous ?? (string) GeneratorSettings::DEFAULT_MAX_TOKENS);
        ModuleConfigQuery::resetConfigCache();

        parent::tearDown();
    }

    #[DataProvider('storedLimits')]
    public function testTheUpdateRaisesTheFormerDefaultAndKeepsAnyOtherValue(string $stored, string $expected): void
    {
        TheliaCMS::setConfigValue(GeneratorSettings::MAX_TOKENS, $stored);

        (new TheliaCMS())->update('2.9.0', '2.10.0', $this->getPropelConnection());
        ModuleConfigQuery::resetConfigCache();

        self::assertSame($expected, $this->stored());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function storedLimits(): iterable
    {
        yield 'the former default' => ['16000', '48000'];
        yield 'a limit the site chose' => ['20000', '20000'];
        yield 'a limit already above' => ['64000', '64000'];
    }

    public function testTheDefaultLeavesRoomForThinkingAndALongPage(): void
    {
        self::assertSame(48000, GeneratorSettings::DEFAULT_MAX_TOKENS);
    }

    private function stored(): ?string
    {
        $value = TheliaCMS::getConfigValue(GeneratorSettings::MAX_TOKENS);

        return null === $value ? null : (string) $value;
    }
}
