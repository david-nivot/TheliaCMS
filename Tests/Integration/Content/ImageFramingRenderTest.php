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

namespace TheliaCMS\Tests\Integration\Content;

use Symfony\UX\TwigComponent\ComponentRendererInterface;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\ImageFraming;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;

/**
 * The pictures of a component of the theme and of a component made from a
 * brief are drawn from the address of the part the editor keeps; with nothing
 * set the address is the one of before, and the component never reads what the
 * editor set.
 */
final class ImageFramingRenderTest extends CmsIntegrationTestCase
{
    public function testAFramedPictureReachesAThemeComponentAsTheAddressOfItsZone(): void
    {
        $imageId = $this->imageOf(1600, 900);
        $received = [];
        $section = $this->themeSection($received, [Prop::text('title', 'title'), Prop::image('image', 'image')]);

        // 1:1 around the centre of a 1600 by 900 picture: 900 by 900 from x = 350.
        $section->render($section->normalizeProps(['title' => 'A', 'image' => $imageId, 'image__proportions' => '1:1']), $this->locale());
        // The picture as it is, zoomed 200 at the top left: 800 by 450 from the corner.
        $section->render($section->normalizeProps(['image' => $imageId, 'image__zoom' => 200, 'image__focus_x' => 0, 'image__focus_y' => 0]), $this->locale());

        self::assertSame(['title' => 'A', 'image' => '/image-library/'.$imageId.'/350,0,900,900/max/0/default.jpg'], $received[0]);
        self::assertSame(['image' => '/image-library/'.$imageId.'/0,0,800,450/max/0/default.jpg'], $received[1], 'Nothing of the framing is handed to the component.');
    }

    public function testAComponentNobodyFramedIsGivenWhatItWasGiven(): void
    {
        $imageId = $this->imageOf(1600, 900);
        $received = [];
        $section = $this->themeSection($received, [Prop::image('image', 'image')]);

        $section->render($section->normalizeProps(['image' => $imageId]), $this->locale());
        // The proportions of the picture itself, centred, not zoomed: the whole picture.
        $section->render($section->normalizeProps(['image' => $imageId, 'image__proportions' => 'original', 'image__focus_x' => 10, 'image__zoom' => 100]), $this->locale());
        // A ratio the picture already has.
        $section->render($section->normalizeProps(['image' => $imageId, 'image__proportions' => '16:9']), $this->locale());

        $whole = ['image' => '/image-library/'.$imageId.'/full/max/0/default.jpg'];
        self::assertSame($whole, $received[0]);
        self::assertSame($whole, $received[1]);
        self::assertSame($whole, $received[2]);
    }

    public function testAPictureKeptElsewhereAndAPictureNotMeasuredAreNotCropped(): void
    {
        $unmeasured = new LibraryImage();
        $unmeasured->setLocale($this->locale())->setFileName('unmeasured.png')->setTitle('unmeasured');
        $unmeasured->save();
        $received = [];
        $section = $this->themeSection($received, [Prop::image('image', 'image')]);

        $section->render($section->normalizeProps(['image' => 'https://images.example.com/a.jpg', 'image__proportions' => '1:1', 'image__zoom' => 300]), $this->locale());
        $section->render($section->normalizeProps(['image' => (int) $unmeasured->getId(), 'image__proportions' => '1:1']), $this->locale());

        self::assertSame(['image' => 'https://images.example.com/a.jpg'], $received[0]);
        self::assertSame(['image' => '/image-library/'.$unmeasured->getId().'/full/max/0/default.png'], $received[1]);
    }

    public function testThePicturesOfTheRowsOfAListAreFramedRowByRow(): void
    {
        $imageId = $this->imageOf(1600, 900);
        $received = [];
        $section = $this->themeSection($received, [Prop::items('slides', 'slides', [Prop::text('title', 'title'), Prop::image('picture', 'picture')], 0, 4)]);

        $section->render($section->normalizeProps(['slides' => [
            ['title' => 'One', 'picture' => $imageId, 'picture__proportions' => '1:1'],
            ['title' => 'Two', 'picture' => $imageId],
        ]]), $this->locale());

        self::assertSame([
            ['title' => 'One', 'picture' => '/image-library/'.$imageId.'/350,0,900,900/max/0/default.jpg'],
            ['title' => 'Two', 'picture' => '/image-library/'.$imageId.'/full/max/0/default.jpg'],
        ], $received[0]['slides']);
    }

    public function testAZoomOffTheStepsIsRefused(): void
    {
        $received = [];
        $section = $this->themeSection($received, [Prop::image('image', 'image')]);

        $this->expectException(\TheliaCMS\Content\InvalidDocumentException::class);
        $section->normalizeProps(['image' => 12, 'image__zoom' => 155]);
    }

    public function testAComponentMadeFromABriefIsGivenTheAddressOfTheZoneAndPublishedWithIt(): void
    {
        $imageId = $this->imageOf(1600, 900);
        $this->getService(SiteComponentRepository::class)->save((new SiteComponentValidator())->validate(new SiteComponent(
            'PhotoCard',
            'Photo card',
            'A photo over a title.',
            [['name' => 'title', 'type' => 'text', 'label' => 'Title'], ['name' => 'photo', 'type' => 'image', 'label' => 'Photo']],
            ['title' => 'A card', 'photo' => null],
            '<article class="photo-card"><img class="photo-card__img" src="{{ props.photo }}" alt=""><h3>{{ props.title }}</h3></article>',
        )), SiteComponentRepository::STATUS_READY);
        $page = $this->createPage('Cards', published: false);

        $this->writer()->saveContent($page, $this->locale(), new Document([
            new Section('plain', 'Site:PhotoCard', ['title' => 'Plain', 'photo' => $imageId]),
            new Section('framed', 'Site:PhotoCard', ['title' => 'Framed', 'photo' => $imageId, 'photo__proportions' => '1:1', 'photo__focus_x' => 50, 'photo__focus_y' => 50]),
        ]));
        $this->writer()->publish($page, $this->locale());

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);
        $draft = (string) $content->getDraftHtml();
        $published = (string) $content->getPublishedHtml();

        self::assertStringContainsString('src="/image-library/'.$imageId.'/350,0,900,900/max/0/default.jpg"', $published);
        self::assertStringContainsString('width="900" height="900"', $published);
        self::assertStringContainsString('/image-library/'.$imageId.'/350,0,900,900/480,480/0/default.webp 480w', $published);
        self::assertStringContainsString('/image-library/'.$imageId.'/full/480,270/0/default.jpg 480w', $published, 'The card nobody framed is served as before.');
        self::assertStringNotContainsString('photo__', $published);
        self::assertStringNotContainsString('photo__', $draft);
    }

    /**
     * @param list<Prop>                 $props
     * @param list<array<string, mixed>> $received by reference: what each render handed the component
     */
    private function themeSection(array &$received, array $props): ThemeComponentSection
    {
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function (string $component, array $given) use (&$received): string {
            $received[] = $given;

            return '';
        });
        $schema = new PropSchema((new ImageFraming())->extend($props));
        $names = [];

        foreach ($schema->props as $prop) {
            $names[$prop->name] = $prop->name;
        }

        return new ThemeComponentSection('Layouts:Banner:Base', '', $schema, $names, $renderer);
    }

    private function imageOf(int $width, int $height): int
    {
        $image = new LibraryImage();
        $image->setLocale($this->locale())->setFileName('landscape.jpg')->setTitle('landscape')->setWidth($width)->setHeight($height);
        $image->save();

        return (int) $image->getId();
    }
}
