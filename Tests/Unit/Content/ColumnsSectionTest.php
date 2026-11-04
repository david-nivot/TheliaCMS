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
use TheliaCMS\Content\ColumnsSection;
use TheliaCMS\Content\InvalidDocumentException;

final class ColumnsSectionTest extends TestCase
{
    public function testEachColumnIsAnImageOverAShortText(): void
    {
        $section = new ColumnsSection();
        $props = $section->normalizeProps(['columns' => [
            ['image' => 'https://img.logoipsum.com/243.svg', 'image_alt' => 'Logoipsum', 'text' => 'Lorem ipsum dolor sit amet.'],
            ['image' => 'https://img.logoipsum.com/245.svg', 'text' => 'Consectetur <b>adipiscing</b> elit.'],
            ['image' => null, 'title' => 'Sans logo', 'text' => 'Sed do eiusmod.'],
        ]]);

        self::assertSame(
            '<section class="cms-columns cms-columns--3" data-cms-sizes="(min-width: 77rem) 23.67rem, (min-width: 48rem) calc((100vw - 2rem - 4rem) / 3), calc(100vw - 2rem)" data-cms-height="5rem"><div class="cms-columns__grid">'
            .'<div class="cms-columns__item"><img class="cms-columns__image" src="https://img.logoipsum.com/243.svg" alt="Logoipsum" loading="lazy"><p class="cms-columns__text">Lorem ipsum dolor sit amet.</p></div>'
            .'<div class="cms-columns__item"><img class="cms-columns__image" src="https://img.logoipsum.com/245.svg" alt="" loading="lazy"><p class="cms-columns__text">Consectetur &lt;b&gt;adipiscing&lt;/b&gt; elit.</p></div>'
            .'<div class="cms-columns__item"><h3 class="cms-columns__heading">Sans logo</h3><p class="cms-columns__text">Sed do eiusmod.</p></div>'
            .'</div></section>',
            $section->render($props, 'fr_FR'),
            'As many columns as items, the text written as text and never as markup.',
        );
    }

    public function testAHeadingGoesAboveTheColumns(): void
    {
        $section = new ColumnsSection();
        $html = $section->render($section->normalizeProps(['title' => 'Ils nous font confiance', 'columns' => [['text' => 'Un'], ['text' => 'Deux']]]), 'fr_FR');

        self::assertStringStartsWith('<section class="cms-columns cms-columns--2" data-cms-sizes="(min-width: 77rem) 36.5rem, (min-width: 48rem) calc((100vw - 2rem - 2rem) / 2), calc(100vw - 2rem)" data-cms-height="5rem"><h2 class="cms-columns__title">Ils nous font confiance</h2>', $html);
    }

    /**
     * The grid of `blocks.css` stacks the columns below 48rem, then shares the
     * block between them, a gap of 2rem apart.
     */
    public function testDeclaresTheWidthOfOneColumn(): void
    {
        $section = new ColumnsSection();
        $html = static fn (int $count): string => $section->render($section->normalizeProps(['columns' => array_fill(0, $count, ['text' => 'Un'])]), 'fr_FR');

        self::assertStringContainsString('(min-width: 77rem) 17.25rem, (min-width: 48rem) calc((100vw - 2rem - 6rem) / 4), calc(100vw - 2rem)', $html(4));
        self::assertStringContainsString('(min-width: 77rem) 36.5rem, (min-width: 48rem) calc((100vw - 2rem - 2rem) / 2), calc(100vw - 2rem)', $html(2));
    }

    /**
     * `.cms-columns__image` is a band `5rem` high, as wide as its proportions
     * make it: the publication cannot know that without being told.
     */
    public function testDeclaresTheBandTheImagesAreDrawnIn(): void
    {
        $section = new ColumnsSection();
        $html = $section->render($section->normalizeProps(['columns' => [['text' => 'Un'], ['text' => 'Deux']]]), 'fr_FR');

        self::assertStringContainsString(' data-cms-height="5rem"', $html);
    }

