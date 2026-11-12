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
use TheliaCMS\Front\BlockStyles;
use TheliaCMS\Generation\Component\ComponentPrompt;

/**
 * The generator is told what each colour of the site is for, not only its
 * name: the accent of a theme is often a brand colour too light to read as
 * text (the orange of Flexy is 3.4:1 on white), and a component that writes
 * its text in it fails the contrast of the page.
 */
final class ReadableColoursRuleOfThePromptTest extends TestCase
{
    public function testEveryColourOfTheStylesheetOfTheSiteHasItsUseSaid(): void
    {
        preg_match_all('/^\s*(--cms-color-[a-z-]+)\s*:/m', (string) file_get_contents((new BlockStyles())->path()), $matches);
        $defined = array_values(array_unique($matches[1]));
        sort($defined);
        $said = array_keys(ComponentPrompt::COLOUR_USES);
        sort($said);

        self::assertSame($defined, $said, 'A colour added to the stylesheet needs its use in the prompt, and the prompt names no colour the site lacks.');
    }

    public function testTheAccentIsNeverTheColourOfAText(): void
    {
        $rule = ComponentPrompt::colourRule(array_keys(ComponentPrompt::COLOUR_USES));

        foreach (['--cms-color-accent', '--cms-color-accent-strong'] as $accent) {
            self::assertStringContainsString('never the colour of a text', ComponentPrompt::COLOUR_USES[$accent], $accent);
        }

        self::assertStringContainsString('`var(--cms-color-on-accent)`', $rule, 'What is written on the accent.');
        self::assertStringContainsString('`var(--cms-color-text)`', $rule);
        self::assertStringContainsString('4.5:1', $rule);
    }

    public function testAColourSetOnAContainerIsSetOnTheParagraphsOfItsRichTextToo(): void
    {
        // The bench measured it: the site gives every paragraph of the page its
        // own colour, so a dark quote whose text is an `html` prop showed it grey
        // on dark (1.5:1) although the quote itself was white.
        $rule = ComponentPrompt::colourRule(array_keys(ComponentPrompt::COLOUR_USES));

        self::assertStringContainsString('every `p` of the page', $rule);
        self::assertStringContainsString('`html` prop', $rule);
    }

    public function testOnlyTheColoursTheSiteDefinesAreNamed(): void
    {
        $rule = ComponentPrompt::colourRule(['--cms-color-text', '--cms-color-accent']);

        self::assertStringContainsString('`var(--cms-color-accent)`', $rule);
        self::assertStringNotContainsString('--cms-color-on-dark', $rule);
    }
}
