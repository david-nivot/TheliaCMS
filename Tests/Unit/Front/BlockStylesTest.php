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

namespace TheliaCMS\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Front\BlockStyles;

/**
 * The stylesheet of the block catalogue, and the promise it makes to a theme.
 *
 * A theme is allowed to know nothing about the CMS: the module ships the socle
 * of its own blocks, and a page has to read as a page without the theme adding
 * a line. Two things have to hold for that, and neither shows up in a rendered
 * page until it is too late: the file has to be there, and every colour in it
 * has to go through a token that has somewhere to fall back to.
 */
final class BlockStylesTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents((new BlockStyles())->path());
    }

    public function testTheStylesheetIsShippedWithTheModule(): void
    {
        self::assertFileExists((new BlockStyles())->path(), 'A theme with no CMS styles of its own renders unstyled markup without it.');
    }

    public function testTheAddressOfTheStylesheetNamesItsContent(): void
    {
        $version = (new BlockStyles())->version();

        // Cached for a year under that name, so the name has to change with the
        // file and never otherwise.
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $version);
        self::assertSame($version, (new BlockStyles())->version());
    }

    public function testEveryColourGoesThroughATokenOfTheModule(): void
    {
        // A `var(--color-x)` with no fallback is the failure this guards: on a
        // theme that names its palette otherwise, the declaration is dropped and
        // the block falls back to the colours of the browser.
        preg_match_all('/var\(--(?!cms-)([a-z0-9-]+)\)/', $this->css(), $matches);

        self::assertSame(
            [],
            array_values(array_unique($matches[1])),
            'A colour of the theme is read without a fallback, so it disappears on any other theme.',
        );
    }

    public function testTheAccentCarriesItsOwnTextColour(): void
    {
        $css = $this->css();

        // A background and the text on it come as a pair: borrowing the
        // background from the theme while keeping our own text colour is how a
        // button ends up dark on dark.
        foreach (['--cms-color-accent:', '--cms-color-accent-strong:', '--cms-color-on-accent:', '--cms-color-accent-surface:'] as $token) {
            self::assertStringContainsString($token, $css, \sprintf('%s has no default, so a theme that states none gets nothing.', $token));
        }

        preg_match_all('/\{[^{}]*background:\s*var\(--cms-color-accent\)[^{}]*\}/', $css, $rules);

        self::assertNotSame([], $rules[0], 'The accent is not used as a background any more; this test no longer measures anything.');

        foreach ($rules[0] as $rule) {
            self::assertStringContainsString(
                'var(--cms-color-on-accent)',
                $rule,
                'Text sits on the accent without using the colour that goes with it.',
            );
        }
    }

    public function testTheFreeTextOfASectionGetsHeadingsParagraphsAndListsOfItsOwn(): void
    {
        $css = $this->css();

        // The reset of a theme leaves what the author typed the size of body
        // text, glued together, its lists without a bullet: a heading of a
        // richtext section read as a line of text on Flexy.
        self::assertMatchesRegularExpression('/\.cms-richtext h2 \{[^}]*font-size:/', $css, 'A heading of free text is the size of body text.');
        self::assertMatchesRegularExpression('/\.cms-richtext > \* \+ \* \{[^}]*margin-top:/', $css, 'The paragraphs of free text touch.');
        self::assertMatchesRegularExpression('/\.cms-richtext ul \{[^}]*list-style: disc/', $css, 'A list of free text has no bullet.');
    }

    public function testTheSocleSitsInACascadeLayerSoAThemeKeepsTheUpperHand(): void
    {
        // Without the layer, the socle would win or lose depending on which
        // stylesheet the browser happens to read last.
        self::assertStringContainsString('@layer cms {', $this->css());
    }
}
