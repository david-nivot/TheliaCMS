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
}
