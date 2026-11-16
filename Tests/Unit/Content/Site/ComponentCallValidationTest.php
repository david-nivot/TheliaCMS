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

namespace TheliaCMS\Tests\Unit\Content\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\HeadingSection;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Content\Site\SiteTemplateRenderer;
use TheliaCMS\Tests\Support\InMemoryDrawableSections;
use TheliaCMS\Tests\Support\InMemorySiteComponents;

/**
 * What the validator says of the components a template draws with
 * `cms_component()` (ADR 15).
 */
final class ComponentCallValidationTest extends TestCase
{
    private const array CAROUSEL_PROPS = [
        ['name' => 'title', 'type' => 'text', 'label' => 'Title'],
        ['name' => 'slides', 'type' => 'items', 'label' => 'Slides', 'min' => 1, 'items' => [
            ['name' => 'image', 'type' => 'image', 'label' => 'Image', 'required' => true],
            ['name' => 'alt', 'type' => 'text', 'label' => 'Text'],
        ]],
    ];

    public function testAComponentThatDrawsAKeptOneWithItsPropsIsKeptAndItsSampleDrawsBoth(): void
    {
        $kept = $this->validator()->validate($this->row('{{ cms_component("Carousel", {slides: props.photos, title: props.heading}) }}'));

        self::assertSame('Row', $kept->code);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedCalls(): iterable
    {
        yield 'itself' => ['{{ cms_component("Row", {heading: props.heading}) }}', 'Line 1: the component draws itself.'];
        yield 'a prototype' => ['{{ cms_component("Draft") }}', 'Line 1: the catalogue offers no component Draft'];
        yield 'a code the site does not hold' => ['{{ cms_component("Slider") }}', 'Line 1: the catalogue offers no component Slider'];
        yield 'a component that draws others' => ['{{ cms_component("Calling") }}', 'Line 1: Calling draws other components itself'];
        yield 'a key the component does not declare' => ['{{ cms_component("Carousel", {pictures: props.photos}) }}', 'Line 1: Carousel has no prop "pictures"; its props are title, slides.'];
        yield 'a prop of another type' => ['{{ cms_component("Carousel", {title: props.photos}) }}', 'Line 1: props.photos is of type items, and the prop "title" of Carousel of type text.'];
        yield 'rows without a required field' => ['{{ cms_component("Carousel", {slides: props.links}) }}', 'Line 1: the rows of props.links have no "image", which the rows of "slides" of Carousel need'];
        yield 'the field of a row' => ['{% for link in props.links %}{{ cms_component("Echo", {said: link.alt}) }}{% endfor %}', 'Line 1: cms_component passes "said" something else than a prop'];
        yield 'rows naming another record' => ['{{ cms_component("Shelf", {products: props.categories}) }}', 'Line 1: "id" of the rows of props.categories names a category, and Shelf reads a product.'];
        yield 'a reference to another record' => ['{{ cms_component("Shelf", {lead: props.section}) }}', 'Line 1: props.section names a category, and the prop "lead" of Shelf a product.'];
        yield 'a text for an object' => ['{{ cms_component("Band", {button: props.heading}) }}', 'Line 1: props.heading is of type text, and the prop "button" of Band of type items.'];
        yield 'a level the heading does not offer' => ['{{ cms_component("cms:heading", {text: props.heading, level: "h5"}) }}', 'Line 1: "h5" is written for the prop "level" of cms:heading, which offers h2, h3, h4.'];
        yield 'a choice with a value the heading does not offer' => ['{{ cms_component("cms:heading", {text: props.heading, level: props.size}) }}', 'Line 1: props.size may be h1, which the prop "level" of cms:heading does not offer; it offers h2, h3, h4.'];
        yield 'a style the heading does not offer' => ['{{ cms_component("cms:heading", {text: props.heading, style: "h1"}) }}', 'Line 1: "h1" is written for the prop "style" of cms:heading, which offers level.'];
        yield 'a choice of styles the heading does not offer' => ['{{ cms_component("cms:heading", {text: props.heading, style: props.look}) }}', 'Line 1: props.look may be display, which the prop "style" of cms:heading does not offer; it offers level.'];
        yield 'rows with a field of another type' => ['{{ cms_component("Carousel", {slides: props.wrong}) }}', 'Line 1: "image" of the rows of props.wrong is of type text, and Carousel reads it as of type image.'];
    }

    #[DataProvider('refusedCalls')]
    public function testACallTheComponentCannotHoldIsRefused(string $call, string $said): void
    {
        try {
            $this->validator()->validate($this->row($call));
            self::fail('The call is refused.');
        } catch (InvalidSiteComponentException $exception) {
            self::assertStringContainsString($said, implode("\n", $exception->problems));
        }
    }

    public function testAComponentAnotherDrawsCannotStartDrawingOthers(): void
    {
        $carousel = new SiteComponent('Carousel', 'Carousel', 'Photos in a row.', self::CAROUSEL_PROPS, ['slides' => [['image' => 1]]], '<section>{% for slide in props.slides %}<img src="{{ slide.image }}" alt="{{ slide.alt }}">{% endfor %}</section>{{ cms_component("Echo", {said: props.title}) }}');

        try {
            $this->validator()->validate($carousel);
            self::fail('The call is refused.');
        } catch (InvalidSiteComponentException $exception) {
            self::assertContains('The component Carousel is drawn by Gallery: a component draws others one level deep, so one that another draws cannot draw any itself.', $exception->problems);
        }
    }

    /**
     * A heading of the site draws nothing (ADR 17): a component drawn by
     * another may draw one, and drawing one that does is not drawing a
     * component that draws others.
     */
    public function testAHeadingKeepsAComponentOneThatAnotherMayDraw(): void
    {
        $carousel = new SiteComponent('Carousel', 'Carousel', 'Photos in a row.', self::CAROUSEL_PROPS, ['title' => 'Photos', 'slides' => [['image' => 'https://example.com/a.jpg', 'alt' => 'A']]], '{{ cms_component("cms:heading", {text: props.title, level: "h3"}) }}<section>{% for slide in props.slides %}<img src="{{ slide.image }}" alt="{{ slide.alt }}">{% endfor %}</section>');

        self::assertSame('Carousel', $this->validator()->validate($carousel)->code, 'Gallery draws it, and it draws a heading.');
        self::assertSame('Row', $this->validator()->validate($this->row('{{ cms_component("Titled", {said: props.heading}) }}{{ cms_component("cms:heading", {text: props.heading, level: props.level, style: props.plain}) }}'))->code, 'Titled draws a heading only; the editor sets the level and the look of the other (ADR 18).');
    }

    public function testTheProductsAPageStoresAreHandedOnToAComponentThatShowsProducts(): void
    {
        $kept = $this->validator()->validate($this->row('{{ cms_component("Shelf", {products: props.picked, lead: props.star}) }}'));

        self::assertSame('Row', $kept->code);
    }

    public function testAnObjectIsHandedOnAsOneRowWhoseAddressReadsAsAText(): void
    {
        $kept = $this->validator()->validate($this->row('{{ cms_component("Band", {button: props.cta}) }}'));

        self::assertSame('Row', $kept->code);
    }

    public function testATextAndAMarkupPropReadAsEachOther(): void
    {
        $kept = $this->validator()->validate($this->row('{{ cms_component("Echo", {said: props.body}) }}'));

        self::assertSame('Row', $kept->code);
    }

    private function row(string $call): SiteComponent
    {
        return new SiteComponent('Row', 'Row', 'A heading next to a carousel.', [
            ['name' => 'heading', 'type' => 'text', 'label' => 'Heading'],
            ['name' => 'body', 'type' => 'html', 'label' => 'Body'],
            ['name' => 'photos', 'type' => 'items', 'label' => 'Photos', 'items' => [['name' => 'image', 'type' => 'image', 'label' => 'Image'], ['name' => 'alt', 'type' => 'text', 'label' => 'Text']]],
            ['name' => 'links', 'type' => 'items', 'label' => 'Links', 'items' => [['name' => 'alt', 'type' => 'text', 'label' => 'Text']]],
            ['name' => 'wrong', 'type' => 'items', 'label' => 'Wrong', 'items' => [['name' => 'image', 'type' => 'text', 'label' => 'Image']]],
            ['name' => 'picked', 'type' => 'items', 'label' => 'Products', 'items' => [['name' => 'id', 'type' => 'reference', 'label' => 'Product', 'record' => 'product', 'required' => true]]],
            ['name' => 'categories', 'type' => 'items', 'label' => 'Categories', 'items' => [['name' => 'id', 'type' => 'reference', 'label' => 'Category', 'record' => 'category', 'required' => true]]],
            ['name' => 'star', 'type' => 'reference', 'label' => 'Product', 'record' => 'product'],
            ['name' => 'section', 'type' => 'reference', 'label' => 'Category', 'record' => 'category'],
            ['name' => 'size', 'type' => 'choice', 'label' => 'Size', 'choices' => ['h1' => 'Large', 'h2' => 'Medium'], 'default' => 'h2'],
            ['name' => 'level', 'type' => 'choice', 'label' => 'Level', 'choices' => ['h2' => 'Part', 'h3' => 'Sub-part'], 'default' => 'h2'],
            ['name' => 'look', 'type' => 'choice', 'label' => 'Look', 'choices' => ['level' => 'Its level', 'display' => 'Display'], 'default' => 'level'],
            ['name' => 'plain', 'type' => 'choice', 'label' => 'Look', 'choices' => ['level' => 'Its level'], 'default' => 'level'],
            ['name' => 'cta', 'type' => 'items', 'label' => 'Button', 'max' => 1, 'items' => [['name' => 'href', 'type' => 'url', 'label' => 'Address', 'required' => true], ['name' => 'label', 'type' => 'text', 'label' => 'Label', 'required' => true]]],
        ], ['heading' => 'Photos', 'body' => '<p>Hello</p>', 'photos' => [['image' => 'https://example.com/a.jpg', 'alt' => 'A']]], '<div class="row"><h2>{{ props.heading }}</h2>'.$call.'</div>');
    }

    private function validator(): SiteComponentValidator
    {
        $components = new InMemorySiteComponents([
            new SiteComponent('Carousel', 'Carousel', 'Photos in a row.', self::CAROUSEL_PROPS, [], '<section>{% for slide in props.slides %}<img src="{{ slide.image }}" alt="{{ slide.alt }}">{% endfor %}</section>'),
            new SiteComponent('Shelf', 'Shelf', 'Products in a row.', [
                ['name' => 'lead', 'type' => 'reference', 'label' => 'Product first', 'record' => 'product'],
                ['name' => 'products', 'type' => 'items', 'label' => 'Products', 'items' => [['name' => 'id', 'type' => 'reference', 'label' => 'Product', 'record' => 'product', 'required' => true]]],
            ], [], '<ul data-lead="{{ props.lead }}">{% for product in props.products %}<li>{{ product.id }}</li>{% endfor %}</ul>'),
            new SiteComponent('Band', 'Band', 'A band with a button.', [
                ['name' => 'button', 'type' => 'items', 'label' => 'Button', 'max' => 1, 'items' => [['name' => 'href', 'type' => 'text', 'label' => 'Address', 'required' => true], ['name' => 'label', 'type' => 'text', 'label' => 'Label', 'required' => true]]],
            ], [], '<div>{% for button in props.button %}<a href="{{ button.href }}">{{ button.label }}</a>{% endfor %}</div>'),
            new SiteComponent('Echo', 'Echo', 'Says a word.', [['name' => 'said', 'type' => 'html', 'label' => 'Said']], [], '<p>{{ props.said }}</p>'),
            new SiteComponent('Calling', 'Calling', 'Calls another.', [['name' => 'said', 'type' => 'text', 'label' => 'Said']], [], '<p>{{ props.said }}</p>{{ cms_component("Echo", {said: "x"}) }}'),
            new SiteComponent('Titled', 'Titled', 'A heading over a word.', [['name' => 'said', 'type' => 'text', 'label' => 'Said']], [], '{{ cms_component("cms:heading", {text: props.said}) }}<p>{{ props.said }}</p>'),
            new SiteComponent('Gallery', 'Gallery', 'Calls the carousel.', [['name' => 'said', 'type' => 'text', 'label' => 'Said']], [], '<div>{{ cms_component("Carousel", {title: props.said}) }}</div>'),
        ], [
            new SiteComponent('Draft', 'Draft', 'A prototype.', [['name' => 'said', 'type' => 'text', 'label' => 'Said']], [], '<p>draft</p>'),
        ]);

        $sections = new InMemoryDrawableSections(array_values(array_filter($components->all(), static fn (SiteComponent $component): bool => null !== $components->kept($component->code))), [new HeadingSection()]);
        $renderer = new SiteTemplateRenderer(null, $sections);
        $sections->renderer = $renderer;

        return new SiteComponentValidator(new SitePropReader(), $renderer, components: $components, sections: $sections);
    }
}
