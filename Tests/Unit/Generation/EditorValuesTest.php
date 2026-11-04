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

namespace TheliaCMS\Tests\Unit\Generation;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\ColumnsSection;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\ImageSection;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Generation\EditorValues;

/**
 * What only the editor sets in a section is hidden from the model and given
 * back to the section its answer replaces, as long as the picture is the same.
 */
final class EditorValuesTest extends TestCase
{
    private const array FRAMED = [
        'image' => 'https://example.com/tart.jpg',
        'alt' => 'A tart',
        'caption' => 'Fresh',
        'width' => 'custom',
        'width_px' => 960,
        'height_px' => 540,
        'fit' => 'contain',
        'focus_x' => 35,
        'focus_y' => 20,
        'zoom' => 150,
    ];

    public function testTheDocumentTheModelReadsCarriesNoSizeNoFramingAndNoCustomWidth(): void
    {
        $document = new Document([new Section('photo', ImageSection::NAME, self::FRAMED, new SectionAppearance('#112233')), new Section('intro', RichTextSection::NAME, ['html' => '<p>Hi</p>'])]);

        $shown = $this->values()->forModel($document);

        self::assertSame(
            ['image' => 'https://example.com/tart.jpg', 'alt' => 'A tart', 'caption' => 'Fresh', 'width' => 'narrow'],
            $shown->sections[0]->props,
            'Only what the model may write is left, and the width the editor typed reads as the default.',
        );
        self::assertSame('#112233', $shown->sections[0]->appearance->background);
        self::assertEquals($document->sections[1], $shown->sections[1]);

        foreach (['width_px', 'height_px', 'fit', 'focus_x', 'focus_y', 'zoom', 'custom'] as $word) {
            self::assertStringNotContainsString($word, $shown->toJson());
        }

        self::assertSame(960, $document->sections[0]->props['width_px'], 'The stored document is not touched.');
    }

    public function testAPresetWidthIsShownAsItIs(): void
    {
        $shown = $this->values()->forModel(new Document([new Section('photo', ImageSection::NAME, [...self::FRAMED, 'width' => 'full'])]));

        self::assertSame('full', $shown->sections[0]->props['width']);
    }

    public function testTheFramingComesBackWhenTheSectionKeepsItsPictureAndItsComponent(): void
    {
        $replaced = new Document([new Section('photo', ImageSection::NAME, self::FRAMED)]);
        $answer = new Document([new Section('photo', ImageSection::NAME, ['image' => 'https://example.com/tart.jpg', 'alt' => 'A tart', 'caption' => 'Just out of the oven', 'width' => 'narrow'])]);

        $props = $this->values()->restore($replaced, $answer)->sections[0]->props;

        self::assertSame('Just out of the oven', $props['caption'], 'The words are the answer\'s.');
        self::assertSame(
            ['custom', 960, 540, 'contain', 35, 20, 150],
            [$props['width'], $props['width_px'], $props['height_px'], $props['fit'], $props['focus_x'], $props['focus_y'], $props['zoom']],
        );
    }

    public function testAnotherPresetWidthIsARequestAndWinsOverTheCustomWidth(): void
    {
        $replaced = new Document([new Section('photo', ImageSection::NAME, self::FRAMED)]);
        $answer = new Document([new Section('photo', ImageSection::NAME, ['image' => 'https://example.com/tart.jpg', 'width' => 'full'])]);

        $props = $this->values()->restore($replaced, $answer)->sections[0]->props;

        self::assertSame('full', $props['width']);
        self::assertSame(960, $props['width_px'], 'The size stays in the section, ready if the editor goes back to a size in pixels.');
    }

    public function testAnotherPictureTakesTheDefaultsAndNeverACustomWidth(): void
    {
        $replaced = new Document([new Section('photo', ImageSection::NAME, self::FRAMED)]);
        $answer = new Document([new Section('photo', ImageSection::NAME, ['image' => 'https://example.com/other.jpg', 'width' => 'custom', 'width_px' => 400, 'zoom' => 300])]);

        $props = $this->values()->restore($replaced, $answer)->sections[0]->props;

        self::assertSame(['narrow', 0, 0, 'cover', 50, 50, 100], [$props['width'], $props['width_px'], $props['height_px'], $props['fit'], $props['focus_x'], $props['focus_y'], $props['zoom']]);
    }

