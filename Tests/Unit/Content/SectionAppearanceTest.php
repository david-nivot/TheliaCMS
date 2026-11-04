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

namespace TheliaCMS\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\SectionAppearance;

final class SectionAppearanceTest extends TestCase
{
    public function testTheSettingsBecomeTheStyleOfTheSection(): void
    {
        $appearance = SectionAppearance::fromArray(['background' => '#F8D7D7', 'text' => '#3a1010', 'padding' => 'large', 'align' => 'center']);

        self::assertSame('#f8d7d7', $appearance->background, 'A colour is kept in lowercase, the way it is written back.');
        self::assertSame('background-color:#f8d7d7;color:#3a1010;padding-block:5rem;text-align:center', $appearance->style());
    }

    public function testEmptySettingsAreNoSettings(): void
    {
        $appearance = SectionAppearance::fromArray(['background' => '', 'text' => null, 'padding' => '', 'align' => null]);

        self::assertTrue($appearance->isEmpty(), 'An empty field of the form is the look of the component.');
        self::assertSame('', $appearance->style());
        self::assertSame([], $appearance->toArray());
    }

    public function testOnlyTheSettingsAndTheirValuesAreAccepted(): void
    {
        try {
            SectionAppearance::fromArray([
                'background' => 'red;position:fixed',
                'text' => 'url(https://example.com/x)',
                'padding' => 'huge',
                'align' => 'justify',
                'style' => 'display:none',
            ]);
            self::fail('A value that is not one of the settings is refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertCount(5, $exception->problems, 'Every refused value is reported at once, for the model to correct them together.');
            self::assertStringContainsString('"style"', implode(' ', $exception->problems));
        }
    }

    public function testALengthIsKeptAsWrittenInLowercase(): void
    {
        $appearance = SectionAppearance::fromArray([
            'padding_top' => '0px',
            'padding_bottom' => ' 64PX ',
            'margin_top' => '2rem',
            'margin_bottom' => '0.25rem',
        ]);

        self::assertSame('padding-top:0px;padding-bottom:64px;margin-top:2rem;margin-bottom:0.25rem', $appearance->style());
        self::assertSame(['padding_top' => '0px', 'padding_bottom' => '64px', 'margin_top' => '2rem', 'margin_bottom' => '0.25rem'], $appearance->toArray());
    }

    public function testTheLeftAndTheRightTakeALengthAsTheTopAndTheBottomDo(): void
    {
        $appearance = SectionAppearance::fromArray([
            'margin_right' => '1rem',
            'padding_left' => '24PX',
            'margin_left' => '0px',
            'padding_right' => '1.5rem',
            'padding_top' => '8px',
        ]);

        self::assertSame('padding-top:8px;padding-left:24px;padding-right:1.5rem;margin-left:0px;margin-right:1rem', $appearance->style());
        self::assertSame(['padding_top' => '8px', 'padding_left' => '24px', 'padding_right' => '1.5rem', 'margin_left' => '0px', 'margin_right' => '1rem'], $appearance->toArray());
    }

    public function testTheBoundsOfEachUnitAreAccepted(): void
    {
        $appearance = SectionAppearance::fromArray(['padding_top' => '320px', 'padding_bottom' => '20rem', 'margin_top' => '319.99px', 'margin_bottom' => '20.00rem']);

        self::assertSame('padding-top:320px;padding-bottom:20rem;margin-top:319.99px;margin-bottom:20.00rem', $appearance->style());
    }

    public function testThePaddingTakesALengthAndASideThatIsSetComesAfterIt(): void
    {
        $appearance = SectionAppearance::fromArray(['padding' => '4.5rem', 'padding_top' => '0px']);

        self::assertSame('padding-block:4.5rem;padding-top:0px', $appearance->style(), 'The side is written after the pair, so it wins for its side.');
        self::assertSame('padding-block:3rem;padding-bottom:10px', SectionAppearance::fromArray(['padding' => 'medium', 'padding_bottom' => '10px'])->style());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function refusedLengths(): iterable
    {
        yield 'calc' => ['padding_top', 'calc(1px + 1px)'];
        yield 'var' => ['padding_bottom', 'var(--room, 10px)'];
        yield 'unknown unit' => ['margin_top', '10em'];
        yield 'percentage' => ['margin_bottom', '10%'];
        yield 'negative' => ['margin_top', '-4px'];
        yield 'plus sign' => ['margin_top', '+4px'];
        yield 'too large in px' => ['padding_top', '321px'];
        yield 'too large in rem' => ['padding_bottom', '20.01rem'];
        yield 'three decimals' => ['margin_bottom', '1.234rem'];
        yield 'left as calc' => ['padding_left', 'calc(1px + 1px)'];
        yield 'right too large' => ['margin_right', '321px'];
        yield 'right negative' => ['margin_right', '-1rem'];
        yield 'no unit' => ['margin_top', '64'];
        yield 'no number' => ['margin_top', 'px'];
        yield 'leading zero' => ['margin_top', '08px'];
        yield 'declaration smuggled in' => ['margin_top', '4px;position:fixed'];
        yield 'a number, not a string' => ['padding_top', 64];
        yield 'padding as a length too large' => ['padding', '400px'];
        yield 'padding as calc' => ['padding', 'calc(1rem)'];
        yield 'padding as an unknown preset' => ['padding', 'huge'];
    }

    #[DataProvider('refusedLengths')]
    public function testAnythingButAStrictLengthIsRefused(string $setting, mixed $value): void
    {
        try {
            SectionAppearance::fromArray([$setting => $value]);
            self::fail('A value that is not a length within its bounds is refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertCount(1, $exception->problems);
            self::assertStringContainsString($setting, $exception->problems[0]);
        }
    }

    /**
     * The generator reads these messages on a correction turn and sets
     * presets only: a message names no setting nor unit it is not given.
     */
    #[DataProvider('messagesWithoutLengths')]
    public function testAMessageOffersNoLengthToWhoeverDidNotWriteOne(array $values, string $expected): void
    {
        try {
            SectionAppearance::fromArray($values);
            self::fail('The appearance is refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertSame([$expected], $exception->problems);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function messagesWithoutLengths(): iterable
    {
        yield 'unknown setting' => [['shadow' => true], 'The appearance has no setting "shadow": only background, text, padding, align, and the room on each side set in the editor.'];
        yield 'unknown preset' => [['padding' => 'huge'], 'The padding of the appearance is none of small, medium, large.'];
    }

    public function testAPaddingWrittenAsALengthIsToldItsBound(): void
    {
        try {
            SectionAppearance::fromArray(['padding' => '400px']);
            self::fail('The appearance is refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertSame(['The padding of the appearance is not a length: write a number and px (0 to 320) or rem (0 to 20), such as 64px or 2rem.'], $exception->problems);
        }
    }

    public function testASectionWithoutPreciseRoomWritesTheStyleItAlwaysDid(): void
    {
        $appearance = SectionAppearance::fromArray(['background' => '#f8d7d7', 'padding' => 'medium']);

        self::assertSame('background-color:#f8d7d7;padding-block:3rem', $appearance->style());
        self::assertSame(['background' => '#f8d7d7', 'padding' => 'medium'], $appearance->toArray(), 'The stored form gains no key.');
    }
}
