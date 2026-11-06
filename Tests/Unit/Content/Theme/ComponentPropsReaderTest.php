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

namespace TheliaCMS\Tests\Unit\Content\Theme;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Theme\ComponentPropsReader;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * What a component of the theme may ask for, read off its template: the
 * records of the site by their id, pictures of the library, and lists of
 * records, next to the scalars.
 */
final class ComponentPropsReaderTest extends TestCase
{
    public function testAPropMayNameARecordOfTheSiteOrAPictureOfTheLibrary(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @description A card of one product. #}
            {# @prop productId product The product shown #}
            {# @prop poster image|null A picture of the library over it #}
            {# @prop categoryId category|null Where the link goes #}
            {% props productId, poster = null, categoryId = null, title = '' %}
            TWIG);
        $props = $this->byName($read);

        self::assertSame('A card of one product.', $read['description']);
        self::assertSame(PropType::Reference, $props['product_id']->type);
        self::assertTrue($props['product_id']->required);
        self::assertSame('/admin.cms.partials.sources.products', $props['product_id']->picker);
        self::assertTrue($props['product_id']->searchable, 'Products are searched, not listed.');
        self::assertSame(PropType::Image, $props['poster']->type);
        self::assertFalse($props['poster']->required);
        self::assertSame(PropType::Reference, $props['category_id']->type);
        self::assertFalse($props['category_id']->required);
        self::assertSame(PropType::Text, $props['title']->type);
        self::assertSame([], $read['id_lists']);
    }

    public function testAPropMayBeAVideoOfTheLibraryAtTheTopOrInTheRowsOfAList(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop title string The heading #}
            {# @prop clip video The video of the section #}
            {# @prop teaser video|null A short video, when there is one #}
            {# @prop videos list<{title: string, video: video, image: image|null, trailer: video|null}> The videos of the gallery #}
            {% props title, teaser = null, videos = [] %}
            TWIG);
        $props = $this->byName($read);

        self::assertSame(PropType::Video, $props['clip']->type);
        self::assertTrue($props['clip']->required);
        self::assertSame(PropType::Video, $props['teaser']->type);
        self::assertFalse($props['teaser']->required, 'video|null is optional.');
        self::assertSame([], $read['id_lists'], 'A video is no list of records.');

        $fields = [];

        foreach ($props['videos']->items as $item) {
            $fields[$item->name] = $item;
        }

        self::assertSame(PropType::Items, $props['videos']->type);
        self::assertSame(['title', 'video', 'image', 'trailer'], array_keys($fields));
        self::assertSame(PropType::Video, $fields['video']->type);
        self::assertTrue($fields['video']->required);
        self::assertSame(PropType::Image, $fields['image']->type);
        self::assertSame(PropType::Video, $fields['trailer']->type);
        self::assertFalse($fields['trailer']->required);
    }

    public function testAListOfRecordsIsRowsOfIdsForTheDocumentAndIsToldApart(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop productIds product[] The products of the row, in order #}
            {# @prop folders list<folder> #}
            {# @prop tags array Free tags #}
            {% props productIds = [], folders = [], tags = [] %}
            TWIG);
        $props = $this->byName($read);

        self::assertSame(PropType::Items, $props['product_ids']->type);
        self::assertSame(12, $props['product_ids']->maxItems);
        self::assertSame('id', $props['product_ids']->items[0]->name);
        self::assertSame(PropType::Reference, $props['product_ids']->items[0]->type);
        self::assertSame('/admin.cms.partials.sources.products', $props['product_ids']->items[0]->picker);
        self::assertSame(PropType::Items, $props['folders']->type);
        self::assertSame(PropType::Json, $props['tags']->type, 'A plain array stays a JSON prop.');
        self::assertSame(['product_ids', 'folders'], $read['id_lists']);
    }

    public function testAPropDocumentedButNotDeclaredIsOneTheClassTakesAndIsOptional(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop title string The heading #}
            {# @prop productIds product[] Picked by hand #}
            {# @prop itemsPerPage int How many #}
            {% props title = '' %}
            TWIG);
        $props = $this->byName($read);

        self::assertSame(['title', 'product_ids', 'items_per_page'], array_keys($props), 'The declared prop first, then what the class takes.');
        self::assertFalse($props['items_per_page']->required);
        self::assertSame(PropType::Integer, $props['items_per_page']->type);
        self::assertSame(['product_ids'], $read['id_lists']);
    }

    public function testARecordTheClassTakesIsRequiredUnlessDocumentedAsNullable(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop productId product The product shown #}
            {# @prop poster image A picture of the library over it #}
            {# @prop categoryId category|null Where the link goes #}
            {# @prop productIds product[] Picked by hand #}
            {# @prop imgWidth int Width of the image #}
            {% props imgWidth = 300 %}
            TWIG);
        $props = $this->byName($read);

        self::assertTrue($props['product_id']->required, 'A card of one product renders empty without it.');
        self::assertTrue($props['poster']->required);
        self::assertFalse($props['category_id']->required, 'Documented as nullable.');
        self::assertSame(0, $props['product_ids']->minItems, 'A list of records may stay empty.');
    }

    public function testAPropDocumentedAsHtmlIsMarkupWithoutALengthLimit(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop title string The heading #}
            {# @prop html html|null The text, as HTML #}
            {# @prop body html The text the class takes #}
            {% props title = '', html = null %}
            TWIG);
        $props = $this->byName($read);

        self::assertSame(PropType::Text, $props['title']->type);
        self::assertSame(PropType::Html, $props['html']->type);
        self::assertFalse($props['html']->required, 'Documented as nullable.');
        self::assertSame(PropType::Html, $props['body']->type);
        self::assertNull($props['html']->max, 'A text of several paragraphs is not cut at 500 characters.');
        self::assertSame(str_repeat('<p>Un paragraphe.</p>', 50), $read['schema']->normalize(['html' => str_repeat('<p>Un paragraphe.</p>', 50)])['html']);
    }

    public function testAListOfObjectsIsRowsOfFieldsTheFormDraws(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop title string The heading #}
            {# @prop ingredients list<{name: string, quantity: string, image: image|null, vegan: bool, unit: 'g'|'cl', count: int, note: html|null}> Items of the recipe #}
            {# @prop extras array<{label: string}>|null More rows #}
            {% props title = '', ingredients = [], extras = null %}
            TWIG);
        $props = $this->byName($read);
        $ingredients = $props['ingredients'];

        self::assertSame(PropType::Items, $ingredients->type);
        self::assertFalse($ingredients->scalarItems);
        self::assertSame(0, $ingredients->minItems);
        self::assertSame(50, $ingredients->maxItems);
        self::assertSame('Items of the recipe', $ingredients->help, 'The description follows a type that holds spaces.');
        self::assertSame(['name', 'quantity', 'image', 'vegan', 'unit', 'count', 'note'], array_map(static fn (Prop $item): string => $item->name, $ingredients->items));

        $fields = [];
        foreach ($ingredients->items as $item) {
            $fields[$item->name] = $item;
        }

        self::assertSame(PropType::Text, $fields['name']->type);
        self::assertSame(500, $fields['name']->max);
        self::assertTrue($fields['name']->required);
        self::assertSame(PropType::Image, $fields['image']->type);
        self::assertFalse($fields['image']->required, '|null makes a field optional.');
        self::assertSame(PropType::Boolean, $fields['vegan']->type);
        self::assertSame(PropType::Choice, $fields['unit']->type);
        self::assertSame(['g' => 'g', 'cl' => 'cl'], $fields['unit']->choices);
        self::assertSame(PropType::Integer, $fields['count']->type);
        self::assertSame(PropType::Html, $fields['note']->type);
        self::assertFalse($fields['note']->required);
        self::assertSame(PropType::Items, $props['extras']->type, 'array<{…}> reads as list<{…}>.');
        self::assertSame([], $read['id_lists'], 'Rows of fields are not lists of ids.');
    }

    public function testARowSpelledAsItsTemplateIsReadUnderTheNamesOfTheSchema(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop slides list<{image: image|null, imageAlt: string|null, buttonLabel: string|null}> The slides #}
            {% props slides = [] %}
            TWIG);
        $slides = $this->byName($read)['slides'];

        self::assertSame(['image', 'image_alt', 'button_label'], array_map(static fn (Prop $item): string => $item->name, $slides->items));
        self::assertSame(
            [['image' => 12, 'image_alt' => 'A field', 'button_label' => 'Read more']],
            $read['schema']->normalize(['slides' => [['image' => '/image-library/12/full/max/0/default.jpg', 'imageAlt' => 'A field', 'buttonLabel' => 'Read more']]])['slides'],
            'A row written as the template spells it keeps its values.',
        );
        self::assertSame(
            'Kept',
            $read['schema']->normalize(['slides' => [['image_alt' => 'Kept', 'imageAlt' => 'Dropped']]])['slides'][0]['image_alt'],
            'The name of the schema wins over the spelling of the template.',
        );
    }

    public function testAListWhoseItemCannotBeHeldByAFormStaysJson(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop nested list<{name: string, tags: list<string>}> A list in a row #}
            {# @prop cards list<{title: string, product: product}> A record in a row #}
            {# @prop objects list<{title: string, meta: array}> A bare array in a row #}
            {# @prop broken list<{title string}> No type #}
            {# @prop plain array Free #}
            {# @prop generic array<string, int> A map #}
            {# @prop iter iterable Whatever #}
            {# @prop obj object Whatever #}
            {# @prop bare list Whatever #}
            {% props nested = [], cards = [], objects = [], broken = [], plain = [], generic = [], iter = [], obj = [], bare = [] %}
            TWIG);

        foreach ($this->byName($read) as $name => $prop) {
            self::assertSame(PropType::Json, $prop->type, \sprintf('"%s" stays a JSON prop.', $name));
        }
    }

    public function testAListOfTextsIsRowsOfOneFieldStoredAsAListOfScalars(): void
    {
        $read = $this->reader()->readSource(<<<'TWIG'
            {# @prop steps list<string> The steps #}
            {# @prop notes string[]|null Notes #}
            {# @prop paragraphs list<html> Paragraphs #}
            {# @prop blocks html[] Blocks #}
            {# @prop productIds product[] Products #}
            {# @prop ints list<int> Numbers #}
            {% props steps = [], notes = null, paragraphs = [], blocks = [], productIds = [], ints = [] %}
            TWIG);
        $props = $this->byName($read);

        foreach (['steps', 'notes'] as $name) {
            self::assertSame(PropType::Items, $props[$name]->type);
            self::assertTrue($props[$name]->scalarItems, $name);
            self::assertSame(50, $props[$name]->maxItems);
            self::assertCount(1, $props[$name]->items);
            self::assertSame('value', $props[$name]->items[0]->name);
            self::assertSame(PropType::Text, $props[$name]->items[0]->type);
            self::assertSame(500, $props[$name]->items[0]->max);
        }

        foreach (['paragraphs', 'blocks'] as $name) {
            self::assertTrue($props[$name]->scalarItems, $name);
            self::assertSame(PropType::Html, $props[$name]->items[0]->type);
        }

        self::assertFalse($props['product_ids']->scalarItems, 'An id list keeps its own path.');
        self::assertSame('id', $props['product_ids']->items[0]->name);
        self::assertSame(12, $props['product_ids']->maxItems);
        self::assertSame(PropType::Json, $props['ints']->type);
        self::assertSame(['product_ids'], $read['id_lists']);
        self::assertSame(['a', 'b'], $read['schema']->normalize(['steps' => [['value' => 'a'], 'b', '']])['steps']);
    }

    /**
     * @return array<string, Prop>
     */
    private function byName(array $read): array
    {
        $props = [];

        foreach ($read['schema']->props as $prop) {
            $props[$prop->name] = $prop;
        }

        return $props;
    }

    private function reader(): ComponentPropsReader
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => '/'.$route);

        return new ComponentPropsReader(new Environment(new ArrayLoader()), $urls);
    }
}
