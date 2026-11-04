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
use TheliaCMS\Content\ImageSlot;

final class ImageSlotTest extends TestCase
{
    public function testABlockIsTheViewportLessItsMarginsUpToItsMaxWidth(): void
    {
        self::assertSame('(max-width: 50rem) calc(100vw - 2rem), 48rem', ImageSlot::narrow());
        self::assertSame('(max-width: 77rem) calc(100vw - 2rem), 75rem', ImageSlot::content());
        self::assertSame('100vw', ImageSlot::screen());
    }

    public function testAColumnIsASharedOfTheBlockLessTheGaps(): void
    {
        self::assertSame('(min-width: 77rem) 23.67rem, (min-width: 48rem) calc((100vw - 2rem - 4rem) / 3), calc(100vw - 2rem)', ImageSlot::column(3));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function values(): iterable
    {
        yield 'the narrow block' => [ImageSlot::narrow(), true];
        yield 'one of four columns' => [ImageSlot::column(4), true];
        yield 'the screen' => ['100vw', true];
        yield 'a fixed width' => ['320px', true];
        yield 'empty' => ['', false];
        yield 'a url' => ['url(https://evil.test/a.png)', false];
        yield 'a variable' => ['var(--slot)', false];
        yield 'a quote' => ['100vw" onerror="x', false];
        yield 'a semicolon' => ['100vw; color: red', false];
        yield 'an unclosed parenthesis' => ['calc(100vw - 2rem', false];
        yield 'a closing parenthesis first' => [')100vw(', false];
        yield 'a percentage' => ['50%', false];
        yield 'too long' => [str_repeat('100vw, ', 60).'100vw', false];
    }

    #[DataProvider('values')]
    public function testOnlyAListOfWidthsIsValid(string $value, bool $valid): void
    {
        self::assertSame($valid, ImageSlot::isValid($value));
    }

    public function testTheAttributeEscapesItsValue(): void
    {
        self::assertSame(' data-cms-sizes="a&quot;b"', ImageSlot::attribute('a"b'));
    }
}
