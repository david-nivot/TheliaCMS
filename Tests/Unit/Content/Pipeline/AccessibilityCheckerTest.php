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

namespace TheliaCMS\Tests\Unit\Content\Pipeline;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\Pipeline\AccessibilityChecker;
use TheliaCMS\Media\ImageState;
use TheliaCMS\Media\MediaDescriptions;

final class AccessibilityCheckerTest extends TestCase
{
    private AccessibilityChecker $checker;

    private int $libraryQuestions = 0;

    protected function setUp(): void
    {
        // Library images 1 (undescribed), 2 (decorative) and 3 (described).
        $library = new class($this->libraryQuestions) implements MediaDescriptions {
            public function __construct(public int &$questions)
            {
            }

            public function statesOf(array $urls, string $locale): array
            {
                ++$this->questions;
                $states = [];

                foreach ($urls as $url) {
                    if (1 === preg_match('#^/image-library/(\d)/#', $url, $matches)) {
                        $states[$url] = [1 => ImageState::Undescribed, 2 => ImageState::Decorative, 3 => ImageState::Described][(int) $matches[1]] ?? null;
                    }
                }

                return array_filter($states);
            }
        };

        $this->checker = new AccessibilityChecker($library, new class implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en_US';
            }
        });
    }

    public function testAnImageWithNoAltAttributeWarns(): void
    {
        $problems = $this->checker->check('<img src="https://example.com/a.jpg">', 'en_US');

        self::assertCount(1, $problems);
        self::assertStringContainsString('no description', $problems[0]);
    }

    public function testABlankAltOnAnUndescribedLibraryImageWarns(): void
    {
        self::assertCount(1, $this->checker->check('<img src="/image-library/1/full/max/0/default.jpg" alt="  ">', 'en_US'));
    }

    public function testABlankAltOnADecorativeLibraryImagePasses(): void
    {
        self::assertSame([], $this->checker->check('<img src="/image-library/2/full/max/0/default.jpg" alt="">', 'en_US'));
    }

    public function testABlankAltOnADescribedLibraryImageIsLeftToTheLibrary(): void
    {
        self::assertSame([], $this->checker->check('<img src="/image-library/3/full/max/0/default.jpg" alt="">', 'en_US'));
    }

    public function testAnAltWithTextPasses(): void
    {
        self::assertSame([], $this->checker->check('<img src="/image-library/1/full/max/0/default.jpg" alt="A green chair">', 'en_US'));
    }

    public function testABlankAltOutsideTheLibraryPasses(): void
    {
        self::assertSame([], $this->checker->check('<img src="https://example.com/a.jpg" alt="">', 'en_US'));
    }

    public function testAnImageHiddenFromAssistiveTechnologyPasses(): void
    {
        self::assertSame([], $this->checker->check('<div aria-hidden="true"><img src="/image-library/1/full/max/0/default.jpg" alt=""></div>', 'en_US'));
        self::assertSame([], $this->checker->check('<img src="https://example.com/a.jpg" role="presentation">', 'en_US'));
        self::assertSame([], $this->checker->check('<figure role="none"><img src="https://example.com/a.jpg"></figure>', 'en_US'));
    }

    public function testSeveralImagesGiveOneMessageWithTheCount(): void
    {
        $problems = $this->checker->check('<img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg">', 'en_US');

        self::assertCount(1, $problems);
        self::assertStringContainsString('3 images', $problems[0]);
    }

    public function testALinkWithNothingInItWarns(): void
    {
        $problems = $this->checker->check('<a href="/x"></a>', 'en_US');

        self::assertCount(1, $problems);
        self::assertStringContainsString('no text', $problems[0]);
    }

    public function testALinkOfAnIconAloneWarnsUnlessItIsNamed(): void
    {
        $icon = '<svg aria-hidden="true" viewBox="0 0 1 1"><path d="M0 0"/></svg>';

        self::assertCount(1, $this->checker->check('<a href="/x">'.$icon.'</a>', 'en_US'));
        self::assertSame([], $this->checker->check('<a href="/x" aria-label="Home">'.$icon.'</a>', 'en_US'));
        self::assertSame([], $this->checker->check('<a href="/x" aria-labelledby="name">'.$icon.'</a>', 'en_US'));
    }

    public function testALinkHoldingAnImageThatSaysSomethingPasses(): void
    {
        self::assertSame([], $this->checker->check('<a href="/x"><img src="https://example.com/logo.png" alt="Logo"></a>', 'en_US'));
        self::assertCount(1, $this->checker->check('<a href="/x"><img src="https://example.com/logo.png" alt=""></a>', 'en_US'));
    }

    public function testAGenericLinkTextWarnsInEnglishAndInFrench(): void
    {
        $english = $this->checker->check('<a href="/x">Click here</a>', 'en_US');
        $french = $this->checker->check('<a href="/x"> Cliquez ici ! </a>', 'fr_FR');

        self::assertCount(1, $english);
        self::assertStringContainsString('"click here"', $english[0]);
        self::assertCount(1, $french);
        self::assertStringContainsString('"cliquez ici"', $french[0]);
    }

    public function testOnlyTheWholeTextOfALinkIsGeneric(): void
    {
        self::assertSame([], $this->checker->check('<a href="/x">Read more about our method</a>', 'en_US'));
        self::assertSame([], $this->checker->check('<a href="/x">En savoir plus sur notre méthode</a>', 'fr_FR'));
    }

    public function testALinkWithAnAccessibleNameIsNotGeneric(): void
    {
        self::assertSame([], $this->checker->check('<a href="/x" aria-label="Read more about the 2026 range">Read more</a>', 'en_US'));
    }

    public function testOtherLocalesGetNoGenericLinkWarning(): void
    {
        self::assertSame([], $this->checker->check('<a href="/x">Click here</a>', 'de_DE'));
        self::assertCount(1, $this->checker->check('<a href="/x"></a>', 'de_DE'));
    }

    public function testAnAnchorWithoutAddressIsIgnored(): void
    {
        self::assertSame([], $this->checker->check('<a id="top"></a><a name="x">here</a>', 'en_US'));
    }

    public function testEmptyAndNullHtmlGiveNothing(): void
    {
        self::assertSame([], $this->checker->check(null, 'en_US'));
        self::assertSame([], $this->checker->check('  ', 'en_US'));
        self::assertSame([], $this->checker->check('<p>No image, no link.</p>', 'en_US'));
    }

    public function testEachKindIsReportedOnceWithItsCount(): void
    {
        $html = '<img src="/a.jpg"><a href="/x"></a><a href="/y"></a><a href="/z">here</a><a href="/w">more</a>';

        $problems = $this->checker->check($html, 'en_US');

        self::assertCount(3, $problems);
        self::assertStringContainsString('2 links have no text', $problems[1]);
        self::assertStringContainsString('2 link texts', $problems[2]);
    }

    public function testSeveralContentsAreJudgedWithOneQuestionToTheLibrary(): void
    {
        $problems = $this->checker->checkMany([
            'a' => '<img src="/image-library/1/full/max/0/default.jpg" alt="">',
            'b' => '<img src="/image-library/1/full/max/0/default.jpg" alt=""><img src="/image-library/2/full/max/0/default.jpg" alt="">',
            'c' => '<p>Nothing</p>',
            'd' => null,
        ], 'en_US');

        self::assertSame(1, $this->libraryQuestions);
        self::assertCount(1, $problems['a']);
        self::assertStringContainsString('An image', $problems['b'][0]);
        self::assertSame([], $problems['c']);
        self::assertSame([], $problems['d']);
    }
}
