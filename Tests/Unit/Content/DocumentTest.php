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
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\RichTextSection;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;

/**
 * The stored form of a page: what goes into the column must come back out the
 * same, and a column holding something else must be refused rather than read
 * as an empty page.
 */
final class DocumentTest extends TestCase
{
    public function testARichTextDocumentHoldsOneSectionCarryingTheMarkup(): void
    {
        $document = Document::richText('<h1>Bonjour</h1>');

        self::assertCount(1, $document->sections);
        self::assertSame(RichTextSection::NAME, $document->sections[0]->component);
        self::assertSame(['html' => '<h1>Bonjour</h1>'], $document->sections[0]->props);
        self::assertFalse($document->isEmpty());
    }

    public function testADocumentSurvivesTheRoundTripThroughJson(): void
    {
        $document = new Document([
            new Section('hero', FakeSection::NAME, ['title' => 'Nos studios 📷', 'image' => 12, 'url' => '/image-library/12/full/960/0/default.webp']),
            new Section('intro', RichTextSection::NAME, ['html' => '<p>Texte</p>']),
        ]);

        $json = $document->toJson();
        $read = Document::fromJson($json);

        self::assertNotNull($read);
        self::assertSame($document->toArray(), $read->toArray());
        self::assertStringContainsString('/image-library/12/', $json, 'Image addresses are stored the way the HTML spells them, so usage finders see them.');
        self::assertStringContainsString('📷', $json, 'Characters are stored as themselves, not as \u escapes.');
        self::assertSame(Document::VERSION, $read->toArray()['version']);
    }

    public function testASectionMovesOnePlaceAndStopsAtEitherEnd(): void
    {
        $document = new Document([
            new Section('a', RichTextSection::NAME, []),
            new Section('b', RichTextSection::NAME, []),
            new Section('c', RichTextSection::NAME, []),
        ]);
        $ids = static fn (Document $moved): array => array_map(static fn (Section $section): string => $section->id, $moved->sections);

        self::assertSame(['a', 'c', 'b'], $ids($document->withSectionMoved('c', -1)));
        self::assertSame(['b', 'a', 'c'], $ids($document->withSectionMoved('a', 1)));
        self::assertSame($document, $document->withSectionMoved('a', -1), 'The first section stays first.');
        self::assertSame($document, $document->withSectionMoved('c', 1), 'The last section stays last.');
        self::assertSame($document, $document->withSectionMoved('gone', 1));
    }

    public function testASectionDroppedAtARankTakesItAndTheOthersCloseUp(): void
    {
        $document = new Document([
            new Section('a', RichTextSection::NAME, []),
            new Section('b', RichTextSection::NAME, []),
            new Section('c', RichTextSection::NAME, []),
            new Section('d', RichTextSection::NAME, []),
        ]);
        $ids = static fn (Document $moved): array => array_map(static fn (Section $section): string => $section->id, $moved->sections);

        self::assertSame(['b', 'c', 'a', 'd'], $ids($document->withSectionAt('a', 2)));
        self::assertSame(['d', 'a', 'b', 'c'], $ids($document->withSectionAt('d', 0)));
        self::assertSame(['a', 'c', 'd', 'b'], $ids($document->withSectionAt('b', 99)), 'Past the end is last.');
        self::assertSame($document, $document->withSectionAt('c', 2), 'Dropped where it was.');
        self::assertSame($document, $document->withSectionAt('gone', 0));
    }

    public function testASectionPutAfterAnotherTakesThePlaceRightBehindIt(): void
    {
        $document = new Document([
            new Section('a', RichTextSection::NAME, []),
            new Section('b', RichTextSection::NAME, []),
            new Section('c', RichTextSection::NAME, []),
        ]);
        $copy = new Section('a-2', RichTextSection::NAME, []);
        $ids = static fn (Document $changed): array => array_map(static fn (Section $section): string => $section->id, $changed->sections);

        self::assertSame(['a', 'a-2', 'b', 'c'], $ids($document->withSectionAfter('a', $copy)));
        self::assertSame(['a', 'b', 'c', 'a-2'], $ids($document->withSectionAfter('c', $copy)), 'After the last one is last.');
        self::assertSame($document, $document->withSectionAfter('gone', $copy));
    }

