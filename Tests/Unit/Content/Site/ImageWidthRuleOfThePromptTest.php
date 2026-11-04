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
use TheliaCMS\Content\ImageSlot;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Generation\Component\ComponentPrompt;

/**
 * What the model is told about the width of its pictures holds against what
 * the site does: the attributes are the ones it reads, the example is a
 * template it keeps, and the values it quotes are widths.
 */
final class ImageWidthRuleOfThePromptTest extends TestCase
{
    public function testTheRuleNamesTheAttributesTheSiteReads(): void
    {
        self::assertStringContainsString('`'.ImageSlot::ATTRIBUTE.'`', ComponentPrompt::IMAGE_WIDTH_RULE);
        self::assertStringContainsString('`'.ImageSlot::HEIGHT_ATTRIBUTE.'`', ComponentPrompt::IMAGE_WIDTH_RULE);
    }

    public function testTheExampleIsATemplateTheSiteKeeps(): void
    {
        $template = ComponentPrompt::IMAGE_WIDTH_EXAMPLE;

        self::assertSame($template, (new SiteComponentValidator())->validate(PriceTable::component($template))->template, 'The attribute of the example is not taken out by the filter of the page.');
    }

    public function testTheValuesTheRuleQuotesAreValid(): void
    {
        preg_match_all('/`((?:\(min-width|\d+rem)[^`]*)`/', ComponentPrompt::IMAGE_WIDTH_RULE.' '.ComponentPrompt::IMAGE_WIDTH_EXAMPLE, $quoted);
        preg_match('/data-cms-sizes="([^"]+)"/', ComponentPrompt::IMAGE_WIDTH_EXAMPLE, $sizes);

        self::assertTrue(ImageSlot::isValid($sizes[1]), 'The sizes of the example.');
        self::assertContains('(min-width: 64rem) 20vw, 50vw', $quoted[1]);
        self::assertTrue(ImageSlot::isValid('(min-width: 64rem) 20vw, 50vw'));
        self::assertTrue(ImageSlot::isValidHeight('5rem'));
    }
}
