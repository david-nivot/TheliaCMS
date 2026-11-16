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
use TheliaCMS\Content\HeadingSection;
use TheliaCMS\Generation\Component\ComponentPrompt;

/**
 * What the bench saw (round 10): told it may draw a heading of the site, the
 * generator wrote the heading of every component itself (3 trials out of 3).
 * The prompt says the heading that opens a component is the heading of the
 * site, drawn only when it has words.
 */
final class SiteHeadingRuleOfThePromptTest extends TestCase
{
    public function testTheHeadingThatOpensAComponentIsTheHeadingOfTheSite(): void
    {
        $rule = ComponentPrompt::SITE_HEADING_RULE;

        self::assertStringContainsString("cms_component('".HeadingSection::NAME."', {text: props.title, level: props.title_level, style: props.title_style})", $rule, 'The editor sets the level and the look of the heading (ADR 18).');
        self::assertStringContainsString('`'.HeadingSection::LEVEL_STYLE.'` by default', $rule);
        self::assertStringContainsString('{% if props.title is not empty %}', $rule, 'A heading without words draws nothing and is not called.');
        self::assertStringContainsString('`h3`', $rule, 'The heading of a card or a row stays the component\'s own.');
    }
}
