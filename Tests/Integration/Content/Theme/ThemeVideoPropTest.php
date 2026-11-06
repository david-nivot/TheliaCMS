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

namespace TheliaCMS\Tests\Integration\Content\Theme;

use Psr\Log\NullLogger;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Theme\ComponentPropsReader;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Content\Theme\ThemeComponentSections;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;

/**
 * A `video` prop of a component of the theme: the section stores the id of a
 * video of the library, the template is given the address of its file, at the
 * top of the props and in the rows of a list, and the page keeps what the
 * template prints from it.
 */
final class ThemeVideoPropTest extends CmsIntegrationTestCase
{
    private const string CONTROLLER = 'Organisms--Videos--base';

    /** @var list<array<string, mixed>> what the renderer received, one entry per render */
    private array $received = [];

    public function testAVideoReachesTheTemplateAsTheAddressOfItsFileAtTheTopAndInTheRows(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $image = $this->image();
        $section = $this->section();

        $section->render($section->normalizeProps([
            'title' => 'Gallery',
            'clip' => $video->getId(),
            'videos' => [['title' => 'One', 'video' => $video->getId(), 'image' => $image->getId()]],
        ]), $this->locale());

        $address = '/video-library/'.$video->getId().'.mp4';

        self::assertSame($address, $this->received[0]['clip']);
        self::assertSame(['title' => 'One', 'video' => $address, 'image' => '/image-library/'.$image->getId().'/full/max/0/default.jpg'], $this->received[0]['videos'][0], 'The video of a row is its address as the image of the row is.');
    }

    public function testAnIdThatIsNoVideoOfTheLibraryIsNothingAndAVideoIsNoImage(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $image = $this->image();
        $section = $this->section();

        $section->render($section->normalizeProps([
            'title' => 'Gallery',
            'clip' => $image->getId(),
            'poster' => $video->getId(),
            'videos' => [['title' => 'One', 'video' => $image->getId(), 'image' => $video->getId()], ['title' => 'Two', 'video' => 987654, 'image' => 987654]],
        ]), $this->locale());

        self::assertArrayNotHasKey('clip', $this->received[0], 'The id of an image is no video: the template keeps its default.');
        self::assertArrayNotHasKey('poster', $this->received[0], 'The id of a video is no image.');
        self::assertSame(['title' => 'One', 'video' => null, 'image' => null], $this->received[0]['videos'][0]);
        self::assertSame(['title' => 'Two', 'video' => null, 'image' => null], $this->received[0]['videos'][1], 'An id the library does not hold either.');
    }

    public function testTheSampleOfTheCatalogueHoldsNoVideo(): void
    {
        $sample = $this->section()->sample();

        self::assertNull($sample['clip']);
        self::assertSame([], $sample['videos']);
    }

    /**
     * The template prints the address in a `<video>` and in the `data-` of its
     * Stimulus controller; the page keeps both, `preload` included, which is
     * what leaves the file unloaded until it is played.
     */
    public function testThePublishedPageKeepsTheVideoTheTemplatePrintsAndTheControllerData(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $section = $this->section(static fn (array $props): string => \sprintf(
            '<div data-controller="%1$s"><button type="button" data-action="%1$s#play" data-organisms--videos--base-src-param="%2$s">Play</button>'
            .'<video preload="none" muted playsinline src="%2$s"></video><video controls><source src="%2$s" type="video/mp4"></video></div>',
            self::CONTROLLER,
            $props['videos'][0]['video'],
        ));

        $html = $section->render($section->normalizeProps(['title' => 'Gallery', 'videos' => [['title' => 'One', 'video' => $video->getId()]]]), $this->locale());
        $published = (string) $this->sanitizer()->html($html);
        $address = '/video-library/'.$video->getId().'.mp4';

        self::assertStringContainsString('<video preload="none" muted playsinline src="'.$address.'"></video>', $published);
        self::assertStringContainsString('<source src="'.$address.'" type="video/mp4" />', $published);
        self::assertStringContainsString('data-organisms--videos--base-src-param="'.$address.'"', $published);
        self::assertStringContainsString('data-action="'.self::CONTROLLER.'#play"', $published);
    }

    private function section(?\Closure $markup = null): ThemeComponentSection
    {
        $this->received = [];
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(function (string $component, array $props) use ($markup): string {
            $this->received[] = $props;

            return null === $markup ? '' : $markup($props);
        });

        $schema = new PropSchema([
            Prop::text('title', 'title'),
            Prop::video('clip', 'clip'),
            Prop::image('poster', 'poster'),
            Prop::items('videos', 'videos', [Prop::text('title', 'title'), Prop::video('video', 'video'), Prop::image('image', 'image')]),
        ]);

        return new ThemeComponentSection('Organisms:Videos:Base', '', $schema, ['title' => 'title', 'clip' => 'clip', 'poster' => 'poster', 'videos' => 'videos'], $renderer);
    }

    private function image(): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle('Photo');
        $image->save();

        return $image;
    }

    /**
     * A page published by a theme that names the Stimulus controller of the component.
     */
    private function sanitizer(): PublishedContentSanitizer
    {
        $directory = sys_get_temp_dir().'/theliacms-theme-'.bin2hex(random_bytes(4));
        mkdir($directory.'/config', 0o777, true);
        file_put_contents($directory.'/config/theliacms.yaml', "sections:\n    components: []\n    controllers:\n        - ".self::CONTROLLER."\n");

        // The sanitizer reads the declaration of the theme when it filters: the directory stays until the test ends.
        register_shutdown_function(static function () use ($directory): void {
            @unlink($directory.'/config/theliacms.yaml');
            @rmdir($directory.'/config');
            @rmdir($directory);
        });

        $theme = $this->createStub(TemplateDefinition::class);
        $theme->method('getAbsolutePath')->willReturn($directory);
        $theme->method('getParentList')->willReturn([]);

        $templates = $this->createStub(TemplateHelperInterface::class);
        $templates->method('getActiveFrontTemplate')->willReturn($theme);

        return new PublishedContentSanitizer(theme: new ThemeComponentSections($templates, $this->getService(ComponentPropsReader::class), $this->createStub(ComponentRendererInterface::class), new NullLogger()));
    }
}
