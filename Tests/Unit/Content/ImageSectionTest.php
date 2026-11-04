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

        self::assertSame(['image', 'width', 'width_px', 'height_px', 'fit', 'focus_x', 'focus_y', 'zoom', 'caption', 'alt'], array_map(static fn (Prop $prop): string => $prop->name, $props));
    }

    public function testTheEditorReadsItsOwnHelpAndTheModelTheInstruction(): void
    {
        foreach ((new ImageSection())->schema()->props as $prop) {
            self::assertNotNull($prop->editorHelp, $prop->name.' has a help written for the editor.');
            self::assertNotSame($prop->help, $prop->editorHelp, $prop->name.': the help of the editor is not the instruction to the model.');
        }
    }

    /**
     * A document stored before the size props existed, read back and written
     * again, carries them with neutral values: it must draw the same markup,
     * byte for byte, as the four strings above.
     */
    public function testASectionWithoutTheSizePropsDrawsTheSameMarkup(): void
    {
        $section = new ImageSection();
        $old = ['image' => 'https://example.com/tarte.jpg', 'alt' => 'Une tarte', 'caption' => 'Sortie du four', 'width' => 'column'];
        $neutral = $old + ['width_px' => 0, 'height_px' => 0, 'fit' => 'cover', 'focus_x' => 50, 'focus_y' => 50, 'zoom' => 100];
        $expected = '<figure class="cms-image cms-image--column" data-cms-sizes="(max-width: 77rem) calc(100vw - 2rem), 75rem"><img class="cms-image__img" src="https://example.com/tarte.jpg" alt="Une tarte" loading="lazy"><figcaption class="cms-image__caption">Sortie du four</figcaption></figure>';

        self::assertSame($expected, $section->render($section->normalizeProps($old), 'fr_FR'));
        self::assertSame($expected, $section->render($section->normalizeProps($neutral), 'fr_FR'));
        self::assertEqualsCanonicalizing($neutral, $section->normalizeProps($old), 'A stored section gets the neutral values of the new props.');
    }

    public function testAFilledBoxKeepsItsProportionsAndTheKeptPart(): void
    {
        self::assertSame(
            '<figure class="cms-image cms-image--custom" style="width:min(960px, 100% - 2rem)" data-cms-sizes="min(960px, calc(100vw - 2rem))"><img class="cms-image__img cms-image__img--cover" src="https://example.com/a.jpg" alt="" loading="lazy" style="aspect-ratio:960/540;object-fit:cover;object-position:35% 20%" data-cms-crop="35,20,150,960,540"></figure>',
            $this->drawn(['width' => 'custom', 'width_px' => 960, 'height_px' => 540, 'fit' => 'cover', 'focus_x' => 35, 'focus_y' => 20, 'zoom' => 150]),
        );
    }

    public function testAContainedBoxShowsTheWholePictureAndDeclaresItsHeight(): void
    {
        self::assertSame(
            '<figure class="cms-image cms-image--custom" style="width:min(960px, 100% - 2rem)" data-cms-sizes="min(960px, calc(100vw - 2rem))" data-cms-height="540px"><img class="cms-image__img cms-image__img--contain" src="https://example.com/a.jpg" alt="" loading="lazy" style="aspect-ratio:960/540;object-fit:contain"></figure>',
            $this->drawn(['width' => 'custom', 'width_px' => 960, 'height_px' => 540, 'fit' => 'contain']),
        );
    }

    public function testACustomWidthAloneKeepsTheHeightOfThePicture(): void
    {
        self::assertSame(
            '<figure class="cms-image cms-image--custom" style="width:min(600px, 100% - 2rem)" data-cms-sizes="min(600px, calc(100vw - 2rem))"><img class="cms-image__img" src="https://example.com/a.jpg" alt="" loading="lazy"></figure>',
            $this->drawn(['width' => 'custom', 'width_px' => 600, 'height_px' => 0, 'focus_x' => 10]),
            'No height, no frame: neither a ratio nor a framing is written.',
        );
    }

    public function testACustomWidthOfZeroIsDrawnNarrow(): void
    {
        self::assertSame($this->drawn(['width' => 'narrow']), $this->drawn(['width' => 'custom', 'width_px' => 0, 'height_px' => 300]));
    }

    public function testAHeightIsIgnoredWithAPresetWidth(): void
    {
        self::assertSame($this->drawn(['width' => 'column']), $this->drawn(['width' => 'column', 'width_px' => 900, 'height_px' => 300, 'fit' => 'contain']));
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('refusedSizes')]
    public function testASizeOutOfItsBoundsIsRefused(array $props): void
    {
        $this->expectException(InvalidDocumentException::class);

        (new ImageSection())->normalizeProps($props + ['image' => 'https://example.com/a.jpg', 'width' => 'custom']);
    }

    /**
     * @return iterable<string, array{0: array<string, int>}>
     */
    public static function refusedSizes(): iterable
    {
        yield 'a width under 160' => [['width_px' => 159]];
        yield 'a width over 2400' => [['width_px' => 2401]];
        yield 'a height under 80' => [['width_px' => 400, 'height_px' => 79]];
        yield 'a height over 2000' => [['width_px' => 2000, 'height_px' => 2001]];
        yield 'a box wider than 4:1' => [['width_px' => 1000, 'height_px' => 249]];
        yield 'a box taller than 1:4' => [['width_px' => 200, 'height_px' => 801]];
        yield 'a zoom off the steps of 10' => [['width_px' => 400, 'height_px' => 300, 'zoom' => 155]];
        yield 'a zoom under 100' => [['zoom' => 90]];
        yield 'a zoom over 400' => [['zoom' => 410]];
        yield 'a focus over 100' => [['focus_x' => 101]];
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        $section = new ImageSection();

        foreach ([[160, 80], [2400, 600], [500, 2000], [800, 200], [2400, 0], [0, 0]] as [$width, $height]) {
            $props = $section->normalizeProps(['image' => 'https://example.com/a.jpg', 'width' => 'custom', 'width_px' => $width, 'height_px' => $height, 'zoom' => 400]);

            self::assertSame([$width, $height], [$props['width_px'], $props['height_px']]);
        }
    }

    public function testTheModelIsNeverOfferedASizeNorACrop(): void
    {
        $schema = (new ImageSection())->schema()->toJsonSchema();
        $properties = (array) $schema['properties'];

        self::assertSame(['image', 'width', 'caption', 'alt'], array_keys($properties));
        self::assertSame(['narrow', 'column', 'full'], $properties['width']['enum']);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function drawn(array $props): string
    {
        $section = new ImageSection();

        return $section->render($section->normalizeProps($props + ['image' => 'https://example.com/a.jpg']), 'fr_FR');
    }
}
