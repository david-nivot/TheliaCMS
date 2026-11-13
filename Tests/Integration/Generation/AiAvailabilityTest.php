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

use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\Exception\UnknownAiUsageException;
use Thelia\Model\AiUsageSetting;
use Thelia\Model\AiUsageSettingQuery;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Generation\Ai\AiAvailability;
use TheliaCMS\Generation\Ai\AiUnavailability;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use Twig\Environment;

/**
 * The service against the real AI layer of the core, on the database of the tests:
 * no connection and no model there, so nothing is available until the test
 * says otherwise, which is what lets the screens be tested with a fake.
 */
final class AiAvailabilityTest extends CmsIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The master switch of the core is on unless said otherwise, but the
        // database of the tests of a core may hold it off (Thelia 3.3.0 does).
        ConfigQuery::write('ai_enabled', '1');
        ConfigQuery::resetCache();
    }

    protected function tearDown(): void
    {
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testEveryUsageTheModuleAsksAboutIsDeclaredToTheCore(): void
    {
        $registry = $this->getService(AiUsageRegistryInterface::class);

        foreach (AiAvailability::USAGES as $usage) {
            try {
                $registry->get($usage);
            } catch (UnknownAiUsageException) {
                self::fail(\sprintf('The usage "%s" is not declared with #[AsAiUsage]: its buttons would be hidden for ever.', $usage));
            }
        }

        self::addToAssertionCount(1);
    }

    public function testWithoutAConnectionAndAModelNoUsageIsAvailable(): void
    {
        $availability = $this->getService(AiAvailability::class);
        $availability->reset();

        foreach (AiAvailability::USAGES as $usage) {
            self::assertSame(AiUnavailability::NoModel, $availability->reason($usage), $usage);
        }
    }

    public function testTheMasterSwitchOfTheCoreSpeaksForEveryUsage(): void
    {
        ConfigQuery::write('ai_enabled', '0');
        ConfigQuery::resetCache();

        $availability = $this->getService(AiAvailability::class);
        $availability->reset();

        foreach (AiAvailability::USAGES as $usage) {
            self::assertSame(AiUnavailability::Disabled, $availability->reason($usage), $usage);
        }
    }

    public function testAUsageTurnedOffInTheAiSettingsIsNamedAsSuch(): void
    {
        AiUsageSettingQuery::create()->filterByUsageId(PageGenerator::USAGE)->delete();
        (new AiUsageSetting())->setUsageId(PageGenerator::USAGE)->setEnabled(false)->save();

        $availability = $this->getService(AiAvailability::class);
        $availability->reset();

        self::assertSame(AiUnavailability::UsageOff, $availability->reason(PageGenerator::USAGE));
        self::assertSame(AiUnavailability::NoModel, $availability->reason(PageGenerator::TRANSLATION_USAGE), 'The other usages are judged on their own.');
    }

    public function testTheTwigFunctionAsksTheServiceAndAnUndeclaredUsageIsNotAvailable(): void
    {
        $twig = $this->getService(Environment::class);
        $render = static fn (string $usage): string => $twig->createTemplate(\sprintf("{{ cms_ai_available('%s') ? 'yes' : 'no' }}", $usage))->render();

        self::assertSame('no', $render('theliacms.text'), 'No model in the database of the tests.');
        self::assertSame('no', $render('theliacms.nothing'), 'A usage the core does not know is not offered.');
    }
}
