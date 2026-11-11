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

namespace TheliaCMS\Tests\Unit\Content\Site;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Generation\Component\ComponentPrompt;

/**
 * What the bench saw: a comparison table that made the page scroll sideways on
 * a phone (3 trials out of 3), and a scrolling track no keyboard could reach.
 * The prompt says how a wide element fits a narrow screen, and its example is
 * a template the site keeps whole.
 */
final class NarrowScreenRuleOfThePromptTest extends TestCase
{
    public function testTheRuleSaysThePageNeverScrollsSidewaysAndHowAWideElementFits(): void
    {
        $rule = ComponentPrompt::NARROW_SCREEN_RULE;

        self::assertStringContainsString('375', $rule);
        self::assertStringContainsString('`overflow-x: auto`', $rule);
        self::assertStringContainsString('`tabindex="0"`', $rule, 'A keyboard reaches what scrolls.');
        self::assertStringContainsString('`role="region"`', $rule);
        self::assertStringContainsString('`aria-label`', $rule);
        // The bench measured it: a text hidden for the screen readers inside the
        // table (`position: absolute`) escapes a wrapper that is not positioned,
        // and makes the page wider than the phone.
        self::assertStringContainsString('`position: relative`', $rule);
    }

    public function testTheExampleIsATemplateTheSiteKeepsWhole(): void
    {
        $kept = (new SiteComponentValidator())->validate(PriceTable::component(ComponentPrompt::NARROW_SCREEN_EXAMPLE))->template;

        self::assertSame(ComponentPrompt::NARROW_SCREEN_EXAMPLE, $kept, 'Nothing of the example is taken out by the filter of the page.');
        self::assertStringContainsString('tabindex="0"', $kept);
        self::assertStringContainsString('role="region"', $kept);
        self::assertStringContainsString('aria-label="{{ props.title }}"', $kept, 'The name comes from a prop, like every text.');
    }
}
