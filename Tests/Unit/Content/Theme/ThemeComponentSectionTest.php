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
use Symfony\Component\Asset\Packages;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Component\Translation\Translator;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Theme\ThemeComponentSection;

/**
 * A component of the theme that takes several records gets their ids as a
 * list, the way a template of the theme would hand them over, while the
 * document stores rows an editor picks one by one. It is drawn in the
 * language of the page, whoever saves it.
 */
final class ThemeComponentSectionTest extends TestCase
{
    public function testRowsOfIdsReachTheComponentAsAListOfIds(): void
    {
        $received = null;
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function (string $name, array $props) use (&$received): string {
            $received = [$name, $props];

            return '<div></div>';
        });
        $schema = new PropSchema([
            Prop::text('title', 'title'),
            Prop::items('product_ids', 'product_ids', [Prop::reference('id', 'id', 'product', true, null, '/sources/products', true)], 0, 12),
        ]);
        $section = new ThemeComponentSection('Layouts:CrossSelling:Base', '', $schema, ['title' => 'title', 'product_ids' => 'productIds'], $renderer, ['product_ids']);

        $section->render(['title' => 'Picked', 'product_ids' => [['id' => 22], ['id' => '17'], ['id' => 0]]], 'fr_FR');

        self::assertSame(['Layouts:CrossSelling:Base', ['title' => 'Picked', 'productIds' => [22, 17]]], $received, 'The ids, in order, under the name the component spells; no id is no row.');
        self::assertSame([['id' => 1], ['id' => 2]], $section->sample()['product_ids'], 'The example shows the shape of a row.');
    }

    public function testTheExampleOfTheThemeWinsOverTheDefaults(): void
    {
        $schema = new PropSchema([
            Prop::text('alt', 'alt', 'Default text'),
            Prop::text('embed_url', 'embed_url'),
            Prop::text('provider', 'provider'),
        ]);
        $example = ['embed_url' => 'https://www.youtube-nocookie.com/embed/abc', 'provider' => 'youtube', 'unknown' => 'dropped'];
        $section = new ThemeComponentSection('Organisms:VideoPlayer:Base', '', $schema, [], $this->createStub(ComponentRendererInterface::class), [], $example);

        self::assertSame(
            ['alt' => 'Default text', 'embed_url' => 'https://www.youtube-nocookie.com/embed/abc', 'provider' => 'youtube'],
            $section->sample(),
            'The theme fills what it gives, the defaults the rest, and a prop the component does not take is left out.',
        );
    }

    public function testTheComponentIsDrawnInTheLanguageOfThePage(): void
    {
        $translator = new Translator('en_US');
        $drawnIn = null;
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function () use ($translator, &$drawnIn): string {
            $drawnIn = $translator->getLocale();

            return '<div></div>';
        });
        $section = new ThemeComponentSection('Organisms:RecipeSteps:Base', '', new PropSchema([Prop::text('title', 'title')]), ['title' => 'title'], $renderer, localeSwitcher: new LocaleSwitcher('en_US', [$translator]));

        $section->render(['title' => 'Préparation'], 'fr_FR');

        self::assertSame('fr_FR', $drawnIn, 'A page in French is drawn in French, whatever the language of the one saving it.');
        self::assertSame('en_US', $translator->getLocale(), 'The language of the request is given back.');
    }

    public function testAPictureOfTheThemeIsDrawnInTheExampleOnly(): void
    {
        $received = null;
        $section = $this->bannerWith(['image' => 'images/illustrations/banner.webp', 'title' => 'Hello'], $received);

        self::assertNull($section->sample()['image'], 'A document stores an id or an https address: the path of a file of the theme is never part of the sample.');
        self::assertSame('Hello', $section->sample()['title'], 'The rest of the example is kept.');

        $section->renderExample('fr_FR');

        self::assertSame(['title' => 'Hello', 'image' => '/assets/images/illustrations/banner-abc123.webp'], $received, 'The component is given the address the theme serves the file from.');
    }

    public function testAnHttpsAddressOfTheExampleIsLeftAsItIs(): void
    {
        $received = null;
        $section = $this->bannerWith(['image' => 'https://example.com/banner.webp'], $received);

        self::assertSame('https://example.com/banner.webp', $section->sample()['image']);

        $section->renderExample('fr_FR');

        self::assertSame('https://example.com/banner.webp', $received['image'] ?? null, 'An address is not turned into a file of the theme.');
    }

    public function testThePicturesOfTheRowsAreDrawnFromTheirAddress(): void
    {
        $received = null;
        $section = $this->recipeWith([], $received);

        $section->render(['title' => 'Cake', 'ingredients' => [
            ['name' => 'Flour', 'image' => 'https://example.com/flour.webp'],
            ['name' => 'Salt', 'image' => null],
            ['name' => 'Eggs', 'image' => 0],
        ], 'steps' => ['Mix', 'Bake']], 'fr_FR');

        self::assertSame([
            ['name' => 'Flour', 'image' => 'https://example.com/flour.webp'],
            ['name' => 'Salt', 'image' => null],
            ['name' => 'Eggs', 'image' => null],
        ], $received['ingredients'], 'An image of a row is read as an address like a prop of its own: an unknown one is none.');
        self::assertSame(['Mix', 'Bake'], $received['steps'], 'A list of scalars reaches the component as it is stored.');
    }

    public function testARowReachesTheComponentUnderTheKeysItSpells(): void
    {
        $received = null;
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function (string $name, array $props) use (&$received): string {
            $received = $props;

            return '';
        });
        $schema = new PropSchema([
            Prop::items('items', 'items', [Prop::image('image', 'image'), Prop::text('image_alt', 'image_alt'), Prop::text('button_label', 'button_label')], 0, 50),
        ]);
        $section = new ThemeComponentSection('Organisms:Carousel:Base', '', $schema, ['items' => 'items'], $renderer);

        $section->render($section->normalizeProps(['items' => [['image' => 'https://example.com/a.webp', 'imageAlt' => 'A field', 'buttonLabel' => 'Read more']]]), 'fr_FR');

        self::assertSame([['image' => 'https://example.com/a.webp', 'imageAlt' => 'A field', 'buttonLabel' => 'Read more']], $received['items'], 'Stored under the names of the schema, handed back as the template reads the row.');
    }

    public function testAPictureOfTheThemeInARowIsDrawnInTheExampleOnly(): void
    {
        $received = null;
        $section = $this->recipeWith(['ingredients' => [
            ['name' => 'Flour', 'image' => 'images/recipes/flour.webp'],
            ['name' => 'Salt', 'image' => 'https://example.com/salt.webp'],
            ['name' => 'Eggs'],
        ], 'steps' => ['Mix']], $received);

        self::assertNull($section->sample()['ingredients'][0]['image'], 'A document never stores the path of a file of the theme.');
        self::assertSame('https://example.com/salt.webp', $section->sample()['ingredients'][1]['image']);
        self::assertSame('Flour', $section->sample()['ingredients'][0]['name']);

        $section->renderExample('fr_FR');

        self::assertSame('/assets/images/recipes/flour-abc123.webp', $received['ingredients'][0]['image'], 'The address the theme serves the file from.');
        self::assertSame('https://example.com/salt.webp', $received['ingredients'][1]['image']);
        self::assertSame(['Mix'], $received['steps']);
    }

    /**
     * @param array<string, mixed>      $example
     * @param array<string, mixed>|null $received the props the component is drawn with
     */
    private function recipeWith(array $example, ?array &$received): ThemeComponentSection
    {
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function (string $name, array $props) use (&$received): string {
            $received = $props;

            return '<div></div>';
        });
        $packages = $this->createStub(Packages::class);
        $packages->method('getUrl')->willReturnCallback(static fn (string $path): string => '/assets/'.preg_replace('/\.(\w+)$/', '-abc123.$1', $path));
        $schema = new PropSchema([
            Prop::text('title', 'title'),
            Prop::items('ingredients', 'ingredients', [Prop::text('name', 'name', required: true), Prop::image('image', 'image')], 0, 50),
            Prop::items('steps', 'steps', [Prop::text('value', 'value', required: true)], 0, 50, scalarItems: true),
        ]);

        return new ThemeComponentSection('Organisms:Recipe:Base', '', $schema, ['title' => 'title', 'ingredients' => 'ingredients', 'steps' => 'steps'], $renderer, [], $example, null, $packages);
    }

    /**
     * @param array<string, mixed>      $example
     * @param array<string, mixed>|null $received the props the component is drawn with
     */
    private function bannerWith(array $example, ?array &$received): ThemeComponentSection
    {
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function (string $name, array $props) use (&$received): string {
            $received = $props;

            return '<div></div>';
        });
        $packages = $this->createStub(Packages::class);
        $packages->method('getUrl')->willReturnCallback(static fn (string $path): string => '/assets/'.preg_replace('/\.(\w+)$/', '-abc123.$1', $path));
        $schema = new PropSchema([Prop::text('title', 'title'), Prop::image('image', 'image')]);

        return new ThemeComponentSection('Layouts:Banner:Base', '', $schema, ['title' => 'title', 'image' => 'image'], $renderer, [], $example, null, $packages);
    }
}