    public function testTwoToFourColumns(): void
    {
        $section = new ColumnsSection();

        foreach ([1, 5] as $count) {
            try {
                $section->normalizeProps(['columns' => array_fill(0, $count, ['text' => 'Un'])]);
                self::fail(\sprintf('%d columns are refused.', $count));
            } catch (InvalidDocumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAnImageOfElsewhereIsAnHttpsAddress(): void
    {
        $this->expectException(InvalidDocumentException::class);

        (new ColumnsSection())->normalizeProps(['columns' => [['image' => 'javascript:alert(1)'], ['image' => 'http://example.com/a.png']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function threeLogos(): array
    {
        return ['columns' => [
            ['image' => 'https://img.test/a.png', 'text' => 'Un', 'focus_x' => 10, 'focus_y' => 90, 'zoom' => 200],
            ['image' => 'https://img.test/b.png', 'text' => 'Deux'],
            ['image' => 'https://img.test/c.png', 'text' => 'Trois', 'focus_x' => 80, 'focus_y' => 20, 'zoom' => 120],
        ]];
    }

    /**
     * The band is the default: a section stored before the proportions, and one
     * that carries a kept part in its rows, draw the markup of before to the byte.
     */
    public function testTheBandDrawsTheMarkupOfBeforeWhateverTheKeptPartOfTheRows(): void
    {
        $section = new ColumnsSection();
        $expected = '<section class="cms-columns cms-columns--3" data-cms-sizes="(min-width: 77rem) 23.67rem, (min-width: 48rem) calc((100vw - 2rem - 4rem) / 3), calc(100vw - 2rem)" data-cms-height="5rem"><div class="cms-columns__grid">'
            .'<div class="cms-columns__item"><img class="cms-columns__image" src="https://img.test/a.png" alt="" loading="lazy"><p class="cms-columns__text">Un</p></div>'
            .'<div class="cms-columns__item"><img class="cms-columns__image" src="https://img.test/b.png" alt="" loading="lazy"><p class="cms-columns__text">Deux</p></div>'
            .'<div class="cms-columns__item"><img class="cms-columns__image" src="https://img.test/c.png" alt="" loading="lazy"><p class="cms-columns__text">Trois</p></div>'
            .'</div></section>';

        self::assertSame($expected, $section->render($section->normalizeProps(self::threeLogos()), 'fr_FR'));
        self::assertSame($expected, $section->render($section->normalizeProps(self::threeLogos() + ['proportions' => 'band']), 'fr_FR'));
    }

    public function testARatioGivesEveryImageTheWidthOfItsColumnAndItsOwnKeptPart(): void
    {
        $section = new ColumnsSection();
        $html = $section->render($section->normalizeProps(self::threeLogos() + ['proportions' => '1:1']), 'fr_FR');

        self::assertStringStartsWith('<section class="cms-columns cms-columns--3" data-cms-sizes="(min-width: 77rem) 23.67rem, (min-width: 48rem) calc((100vw - 2rem - 4rem) / 3), calc(100vw - 2rem)"><div', $html, 'The slot of the column, held to no band.');
        self::assertStringNotContainsString('data-cms-height', $html);
        self::assertStringContainsString('<img class="cms-columns__image cms-columns__image--framed" src="https://img.test/a.png" alt="" loading="lazy" style="aspect-ratio:1/1;object-fit:cover;object-position:10% 90%" data-cms-crop="10,90,200,1000,1000">', $html);
        self::assertStringContainsString('<img class="cms-columns__image cms-columns__image--framed" src="https://img.test/b.png" alt="" loading="lazy" style="aspect-ratio:1/1;object-fit:cover;object-position:50% 50%" data-cms-crop="50,50,100,1000,1000">', $html);
        self::assertStringContainsString('src="https://img.test/c.png" alt="" loading="lazy" style="aspect-ratio:1/1;object-fit:cover;object-position:80% 20%" data-cms-crop="80,20,120,1000,1000">', $html);
    }

    public function testTheOriginalProportionsKeepTheShapeOfEachImageAndWriteOnlyItsZoom(): void
    {
        $section = new ColumnsSection();
        $html = $section->render($section->normalizeProps(self::threeLogos() + ['proportions' => 'original']), 'fr_FR');

        self::assertStringContainsString('<img class="cms-columns__image cms-columns__image--framed" src="https://img.test/a.png" alt="" loading="lazy" data-cms-crop="10,90,200,0,0">', $html);
        self::assertStringContainsString('<img class="cms-columns__image cms-columns__image--framed" src="https://img.test/b.png" alt="" loading="lazy">', $html);
        self::assertStringNotContainsString('data-cms-height', $html);
    }

    public function testAZoomOffTheStepsIsRefusedInARowWithItsPosition(): void
    {
        try {
            (new ColumnsSection())->normalizeProps(['columns' => [['text' => 'Un'], ['text' => 'Deux', 'zoom' => 155]]]);
            self::fail('A zoom off the steps of 10 is refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertStringContainsString('item 2', (string) $exception->getMessage());
        }
    }

    public function testTheModelIsNotOfferedTheProportionsNorTheKeptPartOfARow(): void
    {
        $schema = (new ColumnsSection())->schema()->toJsonSchema();
        $json = json_encode($schema, \JSON_THROW_ON_ERROR);

        foreach (['proportions', 'focus_x', 'focus_y', 'zoom'] as $name) {
            self::assertStringNotContainsString('"'.$name.'"', $json);
        }
    }
}
