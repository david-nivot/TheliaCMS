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

namespace TheliaCMS\Tests\Unit\Media\Svg\Color;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Color\SvgColor;

final class SvgColorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function spellings(): iterable
    {
        yield 'short hexadecimal' => ['#FFF', '#ffffff'];
        yield 'long hexadecimal' => ['#1C1C1C', '#1c1c1c'];
        yield 'opaque alpha' => ['#000000ff', '#000000'];
        yield 'short alpha' => ['#0008', '#00000088'];
        yield 'named' => ['White', '#ffffff'];
        yield 'named outside the table' => ['crimson', 'crimson'];
        yield 'rgb with commas' => ['rgb(255, 255, 255)', '#ffffff'];
        yield 'rgb with spaces and a percentage' => ['rgb(100% 0% 0%)', '#ff0000'];
        yield 'rgba half transparent' => ['rgba(0,0,0,.5)', '#00000080'];
        yield 'hsl' => ['hsl(120, 100%, 25%)', '#008000'];
        yield 'none' => ['none', null];
        yield 'current color' => ['currentColor', null];
        yield 'gradient' => ['url(#a)', null];
        yield 'inherited' => ['inherit', null];
        yield 'unreadable' => ['#12', null];
        yield 'a word with digits' => ['red2', null];
    }

    #[DataProvider('spellings')]
    public function testEverySpellingOfAColorComesToOneKey(string $value, ?string $key): void
    {
        self::assertSame($key, SvgColor::key($value));
    }

    public function testEveryKeyFitsTheAddress(): void
    {
        foreach (self::spellings() as [$value]) {
            $key = SvgColor::key($value);

            if (null !== $key) {
                self::assertMatchesRegularExpression(SvgColor::KEY_PATTERN, $key, $value);
            }
        }
    }
}
