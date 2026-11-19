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

namespace TheliaCMS\Tests\Unit\Content\Kind;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\Kind\ContentKinds;
use TheliaCMS\Content\Kind\InvalidContentKindException;
use TheliaCMS\Content\Kind\UnknownContentKindException;
use TheliaCMS\Tests\Support\GuideKind;

/**
 * The registry refuses what `cms_page.content_kind` could not hold or tell
 * apart, as soon as it is built, and answers by code.
 */
final class ContentKindsTest extends TestCase
{
    /**
     * Sabotage: drop the `preg_match()` check from the constructor of
     * `ContentKinds`; every case of the provider is then accepted and the test
     * is red.
     */
    #[DataProvider('invalidCodes')]
    public function testACodeThatTheColumnCouldNotHoldIsRefusedAtOnce(string $code): void
    {
        $this->expectException(InvalidContentKindException::class);

        new ContentKinds([new GuideKind($code)]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'capital' => ['Guide'];
        yield 'dash' => ['size-guide'];
        yield 'space' => ['size guide'];
        yield 'accent' => ['guidé'];
        yield 'line feed after a valid code' => ["guide\n"];
        yield '33 characters' => [str_repeat('a', 33)];
    }

    public function testTheLimitsOfACodeAreAccepted(): void
    {
        $kinds = new ContentKinds([new GuideKind('a'), new GuideKind(str_repeat('z', 32)), new GuideKind('size_guide_2')]);

        self::assertTrue($kinds->has('a'));
        self::assertTrue($kinds->has(str_repeat('z', 32)));
        self::assertSame('size_guide_2', $kinds->get('size_guide_2')->code());
    }

    /**
     * Sabotage: remove the `isset($byCode[$code])` branch of the constructor:
     * the second kind silently replaces the first and the test is red.
     */
    public function testTwoKindsOfTheSameCodeAreRefusedAtOnce(): void
    {
        $this->expectException(InvalidContentKindException::class);
        $this->expectExceptionMessage('declared twice');

        new ContentKinds([new GuideKind('guide'), new GuideKind('other'), new GuideKind('guide')]);
    }

    public function testAnUnknownCodeIsRefusedByNameAndAsked(): void
    {
        $kinds = new ContentKinds([new GuideKind('guide')]);

        self::assertFalse($kinds->has('ghost'));

        $this->expectException(UnknownContentKindException::class);
        $this->expectExceptionMessage('"ghost"');

        $kinds->get('ghost');
    }

    public function testNoKindAtAllIsAValidSite(): void
    {
        $kinds = new ContentKinds([]);

        self::assertFalse($kinds->has('header'));
    }
}