    public function testAnAddedSectionIsGivenAnIdNoOtherHolds(): void
    {
        $document = new Document([
            new Section('crossselling', 'Layouts:CrossSelling:Base', []),
            new Section('richtext', RichTextSection::NAME, []),
            new Section('richtext-2', RichTextSection::NAME, []),
        ]);

        self::assertSame('crossselling-2', $document->freeSectionId('Layouts:CrossSelling:Base'));
        self::assertSame('richtext-3', $document->freeSectionId(RichTextSection::NAME));
        self::assertSame('cms-form', $document->freeSectionId('Partial:cms-form'));
        self::assertSame('subheader-alt', $document->freeSectionId('Layouts:Subheader:Alt'), 'A variant other than Base says something.');
        self::assertSame('section', (new Document([]))->freeSectionId(':::'));

        $added = $document->withSectionAppended(new Section('cms-form', 'Partial:cms-form', []));
        self::assertSame('cms-form', $added->sections[3]->id);
        self::assertCount(3, $document->sections, 'The document added to is left as it is.');
    }

    public function testAnEmptyColumnIsNoDocument(): void
    {
        self::assertNull(Document::fromJson(null));
        self::assertNull(Document::fromJson(''));
        self::assertNull(Document::fromJson('   '));
    }

    public function testAColumnHoldingSomethingElseIsRefused(): void
    {
        $this->expectException(InvalidDocumentException::class);

        Document::fromJson('{"pages":[]}');
    }

    public function testBrokenJsonIsRefused(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('not valid JSON');

        Document::fromJson('{"version":1,"sections":[');
    }

    public function testADocumentFromALaterFormatIsRefused(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('format version 2');

        Document::fromArray(['version' => 2, 'sections' => []]);
    }

    public function testTheAppearanceOfASectionSurvivesTheRoundTripAndAnAbsentOneIsNotWritten(): void
    {
        $document = new Document([
            new Section('selection', RichTextSection::NAME, ['html' => '<p>Texte</p>'], new SectionAppearance(background: '#f8d7d7')),
            new Section('intro', RichTextSection::NAME, ['html' => '<p>Texte</p>']),
        ]);

        $read = Document::fromJson($document->toJson());

        self::assertSame('#f8d7d7', $read?->section('selection')?->appearance->background);
        self::assertSame(['id' => 'intro', 'component' => RichTextSection::NAME, 'props' => ['html' => '<p>Texte</p>']], $document->toArray()['sections'][1], 'A section without an appearance is stored as before appearances existed.');
        self::assertSame('#f8d7d7', $read?->section('selection')?->withProps(['html' => '<p>Autre</p>'])->appearance->background, 'Changing the props keeps the appearance.');
    }

    public function testAnAppearanceThatIsNotOneIsReportedWithTheOtherProblems(): void
    {
        try {
            Document::fromArray(['version' => 1, 'sections' => [
                ['id' => 'a', 'component' => FakeSection::NAME, 'props' => [], 'appearance' => 'rouge'],
                ['id' => 'b', 'component' => FakeSection::NAME, 'props' => [], 'appearance' => ['background' => 'rouge pastel']],
            ]]);
            self::fail('The document should have been refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertSame([
                'Section 1 has an appearance that is not an object.',
                'Section 2: The background of the appearance is not a colour written #rrggbb.',
            ], $exception->problems);
        }
    }

    public function testEveryMalformedSectionIsReportedAtOnce(): void
    {
        try {
            Document::fromArray(['version' => 1, 'sections' => [
                ['component' => FakeSection::NAME, 'props' => []],
                ['id' => 'b', 'props' => []],
                ['id' => 'c', 'component' => FakeSection::NAME, 'props' => ['a', 'b']],
                'not a section',
            ]]);
            self::fail('The document should have been refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertCount(4, $exception->problems);
            self::assertStringContainsString('Section 1 has no id', $exception->problems[0]);
            self::assertStringContainsString('Section 2 names no component', $exception->problems[1]);
            self::assertStringContainsString('Section 3 has props that are not an object', $exception->problems[2]);
            self::assertStringContainsString('Section 4 is not an object', $exception->problems[3]);
        }
    }

    public function testASectionIsReplacedByIdAndTheOthersAreLeftAlone(): void
    {
        $document = new Document([
            new Section('hero', FakeSection::NAME, ['title' => 'Avant']),
            new Section('intro', RichTextSection::NAME, ['html' => '<p>Texte</p>']),
        ]);

        $changed = $document->withSection(new Section('hero', FakeSection::NAME, ['title' => 'Après']));

        self::assertSame('Après', $changed->section('hero')?->props['title']);
        self::assertSame('<p>Texte</p>', $changed->section('intro')?->props['html']);
        self::assertSame('Avant', $document->section('hero')?->props['title'], 'The original is not changed.');
        self::assertNull($changed->section('missing'));
    }
}
