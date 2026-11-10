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

final class IconNameTest extends TestCase
{
    #[DataProvider('names')]
    public function testTheRuleOfAName(string $name, bool $valid): void
    {
        self::assertSame($valid, IconName::isValid($name));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'one word' => ['home', true];
        yield 'words and digits' => ['document-valide-2', true];
        yield 'maximum length' => [str_repeat('a', 64), true];
        yield 'too long' => [str_repeat('a', 65), false];
        yield 'empty' => ['', false];
        yield 'uppercase' => ['Home', false];
        yield 'underscore' => ['my_icon', false];
        yield 'space' => ['my icon', false];
        yield 'accent' => ['écran', false];
        yield 'prefix included' => ['cms:home', false];
        yield 'slash' => ['a/b', false];
        yield 'leading hyphen' => ['-a', false];
        yield 'trailing hyphen' => ['a-', false];
        yield 'double hyphen' => ['a--b', false];
        yield 'trailing newline' => ["home\n", false];
    }

    #[DataProvider('typedNames')]
    public function testANameTypedLosesItsSpacesAndItsPrefixOnly(string $typed, string $expected): void
    {
        self::assertSame($expected, IconName::normalize($typed));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typedNames(): iterable
    {
        yield 'bare' => ['home', 'home'];
        yield 'prefixed' => ['cms:home', 'home'];
        yield 'spaces around' => ['  cms:home ', 'home'];
        yield 'the case is kept' => ['cms:Home', 'Home'];
        yield 'prefix inside' => ['my-cms:home', 'my-cms:home'];
        yield 'prefix twice' => ['cms:cms:home', 'cms:home'];
        yield 'nothing' => ['', ''];
    }

    public function testAnIconIsCitedBetweenQuotesOnly(): void
    {
        self::assertTrue(IconName::isQuotedIn('{{ cms_icon(\'cms:home\') }}', 'home'));
        self::assertTrue(IconName::isQuotedIn('{{ cms_icon("cms:home") }}', 'home'));
        self::assertFalse(IconName::isQuotedIn('{{ cms_icon(\'cms:home-2\') }}', 'home'));
        self::assertFalse(IconName::isQuotedIn('cms:home', 'home'));
    }

    public function testThePrefixedFormIsTheOneOfUxIcon(): void
    {
        self::assertSame('cms:home', IconName::prefixed('home'));
    }

    #[DataProvider('references')]
    public function testTheRuleOfAValueAnIconPropStores(string $reference, bool $valid): void
    {
        self::assertSame($valid, IconName::isReference($reference));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function references(): iterable
    {
        yield 'icon of the library' => ['cms:document-valide', true];
        yield 'icon of the theme' => ['not-found', true];
        yield 'icon of another set, shaped like one' => ['lucide:home', true];
        yield 'empty' => ['', false];
        yield 'prefix alone' => ['cms:', false];
        yield 'nothing before the colon' => [':home', false];
        yield 'uppercase' => ['Pas Valide', false];
        yield 'uppercase after the prefix' => ['cms:Home', false];
        yield 'space' => ['cms:my icon', false];
        yield 'path' => ['../home', false];
        yield 'trailing newline' => ["cms:home\n", false];
        yield 'maximum length' => [str_repeat('a', 128), true];
        yield 'too long' => [str_repeat('a', 129), false];
    }
}
