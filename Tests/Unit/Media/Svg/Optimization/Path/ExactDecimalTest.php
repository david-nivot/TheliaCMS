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

namespace TheliaCMS\Tests\Unit\Media\Svg\Optimization\Path;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Optimization\Path\ExactDecimal;

final class ExactDecimalTest extends TestCase
{
    #[DataProvider('texts')]
    public function testANumberIsWrittenInItsShortestText(string $text, string $expected): void
    {
        self::assertSame($expected, ExactDecimal::fromText($text)?->toText());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'zeros around' => ['0010.500', '10.5'];
        yield 'leading zero of a fraction' => ['-0.25', '-.25'];
        yield 'negative zero' => ['-0.0', '0'];
        yield 'trailing dot' => ['3.', '3'];
        yield 'exponent expanded when shorter' => ['1.5e1', '15'];
        yield 'exponent kept when shorter' => ['9.25064e-05', '925064e-10'];
        yield 'large round number' => ['1000000', '1e6'];
        yield 'round number as short either way' => ['100', '100'];
    }

    public function testAddingAndSubtractingKeepEveryDigit(): void
    {
        $tenth = ExactDecimal::fromText('.1');
        $fifth = ExactDecimal::fromText('.2');
        self::assertNotNull($tenth);
        self::assertNotNull($fifth);

        self::assertSame('.3', $tenth->plus($fifth)?->toText());
        self::assertSame('.2', ExactDecimal::fromText('10.3')?->minus(ExactDecimal::fromText('10.1') ?? ExactDecimal::zero())?->toText());
        self::assertTrue(ExactDecimal::fromText('2.50')?->equals(ExactDecimal::fromText('25e-1') ?? ExactDecimal::zero()));
    }

    #[DataProvider('unheld')]
    public function testANumberThatCannotBeHeldExactlyIsNull(string $text): void
    {
        self::assertNull(ExactDecimal::fromText($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unheld(): iterable
    {
        yield 'more digits than a float keeps' => ['1.2345678901234567'];
        yield 'exponent too large' => ['1e40'];
        yield 'too large once scaled' => ['1e25'];
        yield 'not a number' => ['.'];
    }

    public function testASumPastTheIntegerRangeIsNull(): void
    {
        $large = ExactDecimal::fromText('922337203685477');
        $fraction = ExactDecimal::fromText('92233720368.5477');
        self::assertNotNull($large);
        self::assertNotNull($fraction);

        self::assertNull($large->plus($fraction));
        self::assertNull($large->minus(ExactDecimal::fromText('.00001') ?? ExactDecimal::zero()));
    }
}
