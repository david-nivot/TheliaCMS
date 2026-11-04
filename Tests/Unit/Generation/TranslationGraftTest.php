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
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Generation\TranslationGraft;

/**
 * A translation is written onto its source: the words are the answer's, every
 * other value is the source's, whatever the model wrote.
 */
final class TranslationGraftTest extends TestCase
{
    public function testOnlyTheWordsComeFromTheAnswerAndEveryOtherPropFromTheSource(): void
    {
        $source = new Document([new Section('offer', 'Test:Rich', [
            'title' => 'Notre offre',
            'body' => '<p>Du conseil.</p>',
            'image' => 12,
            'link' => '/tarifs',
            'places' => 4,
            'featured' => true,
            'tone' => 'warm',
            'data' => ['label' => 'Bonjour'],
            'product' => 7,
            'tags' => ['un', 'deux'],
            'rows' => [
                ['label' => 'Rapide', 'image' => 3, 'page' => 5, 'link' => '/a'],
                ['label' => 'Sûr', 'image' => 4, 'page' => 6, 'link' => '/b'],
            ],
        ], new SectionAppearance('#112233'))]);
        $answer = new Document([new Section('offer', 'Test:Rich', [
            'title' => 'Our offer',
            'body' => '<p>Advice.</p>',
            'image' => 99,
            'link' => '/invented',
            'places' => 40,
            'featured' => false,
            'tone' => 'cold',
            'data' => ['label' => 'Hello'],
            'product' => 70,
            'tags' => ['one', 'two'],
            'rows' => [
                ['label' => 'Fast', 'image' => 30, 'page' => 50, 'link' => '/x'],
                ['label' => 'Safe', 'image' => 40, 'page' => 60, 'link' => '/y'],
            ],
        ], new SectionAppearance('#ffffff'))]);

        $section = $this->graft()->graft($source, $answer)->sections[0];

        self::assertSame([
            'title' => 'Our offer',
            'body' => '<p>Advice.</p>',
            'image' => 12,
            'link' => '/tarifs',
            'places' => 4,
            'featured' => true,
            'tone' => 'warm',
            'data' => ['label' => 'Hello'],
            'product' => 7,
            'tags' => ['one', 'two'],
            'rows' => [
                ['label' => 'Fast', 'image' => 3, 'page' => 5, 'link' => '/a'],
                ['label' => 'Safe', 'image' => 4, 'page' => 6, 'link' => '/b'],
            ],
        ], $section->props);
        self::assertSame('#112233', $section->appearance->background, 'The appearance is the one of the source.');
    }

    public function testASectionThatChangedOfIdOrComponentOrPlaceIsSentBack(): void
    {
        $source = new Document([new Section('one', 'Test:Rich', ['title' => 'Un']), new Section('two', 'Test:Rich', ['title' => 'Deux'])]);

        foreach ([
            'another id' => [new Section('one', 'Test:Rich', ['title' => 'One']), new Section('second', 'Test:Rich', ['title' => 'Two'])],
            'swapped' => [new Section('two', 'Test:Rich', ['title' => 'Two']), new Section('one', 'Test:Rich', ['title' => 'One'])],
            'another component' => [new Section('one', 'Test:Rich', ['title' => 'One']), new Section('two', 'Test:Other', ['title' => 'Two'])],
        ] as $case => $sections) {
            try {
                $this->graft()->graft($source, new Document($sections));
                self::fail('The difference "'.$case.'" should be refused.');
            } catch (InvalidDocumentException $exception) {
                self::assertNotSame([], $exception->problems, $case);
            }
        }

        try {
            $this->graft()->graft($source, new Document([new Section('one', 'Test:Rich', ['title' => 'One'])]));
            self::fail('A missing section should be refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertStringContainsString('holds 1 sections and the source 2', $exception->problems[0]);
        }
    }

    public function testAListThatLostAnItemIsSentBack(): void
    {
        $source = new Document([new Section('offer', 'Test:Rich', ['title' => 'Offre', 'rows' => [['label' => 'A', 'image' => 1, 'page' => 1, 'link' => '/a'], ['label' => 'B', 'image' => 2, 'page' => 2, 'link' => '/b']]])]);
        $answer = new Document([new Section('offer', 'Test:Rich', ['title' => 'Offer', 'rows' => [['label' => 'A', 'image' => 1, 'page' => 1, 'link' => '/a']]])]);

        try {
            $this->graft()->graft($source, $answer);
            self::fail('A list with fewer items should be refused.');
        } catch (InvalidDocumentException $exception) {
            self::assertStringContainsString('"rows" holds 2 items in the source and 1 in the translation', $exception->problems[0]);
        }
    }

    private function graft(): TranslationGraft
    {
        $definition = new class implements SectionDefinitionInterface {
            public function name(): string
            {
                return 'Test:Rich';
            }

            public function label(): string
            {
                return 'Rich';
            }

            public function description(): string
            {
                return 'Every kind of prop.';
            }

            public function schema(): PropSchema
            {
                return new PropSchema([
                    Prop::text('title', 'Title'),
                    Prop::html('body', 'Body'),
                    Prop::image('image', 'Image'),
                    Prop::url('link', 'Link'),
                    Prop::integer('places', 'Places', 1, 0, 100),
                    Prop::boolean('featured', 'Featured'),
                    Prop::choice('tone', 'Tone', ['warm' => 'Warm', 'cold' => 'Cold'], 'warm'),
                    Prop::json('data', 'Data'),
                    Prop::reference('product', 'Product', 'a product', false),
                    Prop::items('tags', 'Tags', [Prop::text('value', 'Value')], scalarItems: true),
                    Prop::items('rows', 'Rows', [
                        Prop::text('label', 'Label'),
                        Prop::image('image', 'Image'),
                        Prop::reference('page', 'Page', 'a page'),
                        Prop::url('link', 'Link'),
                    ]),
                ]);
            }

            public function sample(): array
            {
                return [];
            }

            public function normalizeProps(array $props): array
            {
                return $this->schema()->normalize($props);
            }

            public function render(array $props, string $locale): string
            {
                return '';
            }
        };

        return new TranslationGraft(new SectionCatalog([$definition]));
    }
}
