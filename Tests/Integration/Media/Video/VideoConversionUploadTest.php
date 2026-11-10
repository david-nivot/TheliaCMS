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

namespace TheliaCMS\Tests\Integration\Media\Video;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\Admin\MediaUploadRefusal;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Svg\PreparedSvgStore;
use TheliaCMS\Media\Video\ConvertVideo;
use TheliaCMS\Media\Video\FfmpegBinary;
use TheliaCMS\Media\Video\VideoConversions;
use TheliaCMS\Media\Video\VideoConversionStatus;
use TheliaCMS\Media\Video\VideoIntake;
use TheliaCMS\Model\CmsVideoConversionQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeProcessExecutor;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaCMS\Tests\Support\QuickTimeVideos;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\Service\LibraryImageService;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;

/**
 * What the media library does with a video sent from a phone: stored as sent,
 * listed as processing, queued on the heavy queue, replaced under the same id.
 * Without ffmpeg on the server it refuses what it cannot convert, as it
 * always refused what it could not play.
 */
final class VideoConversionUploadTest extends CmsIntegrationTestCase
{
    /** @var list<Envelope> */
    private array $dispatched = [];

    /** @var list<int> */
    private array $stored = [];

    protected function tearDown(): void
    {
        foreach ($this->stored as $id) {
            $this->getService(LibraryImageService::class)->deleteImage($id);
        }

        parent::tearDown();
    }