    public function testASectionThatReplacesNothingOrAnotherComponentTakesTheDefaults(): void
    {
        $replaced = new Document([new Section('photo', RichTextSection::NAME, ['html' => '<p>Hi</p>'])]);
        $answer = new Document([
            new Section('photo', ImageSection::NAME, ['image' => 'https://example.com/tart.jpg']),
            new Section('new', ImageSection::NAME, ['image' => 'https://example.com/tart.jpg']),
        ]);

        foreach ($this->values()->restore($replaced, $answer)->sections as $section) {
            self::assertSame([0, 50, 100], [$section->props['height_px'], $section->props['focus_x'], $section->props['zoom']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function logos(): array
    {
        return [
            'title' => 'Partners',
            'proportions' => '1:1',
            'columns' => [
                ['image' => 'https://example.com/a.png', 'title' => 'A', 'text' => 'One', 'focus_x' => 10, 'focus_y' => 90, 'zoom' => 200],
                ['image' => 'https://example.com/b.png', 'title' => 'B', 'text' => 'Two', 'focus_x' => 80, 'focus_y' => 20, 'zoom' => 120],
            ],
        ];
    }

    public function testTheKeptPartOfTheRowsOfAListIsLeftOutOfTheDraftTheModelReads(): void
    {
        $document = new Document([new Section('partners', ColumnsSection::NAME, self::logos())]);

        $shown = $this->values()->forModel($document);

        self::assertSame(
            ['title' => 'Partners', 'columns' => [
                ['image' => 'https://example.com/a.png', 'title' => 'A', 'text' => 'One'],
                ['image' => 'https://example.com/b.png', 'title' => 'B', 'text' => 'Two'],
            ]],
            $shown->sections[0]->props,
        );

        foreach (['proportions', 'focus_x', 'focus_y', 'zoom'] as $word) {
            self::assertStringNotContainsString($word, $shown->toJson());
        }

        self::assertSame(200, $document->sections[0]->props['columns'][0]['zoom'], 'The stored document is not touched.');
    }

    public function testEachRowGetsItsKeptPartBackWhenItsPictureIsTheSame(): void
    {
        $replaced = new Document([new Section('partners', ColumnsSection::NAME, self::logos())]);
        $answer = new Document([new Section('partners', ColumnsSection::NAME, ['title' => 'Our partners', 'columns' => [
            ['image' => 'https://example.com/a.png', 'title' => 'A', 'text' => 'Reworded'],
            ['image' => 'https://example.com/b.png', 'title' => 'B', 'text' => 'Reworded too'],
        ]])]);

        $props = $this->values()->restore($replaced, $answer)->sections[0]->props;

        self::assertSame('1:1', $props['proportions']);
        self::assertSame('Reworded', $props['columns'][0]['text'], 'The words are the answer\'s.');
        self::assertSame([10, 90, 200], [$props['columns'][0]['focus_x'], $props['columns'][0]['focus_y'], $props['columns'][0]['zoom']]);
        self::assertSame([80, 20, 120], [$props['columns'][1]['focus_x'], $props['columns'][1]['focus_y'], $props['columns'][1]['zoom']]);
    }

    public function testARowWhosePictureChangedTakesTheDefaultsAndTheOthersKeepTheirs(): void
    {
        $replaced = new Document([new Section('partners', ColumnsSection::NAME, self::logos())]);
        $answer = new Document([new Section('partners', ColumnsSection::NAME, ['columns' => [
            ['image' => 'https://example.com/other.png', 'text' => 'One', 'focus_x' => 5, 'zoom' => 400],
            ['image' => 'https://example.com/b.png', 'text' => 'Two'],
            ['image' => 'https://example.com/c.png', 'text' => 'A third column the section did not have'],
        ]])]);

        $columns = $this->values()->restore($replaced, $answer)->sections[0]->props['columns'];

        self::assertSame([50, 50, 100], [$columns[0]['focus_x'], $columns[0]['focus_y'], $columns[0]['zoom']], 'Another picture, and what the answer holds of the editor\'s is reset.');
        self::assertSame([80, 20, 120], [$columns[1]['focus_x'], $columns[1]['focus_y'], $columns[1]['zoom']]);
        self::assertSame([50, 50, 100], [$columns[2]['focus_x'], $columns[2]['focus_y'], $columns[2]['zoom']], 'A row the replaced list did not have.');
    }

    public function testTheRowsOfAnotherComponentTakeTheDefaults(): void
    {
        $replaced = new Document([new Section('partners', RichTextSection::NAME, ['html' => '<p>Hi</p>'])]);
        $answer = new Document([new Section('partners', ColumnsSection::NAME, ['columns' => [['image' => null, 'text' => 'One'], ['image' => null, 'text' => 'Two']]])]);

        $props = $this->values()->restore($replaced, $answer)->sections[0]->props;

        self::assertSame('band', $props['proportions']);
        self::assertSame([50, 50, 100], [$props['columns'][0]['focus_x'], $props['columns'][0]['focus_y'], $props['columns'][0]['zoom']]);
    }

    private function values(): EditorValues
    {
        return new EditorValues(new SectionCatalog([new ImageSection(), new RichTextSection(), new ColumnsSection()]));
    }
}
