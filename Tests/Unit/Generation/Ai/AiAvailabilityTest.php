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

namespace TheliaCMS\Tests\Unit\Generation\Ai;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\Catalog\AiModelCatalog;
use Thelia\Domain\Ai\Exception\AiKeyDecryptionException;
use Thelia\Domain\Ai\Exception\InactiveAiUsageException;
use Thelia\Domain\Ai\Exception\UnknownAiUsageException;
use Thelia\Domain\Ai\Provider\AiTargetResolver;
use Thelia\Domain\Ai\Repository\AiRoutingRepository;
use Thelia\Domain\Ai\Repository\AiUsageSettingRepository;
use Thelia\Domain\Ai\Service\AiSettings;
use Thelia\Domain\Ai\Vault\AiKeyVault;
use TheliaCMS\Generation\Ai\AiAvailability;
use TheliaCMS\Generation\Ai\AiUnavailability;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\Text\TextGenerator;
use TheliaCMS\Tests\Support\ScriptedAiAvailability;

/**
 * When the AI of the shop can serve a usage of the module: the master switch, the
 * usage turned on, a connection and a model that serve it, a jurisdiction the shop
 * accepts; and that the answer is kept for the request, not asked again by each
 * button of a screen.
 *
 * The connection is scripted through the one method that reads it: the real
 * resolver needs a connection, a model and a key in a database.
 */
final class AiAvailabilityTest extends TestCase
{
    private AiUsageRegistryInterface&MockObject $registry;

    private AiSettings&MockObject $settings;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(AiUsageRegistryInterface::class);
        $this->settings = $this->createMock(AiSettings::class);
        $this->settings->method('isEnabled')->willReturn(true);
        $this->settings->method('isEuropeanUnionOnly')->willReturn(false);
        $this->registry->method('isActive')->willReturn(true);
    }

    public function testAnUsageWithAnActiveConnectionAndAModelIsAvailable(): void
    {
        $availability = $this->availability(AiJurisdiction::OutsideEuropeanUnion);

        self::assertTrue($availability->isAvailable(PageGenerator::USAGE));
        self::assertNull($availability->reason(PageGenerator::USAGE));
        self::assertSame([], $availability->unavailableUsages());
    }

    public function testTheMasterSwitchTurnsEveryUsageOffWithoutAskingTheRest(): void
    {
        $settings = $this->createMock(AiSettings::class);
        $settings->method('isEnabled')->willReturn(false);
        $registry = $this->createMock(AiUsageRegistryInterface::class);
        $registry->expects(self::never())->method('isActive');
        $availability = $this->scripted($registry, $settings, AiJurisdiction::EuropeanUnion);

        foreach (AiAvailability::USAGES as $usage) {
            self::assertSame(AiUnavailability::Disabled, $availability->reason($usage), $usage);
        }

        self::assertSame(0, $availability->resolutions, 'Nothing is resolved while the switch is off.');
    }

    public function testAUsageTurnedOffInItsOwnSettingsIsNotAvailable(): void
    {
        $registry = $this->createMock(AiUsageRegistryInterface::class);
        $registry->method('isActive')->willReturnCallback(static fn (string $usage): bool => PageGenerator::USAGE !== $usage);
        $availability = $this->scripted($registry, $this->settings, AiJurisdiction::EuropeanUnion);

        self::assertSame(AiUnavailability::UsageOff, $availability->reason(PageGenerator::USAGE));
        self::assertTrue($availability->isAvailable(TextGenerator::USAGE), 'The others are not concerned.');
    }

    public function testAUsageWithoutAModelIsNotAvailableAndTheOthersStillAre(): void
    {
        $availability = $this->availability(AiJurisdiction::EuropeanUnion, [ComponentGenerator::USAGE => new InactiveAiUsageException(ComponentGenerator::USAGE)]);

        self::assertFalse($availability->isAvailable(ComponentGenerator::USAGE));
        self::assertSame(AiUnavailability::NoModel, $availability->reason(ComponentGenerator::USAGE));
        self::assertTrue($availability->isAvailable(PageGenerator::USAGE));
        self::assertSame([ComponentGenerator::USAGE => AiUnavailability::NoModel], $availability->unavailableUsages());
    }

    public function testAKeyThatCannotBeReadAndAnUnknownUsageAreNamedAsSuch(): void
    {
        $availability = $this->availability(AiJurisdiction::EuropeanUnion, [
            PageGenerator::USAGE => new AiKeyDecryptionException('anthropic-main'),
            TextGenerator::USAGE => new UnknownAiUsageException(TextGenerator::USAGE),
        ]);

        self::assertSame(AiUnavailability::KeyUnreadable, $availability->reason(PageGenerator::USAGE));
        self::assertSame(AiUnavailability::Unknown, $availability->reason(TextGenerator::USAGE));
    }

    public function testAShopThatAcceptsTheEuropeanUnionOnlyRefusesAConnectionOutsideIt(): void
    {
        $settings = $this->createMock(AiSettings::class);
        $settings->method('isEnabled')->willReturn(true);
        $settings->method('isEuropeanUnionOnly')->willReturn(true);

        self::assertSame(AiUnavailability::Jurisdiction, $this->scripted($this->registry, $settings, AiJurisdiction::OutsideEuropeanUnion)->reason(PageGenerator::USAGE));
        self::assertNull($this->scripted($this->registry, $settings, AiJurisdiction::EuropeanUnion)->reason(PageGenerator::USAGE));
    }

    public function testTheAnswerIsKeptForTheRequestAndForgottenByReset(): void
    {
        $availability = $this->availability(AiJurisdiction::EuropeanUnion);

        for ($button = 0; $button < 5; ++$button) {
            $availability->isAvailable(PageGenerator::USAGE);
        }

        $availability->isAvailable(TextGenerator::USAGE);
        self::assertSame(2, $availability->resolutions, 'One resolution by usage, however many buttons ask.');

        $availability->reset();
        $availability->isAvailable(PageGenerator::USAGE);
        self::assertSame(3, $availability->resolutions, 'A worker that lives on asks again for the next request.');
    }

    public function testTheUsagesOfTheModuleAreTheFourItCallsTheAiUnder(): void
    {
        self::assertSame(
            ['theliacms.page_generation', 'theliacms.page_translation', 'theliacms.component', 'theliacms.text'],
            AiAvailability::USAGES,
        );
    }

    /**
     * @param array<string, \Throwable> $failures by usage, what the resolution throws
     */
    private function availability(AiJurisdiction $jurisdiction, array $failures = []): ScriptedAiAvailability
    {
        return $this->scripted($this->registry, $this->settings, $jurisdiction, $failures);
    }

    /**
     * @param array<string, \Throwable> $failures
     */
    private function scripted(AiUsageRegistryInterface $registry, AiSettings $settings, AiJurisdiction $jurisdiction, array $failures = []): ScriptedAiAvailability
    {
        $resolver = new AiTargetResolver(
            $registry,
            $this->createMock(AiUsageSettingRepository::class),
            $this->createMock(AiRoutingRepository::class),
            $this->createMock(AiModelCatalog::class),
            $this->createMock(AiKeyVault::class),
        );

        return new ScriptedAiAvailability($registry, $resolver, $settings, $jurisdiction, $failures);
    }
}
