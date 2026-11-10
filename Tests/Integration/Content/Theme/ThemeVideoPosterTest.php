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

use Symfony\UX\TwigComponent\ComponentRendererInterface;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Video\VideoConversionStatus;
use TheliaCMS\Model\CmsVideoConversion;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;

/**
 * The poster taken from a video when it was converted is what a component
 * that shows the video draws as its `poster`, unless the author picked one.
 */
final class ThemeVideoPosterTest extends CmsIntegrationTestCase
{
    /** @var list<array<string, mixed>> */
    private array $received = [];

    public function testAComponentIsGivenThePosterOfItsVideoWhenTheAuthorPickedNone(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $poster = $this->image('Poster');
        $this->converted($video, $poster);
        $section = $this->section();

        $section->render($section->normalizeProps(['title' => 'Gallery', 'clip' => $video->getId()]), $this->locale());

        self::assertSame('/image-library/'.$poster->getId().'/full/max/0/default.jpg', $this->received[0]['poster']);
    }

    public function testThePosterTheAuthorPickedWins(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $this->converted($video, $this->image('Poster'));
        $picked = $this->image('Chosen');
        $section = $this->section();

        $section->render($section->normalizeProps(['title' => 'Gallery', 'clip' => $video->getId(), 'poster' => $picked->getId()]), $this->locale());

        self::assertSame('/image-library/'.$picked->getId().'/full/max/0/default.jpg', $this->received[0]['poster']);
    }

    public function testAVideoThatWasNeverConvertedOrHasNoPosterGivesNone(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $plain = Mp4Videos::row($library, $this->locale());
        $withoutPoster = Mp4Videos::row($library, $this->locale());
        $this->converted($withoutPoster, null);
        $section = $this->section();

        $section->render($section->normalizeProps(['title' => 'Gallery', 'clip' => $plain->getId()]), $this->locale());
        $section->render($section->normalizeProps(['title' => 'Gallery', 'clip' => $withoutPoster->getId()]), $this->locale());

        self::assertArrayNotHasKey('poster', $this->received[0]);
        self::assertArrayNotHasKey('poster', $this->received[1]);
    }

    public function testEachRowOfAListTakesThePosterOfItsOwnVideo(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $first = Mp4Videos::row($library, $this->locale(), 'One');
        $second = Mp4Videos::row($library, $this->locale(), 'Two');
        $firstPoster = $this->image('P1');
        $this->converted($first, $firstPoster);
        $this->converted($second, null);
        $picked = $this->image('Chosen');
        $section = $this->section();

        $section->render($section->normalizeProps(['title' => 'Gallery', 'videos' => [
            ['title' => 'One', 'video' => $first->getId()],
            ['title' => 'Two', 'video' => $second->getId()],
            ['title' => 'Three', 'video' => $first->getId(), 'poster' => $picked->getId()],
        ]]), $this->locale());

        $rows = $this->received[0]['videos'];
        self::assertSame('/image-library/'.$firstPoster->getId().'/full/max/0/default.jpg', $rows[0]['poster']);
        self::assertNull($rows[1]['poster'] ?? null, 'A video without a poster leaves the row without.');
        self::assertSame('/image-library/'.$picked->getId().'/full/max/0/default.jpg', $rows[2]['poster'], 'The row the author gave a poster keeps it.');
    }

    private function converted(LibraryImage $video, ?LibraryImage $poster): void
    {
        (new CmsVideoConversion())
            ->setLibraryImageId($video->getId())
            ->setStatus(VideoConversionStatus::Ready->value)
            ->setPosterImageId($poster?->getId())
            ->save();
    }

    private function section(): ThemeComponentSection
    {
        $this->received = [];
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(function (string $component, array $props): string {
            $this->received[] = $props;

            return '';
        });

        $schema = new PropSchema([
            Prop::text('title', 'title'),
            Prop::video('clip', 'clip'),
            Prop::image('poster', 'poster'),
            Prop::items('videos', 'videos', [Prop::text('title', 'title'), Prop::video('video', 'video'), Prop::image('poster', 'poster')]),
        ]);

        return new ThemeComponentSection('Organisms:Videos:Base', '', $schema, ['title' => 'title', 'clip' => 'clip', 'poster' => 'poster', 'videos' => 'videos'], $renderer);
    }

    private function image(string $title): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0);
        $image->setLocale($this->locale())->setTitle($title);
        $image->save();

        return $image;
    }
}
