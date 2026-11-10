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

namespace TheliaCMS\Tests\Unit\Media\Icon;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Icon\IconName;
use TheliaCMS\Media\Icon\IconNameSuggestion;

final class IconNameSuggestionTest extends TestCase
{
    #[DataProvider('fileNames')]
    public function testTheNameIsDrawnFromTheFileName(string $fileName, string $expected): void
    {
        self::assertSame($expected, IconNameSuggestion::fromFileName($fileName));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fileNames(): iterable
    {
        yield 'plain' => ['home.svg', 'home'];
        yield 'uppercase' => ['HOME.SVG', 'home'];
        yield 'accents' => ['Écran téléphoné.svg', 'ecran-telephone'];
        yield 'spaces and underscores' => ['my  big_icon.svg', 'my-big-icon'];
        yield 'dots and symbols' => ['logo (v2).final@2x.svg', 'logo-v2-final-2x'];
        yield 'hyphens doubled and on the edges' => ['--a---b--.svg', 'a-b'];
        yield 'only the extension is cut' => ['arrow.right.svg', 'arrow-right'];
        yield 'no extension' => ['arrow', 'arrow'];
        yield 'empty' => ['', ''];
        yield 'only symbols' => ['***.svg', ''];
    }

    public function testTheNameIsCutAtTheMaximumLengthWithoutAHyphenAtTheEnd(): void
    {
        $name = IconNameSuggestion::fromFileName(str_repeat('a', 63).' b.svg');

        self::assertSame(str_repeat('a', 63), $name, 'Cut at 64 characters, the hyphen that ended it removed.');
        self::assertLessThanOrEqual(IconName::MAXIMUM_LENGTH, \strlen(IconNameSuggestion::fromFileName(str_repeat('word ', 40).'.svg')));
        self::assertTrue(IconName::isValid(IconNameSuggestion::fromFileName(str_repeat('word ', 40).'.svg')));
    }
}
