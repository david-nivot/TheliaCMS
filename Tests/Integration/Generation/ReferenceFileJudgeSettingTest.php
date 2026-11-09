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

use PHPUnit\Framework\Attributes\DataProvider;
use Thelia\Model\AiUsageSetting;
use Thelia\Model\AiUsageSettingQuery;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Guardrail\ReferenceFileJudgeSetting;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

final class ReferenceFileJudgeSettingTest extends CmsIntegrationTestCase
{
    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function choices(): iterable
    {
        yield 'no setting of the usage' => [null, false];
        yield 'no guardrail chosen: the usage declares the judge' => ['', false];
        yield 'an unreadable choice: the core keeps what the usage declares' => ['not json', false];
        yield 'the judge chosen in block mode' => ['{"topic":"block","reference_file":"block"}', false];
        yield 'the judge chosen in annotate mode' => ['{"reference_file":"annotate"}', false];
        yield 'other guardrails chosen, not the judge' => ['{"topic":"block"}', true];
        yield 'the judge with a mode the core does not know' => ['{"reference_file":"off"}', true];
    }

    #[DataProvider('choices')]
    public function testTheJudgeIsLeftOutOnlyWhenTheMerchantChoseGuardrailsWithoutIt(?string $guardrails, bool $leftOut): void
    {
        AiUsageSettingQuery::create()->filterByUsageId(PageGenerator::USAGE)->delete();

        if (null !== $guardrails) {
            (new AiUsageSetting())->setUsageId(PageGenerator::USAGE)->setGuardrails($guardrails)->save();
        }

        self::assertSame($leftOut, $this->getService(ReferenceFileJudgeSetting::class)->leavesTheJudgeOut());
    }

    public function testTheUsageOfComponentsIsReadOnItsOwn(): void
    {
        AiUsageSettingQuery::create()->filterByUsageId(PageGenerator::USAGE)->delete();
        AiUsageSettingQuery::create()->filterByUsageId(ComponentGenerator::USAGE)->delete();
        (new AiUsageSetting())->setUsageId(ComponentGenerator::USAGE)->setGuardrails('{"topic":"block"}')->save();
        $setting = $this->getService(ReferenceFileJudgeSetting::class);

        self::assertTrue($setting->leavesTheJudgeOut(ComponentGenerator::USAGE));
        self::assertFalse($setting->leavesTheJudgeOut(), 'The usage of pages keeps its judge.');
    }
}
