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
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionAppearance;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\SectionDefinitionInterface;
use TheliaCMS\Content\TranslatableWords;
use TheliaCMS\Generation\TranslationGraft;

/**
 * What a translation depends on in its source is the words of each section:
 * the fingerprint moves when one of them does, and for nothing else.
 */
final class TranslatableWordsTest extends TestCase
{
    public function testAChangeOfTextChangesOnlyTheHashOfItsSection(): void
    {
        $before = $this->fingerprint(new Document([$this->offer('one', 'Notre offre'), $this->offer('two', 'Nos tarifs')]));
        $after = $this->fingerprint(new Document([$this->offer('one', 'Notre nouvelle offre'), $this->offer('two', 'Nos tarifs')]));

        self::assertNotSame($before['one'], $after['one']);
        self::assertSame($before['two'], $after['two']);
        self::assertSame(['one', 'two'], array_keys($after));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $after['one']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function whatIsNotWords(): iterable
    {
        yield 'an image' => ['image', 99];
        yield 'a link' => ['link', '/autre'];
        yield 'a number' => ['places', 40];
        yield 'a switch' => ['featured', false];
        yield 'a choice' => ['tone', 'cold'];
        yield 'a reference' => ['product', 70];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('whatIsNotWords')]
    public function testAnImageALinkANumberASwitchAChoiceOrAReferenceChangesNothing(string $prop, mixed $value): void
    {
        $original = $this->offer('one', 'Notre offre');
        $changed = new Section('one', 'Test:Rich', [...$original->props, $prop => $value]);

        self::assertNotSame($original->props, $changed->props, 'The case changes something.');
        self::assertSame($this->fingerprint(new Document([$original])), $this->fingerprint(new Document([$changed])));
    }

    public function testTheAppearanceOfASectionChangesNothing(): void
    {
        $original = $this->offer('one', 'Notre offre');

        self::assertSame(
            $this->fingerprint(new Document([$original])),
            $this->fingerprint(new Document([$original->withAppearance(new SectionAppearance('#112233', padding: 'large'))])),
        );
    }

    public function testTheWordsOfARowOfAListAreCounted(): void
    {
        $before = $this->fingerprint(new Document([$this->offer('one', 'Notre offre', rows: [['label' => 'Rapide', 'image' => 3], ['label' => 'Sûr', 'image' => 4]])]));
        $reworded = $this->fingerprint(new Document([$this->offer('one', 'Notre offre', rows: [['label' => 'Rapide', 'image' => 3], ['label' => 'Très sûr', 'image' => 4]])]));
        $rePictured = $this->fingerprint(new Document([$this->offer('one', 'Notre offre', rows: [['label' => 'Rapide', 'image' => 30], ['label' => 'Sûr', 'image' => 40]])]));
        $longer = $this->fingerprint(new Document([$this->offer('one', 'Notre offre', rows: [['label' => 'Rapide', 'image' => 3], ['label' => 'Sûr', 'image' => 4], ['label' => 'Simple', 'image' => 5]])]));

        self::assertNotSame($before, $reworded, 'A word of a row is a word of the section.');
        self::assertSame($before, $rePictured, 'The image of a row is not.');
        self::assertNotSame($before, $longer, 'A row more is a row to translate.');
    }

    public function testTheWordsOfAListOfTextsAndOfAJsonValueAreCounted(): void
    {
        $before = $this->fingerprint(new Document([$this->offer('one', 'Notre offre', tags: ['un', 'deux'], data: ['label' => 'Bonjour'])]));

        self::assertNotSame($before, $this->fingerprint(new Document([$this->offer('one', 'Notre offre', tags: ['un', 'trois'], data: ['label' => 'Bonjour'])])));
        self::assertNotSame($before, $this->fingerprint(new Document([$this->offer('one', 'Notre offre', tags: ['un', 'deux'], data: ['label' => 'Salut'])])));
    }

    public function testTheOrderOfTheKeysOfAValueChangesNothing(): void
    {
        $one = $this->fingerprint(new Document([$this->offer('one', 'Notre offre', data: ['a' => 1, 'b' => ['c' => 2, 'd' => 3]])]));
        $other = $this->fingerprint(new Document([$this->offer('one', 'Notre offre', data: ['b' => ['d' => 3, 'c' => 2], 'a' => 1])]));

        self::assertSame($one, $other);
    }

    public function testASectionAddedOrRemovedIsADifferenceAndMovingSectionsIsNot(): void
    {
        $words = $this->words();
        $recorded = $words->fingerprint(new Document([$this->offer('one', 'Un'), $this->offer('two', 'Deux')]));

        $added = $words->fingerprint(new Document([$this->offer('one', 'Un'), $this->offer('two', 'Deux'), $this->offer('three', 'Trois')]));
        self::assertSame(['changed' => ['three'], 'removed' => []], $words->differences($recorded, $added));

        $removed = $words->fingerprint(new Document([$this->offer('one', 'Un')]));
        self::assertSame(['changed' => [], 'removed' => ['two']], $words->differences($recorded, $removed));

        $moved = $words->fingerprint(new Document([$this->offer('two', 'Deux'), $this->offer('one', 'Un')]));
        self::assertSame(['changed' => [], 'removed' => []], $words->differences($recorded, $moved), 'A move changes no word.');

        $reworded = $words->fingerprint(new Document([$this->offer('one', 'Un'), $this->offer('two', 'Deux, autrement')]));
        self::assertSame(['changed' => ['two'], 'removed' => []], $words->differences($recorded, $reworded));
    }

    public function testAComponentTheCatalogueNoLongerHoldsFallsBackToAllItsProps(): void
    {
        $before = $this->fingerprint(new Document([new Section('gone', 'Site:Removed', ['title' => 'Un', 'image' => 3])]));

        self::assertNotSame($before, $this->fingerprint(new Document([new Section('gone', 'Site:Removed', ['title' => 'Un', 'image' => 4])])), 'With no schema, nothing can be told from words.');
        self::assertSame($before, $this->fingerprint(new Document([new Section('gone', 'Site:Removed', ['image' => 3, 'title' => 'Un'])])));
    }

    public function testTheRuleOfWordsIsTheOneOfTheGraftOnEveryTypeOfProp(): void
    {
        $isWords = new \ReflectionMethod(TranslationGraft::class, 'isWords');

        foreach (PropType::cases() as $type) {
            $prop = new Prop('value', $type, 'Value', choices: PropType::Choice === $type ? ['a' => 'A'] : [], items: PropType::Items === $type ? [Prop::text('label', 'Label')] : []);

            self::assertSame($isWords->invoke(null, $prop), TranslatableWords::holdsWords($prop), \sprintf('The translation writes a "%s" prop or not, and the fingerprint must say the same.', $type->value));
        }
    }

    /**
     * @return array<string, string>
     */
    private function fingerprint(Document $document): array
    {
        return $this->words()->fingerprint($document);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $tags
     * @param array<string, mixed>       $data
     */
    private function offer(string $id, string $title, array $rows = [], array $tags = [], array $data = []): Section
    {
        return new Section($id, 'Test:Rich', [
            'title' => $title,
            'image' => 12,
            'link' => '/tarifs',
            'places' => 4,
            'featured' => true,
            'tone' => 'warm',
            'data' => $data,
            'product' => 7,
            'tags' => $tags,
            'rows' => $rows,
        ]);
    }

    private function words(): TranslatableWords
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
                    ]),
                ]);
            }

            public function sample(): array
            {
                return [];
            }

            public function normalizeProps(array $props): array
            {
                return $props;
            }

            public function render(array $props, string $locale): string
            {
                return '';
            }
        };

        return new TranslatableWords(new SectionCatalog([$definition]));
    }
}
