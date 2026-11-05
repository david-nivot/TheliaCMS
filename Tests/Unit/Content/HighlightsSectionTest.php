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

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\HighlightsSection;
use TheliaCMS\Content\InvalidDocumentException;

final class HighlightsSectionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function block(string $title = 'Soldes'): array
    {
        return ['title' => $title, 'text' => 'Jusqu\'à -50%', 'button_label' => 'Voir', 'button_url' => '/soldes', 'image' => 'https://img.test/d.jpg', 'image_alt' => 'Des soldes'];
    }

    private function render(array $blocks): string
    {
        $section = new HighlightsSection();

        return $section->render($section->normalizeProps(['blocks' => $blocks]), 'fr_FR');
    }

    public function testABlockIsAPictureUnderATitleACatchphraseAndAButton(): void
    {
        self::assertSame(
            '<ul class="cms-highlights cms-highlights--1" data-cms-sizes="100vw">'
            .'<li class="cms-highlights__item cms-highlights__item--lead"><img class="cms-highlights__image" src="https://img.test/d.jpg" alt="Des soldes" loading="lazy">'
            .'<div class="cms-highlights__content"><p class="cms-highlights__title">Soldes</p><p class="cms-highlights__text">Jusqu&apos;à -50%</p><a class="cms-highlights__button" href="/soldes">Voir</a></div></li></ul>',
            $this->render([self::block()]),
        );
    }

    public function testOnlyTheFirstBlockIsTheLargeOneAndTheTextIsEscaped(): void
    {
        $html = $this->render([self::block(), self::block('<b>Deux</b>'), self::block('Trois')]);

        self::assertStringStartsWith('<ul class="cms-highlights cms-highlights--3" data-cms-sizes="(min-width: 48rem) 67vw, 100vw"', $html);
        self::assertSame(1, substr_count($html, 'cms-highlights__item--lead'));
        self::assertSame(3, substr_count($html, '<li '));
        self::assertStringContainsString('&lt;b&gt;Deux&lt;/b&gt;', $html);
    }

    public function testTheMobileImageIsDrawnBelow1024PixelsAndTheComputerOneAbove(): void
    {
        $html = $this->render([self::block() + ['image_mobile' => 'https://img.test/m.jpg']]);

        self::assertStringContainsString('<picture class="cms-highlights__picture"><source media="(max-width: 1023px)" srcset="https://img.test/m.jpg"><img class="cms-highlights__image" src="https://img.test/d.jpg" alt="Des soldes" loading="lazy"></picture>', $html);
    }

    public function testWithoutAMobileImageTheComputerOneIsTheOnlyPicture(): void
    {
        $html = $this->render([self::block()]);

        self::assertStringNotContainsString('<picture', $html);
        self::assertStringNotContainsString('<source', $html);
        self::assertStringContainsString('src="https://img.test/d.jpg"', $html);
    }

    public function testABlockWithOnlyAMobileImageDrawsItEverywhere(): void
    {
        $html = $this->render([['title' => 'Seul', 'image_mobile' => 'https://img.test/m.jpg']]);

        self::assertStringContainsString('src="https://img.test/m.jpg"', $html);
        self::assertStringNotContainsString('<source', $html);
    }

    public function testTheImageAndTheLinkAreOptional(): void
    {
        $html = $this->render([['title' => 'Texte seul', 'button_label' => 'Voir']]);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<a ', $html, 'A button needs its address.');
        self::assertStringContainsString('<p class="cms-highlights__title">Texte seul</p>', $html);
    }

    public function testTheKeptPartOfThePictureMovesItsFocus(): void
    {
        $html = $this->render([self::block() + ['focus_x' => 20, 'focus_y' => 80]]);

        self::assertStringContainsString('style="object-position:20% 80%"', $html);
        self::assertStringNotContainsString('object-position', $this->render([self::block()]));
    }

    public function testOneToThreeBlocks(): void
    {
        $section = new HighlightsSection();

        foreach ([[], [self::block(), self::block(), self::block(), self::block()]] as $blocks) {
            try {
                $section->normalizeProps(['blocks' => $blocks]);
                self::fail(\sprintf('%d blocks are refused.', \count($blocks)));
            } catch (InvalidDocumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertCount(3, $section->normalizeProps(['blocks' => [self::block(), self::block(), self::block()]])['blocks']);
    }

    public function testAButtonAddressIsAPathOrAWebAddress(): void
    {
        $this->expectException(InvalidDocumentException::class);

        (new HighlightsSection())->normalizeProps(['blocks' => [['title' => 'A', 'button_url' => 'javascript:alert(1)']]]);
    }

    public function testAMobileImageOfElsewhereIsAnHttpsAddress(): void
    {
        $this->expectException(InvalidDocumentException::class);

        (new HighlightsSection())->normalizeProps(['blocks' => [['title' => 'A', 'image_mobile' => 'http://example.com/a.png']]]);
    }

    public function testNothingIsDrawnWithoutBlocks(): void
    {
        self::assertSame('', (new HighlightsSection())->render(['blocks' => []], 'fr_FR'));
    }

    public function testTheModelIsOfferedTheMobileImageAndTheLimitsButNotTheKeptPart(): void
    {
        $schema = (new HighlightsSection())->schema()->toJsonSchema();
        $json = json_encode($schema, \JSON_THROW_ON_ERROR);
        $blocks = (array) $schema['properties'];
        $blocks = (array) $blocks['blocks'];

        self::assertSame(1, $blocks['minItems']);
        self::assertSame(3, $blocks['maxItems']);
        self::assertStringContainsString('"image_mobile"', $json);
        self::assertStringContainsString('screen narrower than 1024px', $json, 'The generator reads the help, never the default.');

        foreach (['focus_x', 'focus_y'] as $name) {
            self::assertStringNotContainsString('"'.$name.'"', $json);
        }
    }

    public function testTheSampleIsValid(): void
    {
        $section = new HighlightsSection();

        self::assertCount(1, $section->normalizeProps($section->sample())['blocks']);
    }
}
