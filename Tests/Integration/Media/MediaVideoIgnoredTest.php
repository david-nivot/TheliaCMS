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

namespace TheliaCMS\Tests\Integration\Media;

use TheliaCMS\Content\ImageSource;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\Tool\SearchMediaTool;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\Admin\MediaUploadRefusal;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\MediaFilter;
use TheliaCMS\Media\MediaKind;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * The library holds videos next to the images, and whatever wants a picture
 * ignores or refuses them: the pickers, the search of the generator, the
 * address of an image, an image uploaded in a section, the state of an image.
 * The images are unchanged by it.
 */
final class MediaVideoIgnoredTest extends AdminScreenTestCase
{
    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $file = TheliaLibrary::getImageDirectory().$image->getFileName();

            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testTheLibraryNarrowsToOneKindAndAnImageWithNoRecordedTypeIsAnImage(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $image = $this->image('Photo');
        $untyped = $this->image('Ancienne', mimeType: null);
        $video = Mp4Videos::row($library, $this->locale());
        $ids = static fn (array $images): array => array_map(static fn (LibraryImage $found): int => (int) $found->getId(), $images);

        self::assertEqualsCanonicalizing([(int) $image->getId(), (int) $untyped->getId(), (int) $video->getId()], $ids($library->images()), 'Without a kind, everything.');
        self::assertEqualsCanonicalizing([(int) $image->getId(), (int) $untyped->getId()], $ids($library->images(kind: MediaKind::Image)), 'NULL is an image.');
        self::assertSame([(int) $video->getId()], $ids($library->images(kind: MediaKind::Video)));
        self::assertSame(3, $library->counts($this->locale())['total']);
        self::assertSame(2, $library->counts($this->locale(), kind: MediaKind::Image)['total']);
        self::assertEqualsCanonicalizing([(int) $image->getId(), (int) $untyped->getId()], $library->selection($this->locale(), kind: MediaKind::Image));
        self::assertSame([(int) $video->getId()], $library->selection($this->locale(), filter: MediaFilter::Unused, kind: MediaKind::Video));
        self::assertNull($library->ownedImage((int) $video->getId(), MediaKind::Image), 'A video is no image.');
        self::assertNotNull($library->ownedImage((int) $video->getId()), 'The media screens still reach it.');
        self::assertNotNull($library->ownedImage((int) $untyped->getId(), MediaKind::Image));
    }

    public function testThePickerListsTheImagesOnlyAndAnswersAVideoByItsIdAsAnUnknownImage(): void
    {
        $image = $this->image('Fauteuil');
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale(), 'Visite');
        $this->tagBoth($image, $video, 'Salon');

        $this->client->request('GET', '/admin/cms/media/picker', [], [], self::XHR);
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame([(int) $image->getId()], array_column($page['items'], 'id'));
        self::assertSame(1, $page['total']);

        $this->client->request('GET', '/admin/cms/media/picker?search=Visite', [], [], self::XHR);
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'Not found by its title either.');

        $this->client->request('GET', '/admin/cms/media/picker?id='.$video->getId(), [], [], self::XHR);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/admin/cms/media/picker?id='.$image->getId(), [], [], self::XHR);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The image still answers.');

        $screen = $this->screen('/admin/cms/media');
        self::assertSame(1, $screen->filter(\sprintf('[data-testid="cms-media-card-%d"]', $video->getId()))->count(), 'The list of the library still shows the video.');
    }

    public function testTheSearchOfTheGeneratorFindsTheImagesOnly(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $image = $this->image('Visite du salon');
        Mp4Videos::row($library, $this->locale(), 'Visite en vidéo');
        $tool = new SearchMediaTool($library);
        $request = GenerationRequest::fromBrief(GenerationTarget::page($this->createPage('Brief', published: false), $this->locale()), $this->locale(), new Brief('A page.'));

        $latest = json_decode($tool->run(['query' => ''], $request), true);
        $found = json_decode($tool->run(['query' => 'Visite'], $request), true);

        self::assertSame([(int) $image->getId()], array_column($latest, 'id'));
        self::assertSame([(int) $image->getId()], array_column($found, 'id'));
    }

    public function testAVideoIdGivesNoImageToASectionAndAnImageIsUnchanged(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $image = $this->image('Photo', alt: 'Un fauteuil');
        $video = Mp4Videos::row($library, $this->locale());

        self::assertSame('/image-library/'.$image->getId().'/full/max/0/default.jpg', ImageSource::of($image->getId()));
        self::assertNull(ImageSource::of($video->getId()), 'No address, above all not /video-library.');
        self::assertNull(ImageSource::of((string) $video->getId()));
        self::assertNull(ImageSource::framed($video->getId(), '1:1', 50, 50, 100));
        self::assertSame('Un fauteuil', ImageSource::alt($image->getId(), 'Section', $this->locale()));
        self::assertSame('', ImageSource::alt($video->getId(), 'Texte de la section', $this->locale()));
    }

    public function testAnAddressOfAVideoIsNeverReadAsAnImageNorGivenAState(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $image = $this->image('Sans texte');
        $video = Mp4Videos::row($library, $this->locale());
        $imageUrl = '/image-library/'.$image->getId().'/full/max/0/default.jpg';
        $videoAsImage = '/image-library/'.$video->getId().'/full/max/0/default.mp4';
        $videoUrl = (string) $library->publicUrl($video);

        self::assertSame('/video-library/'.$video->getId().'.mp4', $videoUrl);
        self::assertNull($library->fromUrl($videoUrl));
        self::assertNull($library->fromUrl($videoAsImage), 'The image route answers 404 for a video: nothing to resize.');
        self::assertSame((int) $image->getId(), $library->fromUrl($imageUrl)?->id);

        $states = $library->statesOf([$imageUrl, $videoUrl, $videoAsImage], $this->locale());

        self::assertSame([$imageUrl], array_keys($states), 'A video is never "without alternative text".');

        $video->setLocale($this->locale())->setAlt('Un clip')->save();
        self::assertSame([], $library->alternativeTextsOf([$videoUrl, $videoAsImage], $this->locale()), 'Nor given one to print on an image.');
    }

    public function testAnImageSentWhereAPictureIsWantedIsNeverAVideo(): void
    {
        $writer = $this->getService(CmsMediaWriter::class);

        $upload = $writer->add([Mp4Videos::small('clip.mp4')], $this->locale(), imagesOnly: true);

        self::assertSame([], $upload->stored, 'Nothing is stored.');
        self::assertSame(MediaUploadRefusal::Format, $upload->refused[0]['refusal']);

        $kept = $writer->add([Mp4Videos::small('clip.mp4')], $this->locale());
        self::assertCount(1, $kept->stored, 'The library takes it.');
        self::assertTrue($kept->stored[0]->isVideo());
    }

    private function tagBoth(LibraryImage $image, LibraryImage $video, string $tag): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $library->addTag($image, $tag);
        $library->addTag($video, $tag);

        self::assertSame([$tag => 2], $library->tagCounts(), 'Both carry the tag.');
        self::assertSame([$tag => 1], $library->tagCounts(MediaKind::Image), 'A picker offers the tags of its own kind.');
    }

    private function image(string $title, ?string $alt = null, ?string $mimeType = 'image/jpeg'): LibraryImage
    {
        $image = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.jpg')->setWidth(800)->setHeight(600)->setDecorative(0)->setMimeType($mimeType);
        $image->setLocale($this->locale())->setTitle($title)->setAlt($alt);
        $image->save();

        $this->getService(CmsMediaLibrary::class)->tag($image);

        return $image;
    }
}
