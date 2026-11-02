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

namespace TheliaCMS\Tests\Unit\Page;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Page\PageTagColor;

final class PageTagColorTest extends TestCase
{
    public function testACodeIsStoredInLowercaseOnSixDigits(): void
    {
        self::assertSame('#dc4a7b', PageTagColor::of('#DC4A7B')->background);
        self::assertSame('#ffaa00', PageTagColor::of(' #fa0 ')->background);
        self::assertNull(PageTagColor::of('#007040')->text);
        self::assertSame('#d8e480', PageTagColor::of('#007040', '#D8E480')->text);
    }

    public function testAnythingButTheCodeOfAColorIsRefused(): void
    {
        foreach (['pink', '#ff00f', 'ff00ff', '#gg0000', '#ff00ff;color:red', ''] as $code) {
            self::assertNull(PageTagColor::normalize($code), $code);
        }

        $this->expectException(\InvalidArgumentException::class);
        PageTagColor::of('#007040', 'url(x)');
    }

    public function testTheTextIsTheOneChosenOrTheBlackOrWhiteThatContrastsMore(): void
    {
        self::assertSame('#ffffff', PageTagColor::of('#d40e21')->textColor(), 'White on raspberry: 5.4 against 3.9.');
        self::assertSame('#000000', PageTagColor::of('#dc4a7b')->textColor(), 'Black on rose, 5.3 against 4.0: a white text is chosen.');
        self::assertSame('#ffffff', PageTagColor::of('#007040')->textColor(), 'White on basil.');
        self::assertSame('#000000', PageTagColor::of('#f9b000')->textColor(), 'Black on honey.');
        self::assertSame('#000000', PageTagColor::of('#f5efe6')->textColor(), 'Black on beige.');
        self::assertSame('#9c0337', PageTagColor::of('#f9b000', '#9c0337')->textColor());
    }

    public function testANameOfTheFormerPaletteReadsAsTheBadgeItGave(): void
    {
        foreach (PageTagColor::FORMER_PALETTE as $name => [$background, $text]) {
            $color = PageTagColor::fromStored($name);

            self::assertSame($background, $color?->background, $name);
            self::assertSame($text, $color?->textColor(), $name);
        }

        self::assertNull(PageTagColor::fromStored(null));
        self::assertNull(PageTagColor::fromStored('pink'));
        self::assertNull(PageTagColor::fromStored('Yellow'), 'The stored name is the lowercase one.');
        self::assertSame('#d8e480', PageTagColor::fromStored('#007040', '#d8e480')?->textColor());
        self::assertNull(PageTagColor::fromStored('#007040', 'anise')?->text, 'A text color written by hand is left to the contrast.');
    }

    public function testTheBadgeOfNoColorIsTheGreyOneAndTheOthersAreDrawnInline(): void
    {
        self::assertSame(PageTagColor::NO_COLOR_BADGE, PageTagColor::badgeClassOf(null));
        self::assertSame('', PageTagColor::badgeStyleOf(null));
        self::assertSame('', PageTagColor::badgeClassOf(PageTagColor::of('#dc4a7b')));
        self::assertSame('background-color: #dc4a7b; color: #ffffff;', PageTagColor::badgeStyleOf(PageTagColor::of('#dc4a7b', '#fff')));
    }

    public function testTwoColorsAreEqualWhenTheirCodesAre(): void
    {
        self::assertTrue(PageTagColor::of('#DC4A7B')->equals(PageTagColor::of('#dc4a7b')));
        self::assertFalse(PageTagColor::of('#dc4a7b')->equals(PageTagColor::of('#dc4a7b', '#ffffff')), 'A chosen white is not the white of the contrast.');
        self::assertFalse(PageTagColor::of('#dc4a7b')->equals(null));
    }

    public function testEveryLabelOfTheFormIsTranslated(): void
    {
        $labels = ['Color', 'Give the tag a color', 'Background', 'Text in black or white, whichever reads best on the background', 'Text', 'Used when the box above is unticked.', 'The badge of the tag in the back office. A theme may show it on the site.'];

        foreach (['fr_FR', 'en_US'] as $locale) {
            $catalogue = require \dirname(__DIR__, 3).'/I18n/'.$locale.'.php';

            foreach ($labels as $label) {
                self::assertArrayHasKey($label, $catalogue, \sprintf('%s has no %s translation.', $label, $locale));
            }
        }
    }
}