    public function testAMovIsStoredAsAVideoListedAsProcessingAndQueuedOnTheHeavyQueue(): void
    {
        $this->requireTheLibraryBranch();

        $upload = $this->mediaWriter(new FakeProcessExecutor())->add([QuickTimeVideos::small('IMG_4312.MOV')], $this->locale());
        $this->remember($upload->stored);

        self::assertSame([], $upload->refused);
        self::assertCount(1, $upload->stored);
        $video = $upload->stored[0];
        self::assertTrue($video->isVideo(), 'A video of the library, with the id the pages will keep.');
        self::assertSame('video/quicktime', $video->getMimeType(), 'Stored as it was sent.');
        self::assertContains((int) $video->getId(), array_map(static fn ($image): int => (int) $image->getId(), $this->getService(CmsMediaLibrary::class)->images()));

        $row = CmsVideoConversionQuery::create()->findOneByLibraryImageId($video->getId());
        self::assertNotNull($row);
        self::assertSame(VideoConversionStatus::Processing->value, $row->getStatus());

        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0]->getMessage();
        self::assertInstanceOf(ConvertVideo::class, $message);
        self::assertSame((int) $row->getId(), $message->conversionId);
        self::assertSame([VideoConversions::TRANSPORT], $this->dispatched[0]->last(TransportNamesStamp::class)?->getTransportNames());
        self::assertSame('async_heavy', VideoConversions::TRANSPORT);
    }

    public function testARawVideoUpToOneHundredMegabytesIsTakenAndOneByteMoreIsRefusedWithItsOwnMessage(): void
    {
        $this->requireTheLibraryBranch();
        $writer = $this->mediaWriter(new FakeProcessExecutor());

        $refused = $writer->add([QuickTimeVideos::weighing(CmsMediaType::MAX_RAW_VIDEO_BYTES + 1)], $this->locale());

        self::assertSame([], $refused->stored);
        self::assertSame(MediaUploadRefusal::RawVideoTooHeavy, $refused->refused[0]['refusal']);
        self::assertSame([], $this->dispatched);

        $taken = $writer->add([Mp4Videos::weighing(CmsMediaType::MAX_VIDEO_BYTES + 1, 'phone.mp4')], $this->locale());
        $this->remember($taken->stored);

        self::assertSame([], $taken->refused, 'An MP4 above 50 MB, which the library refused until now, is taken once the server converts.');
        self::assertCount(1, $this->dispatched);
    }

    public function testWithoutFfmpegAMovIsRefusedWithAClearMessageAndNothingIsStored(): void
    {
        $writer = $this->mediaWriter(new FakeProcessExecutor(ffmpegPresent: false));

        $upload = $writer->add([QuickTimeVideos::small()], $this->locale());

        self::assertSame([], $upload->stored);
        self::assertSame(MediaUploadRefusal::VideoNeedsFfmpeg, $upload->refused[0]['refusal']);
        self::assertStringContainsString('ffmpeg', MediaUploadRefusal::VideoNeedsFfmpeg->message());
        self::assertSame([], $this->dispatched);
    }

    public function testWithoutFfmpegAnMp4StaysAcceptedUpToFiftyMegabytesAndIsNeverConverted(): void
    {
        $writer = $this->mediaWriter(new FakeProcessExecutor(ffmpegPresent: false));

        $taken = $writer->add([Mp4Videos::small()], $this->locale());
        $this->remember($taken->stored);

        self::assertSame([], $taken->refused);
        self::assertCount(1, $taken->stored);
        self::assertNull(CmsVideoConversionQuery::create()->findOneByLibraryImageId($taken->stored[0]->getId()), 'No row: a video that was never converted is read as ready.');
        self::assertSame([], $this->dispatched);

        $heavy = $writer->add([Mp4Videos::weighing(CmsMediaType::MAX_VIDEO_BYTES + 1)], $this->locale());
        self::assertSame(MediaUploadRefusal::VideoTooHeavy, $heavy->refused[0]['refusal'], 'The ceiling is the old one.');
    }

    public function testAnImageIsNeverQueuedWhateverTheServer(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-upload-');
        imagepng(imagecreatetruecolor(3, 2), $path);

        $rows = CmsVideoConversionQuery::create()->count();
        $upload = $this->mediaWriter(new FakeProcessExecutor())->add([new UploadedFile($path, 'a.png', 'image/png', null, true)], $this->locale());
        $this->remember($upload->stored);

        self::assertCount(1, $upload->stored);
        self::assertSame([], $this->dispatched);
        self::assertSame($rows, CmsVideoConversionQuery::create()->count());
    }

    public function testReplacingAVideoByAMovKeepsItsIdAndQueuesTheConversion(): void
    {
        $this->requireTheLibraryBranch();
        $writer = $this->mediaWriter(new FakeProcessExecutor());
        $first = $writer->add([Mp4Videos::small()], $this->locale());
        $this->remember($first->stored);
        $video = $first->stored[0];
        $this->dispatched = [];

        $writer->save($video, $this->locale(), ['title' => null, 'caption' => null, 'tags' => null, 'file' => QuickTimeVideos::small('NEW.MOV')]);

        $video = LibraryImageQuery::create()->findPk($video->getId());
        self::assertSame('video/quicktime', $video->getMimeType(), 'The new file is in place, under the same id.');
        self::assertSame(VideoConversionStatus::Processing->value, CmsVideoConversionQuery::create()->findOneByLibraryImageId($video->getId())->getStatus());
        self::assertCount(1, $this->dispatched);
    }

    public function testDeletingAVideoDeletesItsRow(): void
    {
        $this->requireTheLibraryBranch();
        $writer = $this->mediaWriter(new FakeProcessExecutor());
        $upload = $writer->add([QuickTimeVideos::small()], $this->locale());
        $this->remember($upload->stored);
        $id = (int) $upload->stored[0]->getId();

        $writer->delete($upload->stored[0]);

        self::assertNull(CmsVideoConversionQuery::create()->findOneByLibraryImageId($id));
    }

    /**
     * @param list<\TheliaLibrary\Model\LibraryImage> $images
     */
    private function remember(array $images): void
    {
        foreach ($images as $image) {
            $this->stored[] = (int) $image->getId();
        }
    }

    private function requireTheLibraryBranch(): void
    {
        if (!QuickTimeVideos::libraryStoresThem()) {
            self::markTestSkipped('The TheliaLibrary installed stores MP4 only: QuickTime and WebM need TheliaLibrary 2.7.0.');
        }
    }

    private function mediaWriter(FakeProcessExecutor $executor): CmsMediaWriter
    {
        $this->dispatched = [];
        $bus = new class($this->dispatched) implements MessageBusInterface {
            /** @param list<Envelope> $dispatched */
            public function __construct(public array &$dispatched)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->dispatched[] = Envelope::wrap($message, $stamps);
            }
        };

        return new CmsMediaWriter(
            $this->getService(LibraryImageService::class),
            $this->getService(CmsMediaLibrary::class),
            $this->getService(SvgSanitizerInterface::class),
            $this->getService(PreparedSvgStore::class),
            new VideoIntake(new FfmpegBinary($executor)),
            new VideoConversions($bus),
        );
    }
}
