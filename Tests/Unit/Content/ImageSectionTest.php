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
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Prop;

final class ImageSectionTest extends TestCase
{
    public function testAPictureOverItsCaption(): void
    {
        $section = new ImageSection();
        $props = $section->normalizeProps(['image' => 'https://example.com/tarte.jpg', 'alt' => 'Une tarte aux pommes dorée', 'caption' => 'Tout juste <b>sortie</b> du four', 'width' => 'narrow']);

        self::assertSame(
            '<figure class="cms-image cms-image--narrow" data-cms-sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"><img class="cms-image__img" src="https://example.com/tarte.jpg" alt="Une tarte aux pommes dorée" loading="lazy">'
            .'<figcaption class="cms-image__caption">Tout juste &lt;b&gt;sortie&lt;/b&gt; du four</figcaption></figure>',
            $section->render($props, 'fr_FR'),
            'The caption is written as text, never as markup.',
        );
    }

    public function testNoCaptionNoFigcaption(): void
    {
        $section = new ImageSection();

        self::assertSame(
            '<figure class="cms-image cms-image--narrow" data-cms-sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"><img class="cms-image__img" src="https://example.com/tarte.jpg" alt="" loading="lazy"></figure>',
            $section->render($section->normalizeProps(['image' => 'https://example.com/tarte.jpg']), 'fr_FR'),
            'As narrow as a column of text unless asked otherwise: a photo is not a banner.',
        );
    }

    /**
     * `blocks.css` sets the width of each variant: the section says it, so the
     * publication does not have to guess it from the file.
     */
    public function testDeclaresTheWidthTheImageIsDrawnAt(): void
    {
        $section = new ImageSection();
        $declared = static fn (string $width): string => $section->render($section->normalizeProps(['image' => 'https://example.com/a.jpg', 'width' => $width]), 'fr_FR');

        self::assertStringContainsString('data-cms-sizes="(max-width: 50rem) calc(100vw - 2rem), 48rem"', $declared('narrow'));
        self::assertStringContainsString('data-cms-sizes="(max-width: 77rem) calc(100vw - 2rem), 75rem"', $declared('column'));
        self::assertStringContainsString('data-cms-sizes="100vw"', $declared('full'));
    }

    public function testNoImageDrawsNothing(): void
    {
        $section = new ImageSection();

        self::assertSame('', $section->render($section->normalizeProps($section->sample()), 'fr_FR'), 'The sample is valid and draws nothing until an image is chosen.');
    }

    public function testAnImageOfElsewhereIsAnHttpsAddress(): void
    {
        $this->expectException(InvalidDocumentException::class);

        (new ImageSection())->normalizeProps(['image' => 'http://example.com/a.png']);
    }

    public function testAWidthOutsideTheChoicesIsRefused(): void
    {
        $this->expectException(InvalidDocumentException::class);

        (new ImageSection())->normalizeProps(['image' => 'https://example.com/a.png', 'width' => 'huge']);
    }

    /**
     * The order of the schema is the order of the form and of the lines of the
     * prompt: the picture, how wide it is, what is written under it, then the
     * text for those who do not see it.
     */
    public function testThePropsAreInTheOrderOfTheForm(): void
    {
        $props = (new ImageSection())->schema()->props;

        self::assertSame(['image', 'width', 'caption', 'alt'], array_map(static fn (Prop $prop): string => $prop->name, $props));
    }

    public function testTheEditorReadsItsOwnHelpAndTheModelTheInstruction(): void
    {
        foreach ((new ImageSection())->schema()->props as $prop) {
            self::assertNotNull($prop->editorHelp, $prop->name.' has a help written for the editor.');
            self::assertNotSame($prop->help, $prop->editorHelp, $prop->name.': the help of the editor is not the instruction to the model.');
        }
    }
}
