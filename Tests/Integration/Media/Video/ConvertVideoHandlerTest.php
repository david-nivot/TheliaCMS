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

use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Video\ConvertVideo;
use TheliaCMS\Media\Video\ConvertVideoHandler;
use TheliaCMS\Media\Video\FfmpegBinary;
use TheliaCMS\Media\Video\VideoConversionStatus;
use TheliaCMS\Media\Video\VideoFailure;
use TheliaCMS\Model\CmsVideoConversion;
use TheliaCMS\Model\CmsVideoConversionQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeProcessExecutor;
use TheliaCMS\Tests\Support\QuickTimeVideos;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\Service\LibraryImageService;
use TheliaLibrary\TheliaLibrary;

/**
 * The background job that turns the video a phone sent into a web video, run
 * with a double in place of ffmpeg: its real path, files included.
 */
final class ConvertVideoHandlerTest extends CmsIntegrationTestCase
{
    /** @var list<int> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!QuickTimeVideos::libraryStoresThem()) {
            self::markTestSkipped('The TheliaLibrary installed stores MP4 only: QuickTime and WebM need TheliaLibrary 2.7.0.');
        }
    }

    protected function tearDown(): void
    {
        $images = $this->getService(LibraryImageService::class);

        foreach ($this->stored as $id) {
            $images->deleteImage($id);
        }

        parent::tearDown();
    }

    public function testASuccessReplacesTheFileUnderTheSameIdAndLinksAPoster(): void
    {
        [$video, $row] = $this->waitingVideo();
        $rawName = $video->getFileName();
        $executor = new FakeProcessExecutor();

        $this->handler($executor)(new ConvertVideo((int) $row->getId()));

        $video = LibraryImageQuery::create()->findPk($video->getId());
        $row = CmsVideoConversionQuery::create()->findPk($row->getId());

        self::assertNotNull($video, 'The row of the library is kept: same id, same address.');
        self::assertSame('video/mp4', $video->getMimeType(), 'The MOV is replaced by the MP4.');
        self::assertStringEndsWith('.mp4', (string) $video->getFileName());
        self::assertNotSame($rawName, $video->getFileName());
        self::assertFileDoesNotExist(TheliaLibrary::getImageDirectory().$rawName, 'The raw file is removed by the library.');
        self::assertFileExists(TheliaLibrary::getImageDirectory().$video->getFileName());
        self::assertSame(VideoConversionStatus::Ready->value, $row->getStatus());
        self::assertNull($row->getFailure());

        $poster = LibraryImageQuery::create()->findPk($row->getPosterImageId());
        self::assertNotNull($poster, 'The poster is an image of the library, linked on the row.');
        $this->stored[] = (int) $poster->getId();
        self::assertFalse($poster->isVideo());
        self::assertSame(1, $poster->getDecorative(), 'The poster is not one more image to describe.');
        self::assertContains((int) $poster->getId(), array_map(static fn (LibraryImage $image): int => (int) $image->getId(), $this->getService(CmsMediaLibrary::class)->images()), 'It is listed with the images of the CMS.');

        $work = $executor->work();
        self::assertCount(2, $work, 'One conversion, one poster.');
        self::assertContains('+faststart', $work[0]);
        self::assertStringEndsWith('poster.jpg', end($work[1]));
    }

    public function testAFailureIsRecordedWithItsReasonAndTheSentFileIsKept(): void
    {
        [$video, $row] = $this->waitingVideo();
        $rawName = $video->getFileName();

        $this->handler(new FakeProcessExecutor(conversionSucceeds: false, conversionError: "frame=0\nIMG.MOV: Invalid data found when processing input\n"))(new ConvertVideo((int) $row->getId()));

        $row = CmsVideoConversionQuery::create()->findPk($row->getId());
        $video = LibraryImageQuery::create()->findPk($video->getId());

        self::assertSame(VideoConversionStatus::Failed->value, $row->getStatus());
        self::assertSame(VideoFailure::ConversionFailed->value, $row->getFailure());
        self::assertSame('IMG.MOV: Invalid data found when processing input', $row->getDetail(), 'The last line of what ffmpeg said.');
        self::assertNull($row->getPosterImageId());
        self::assertSame($rawName, $video->getFileName(), 'Nothing of the library changed.');
        self::assertFileExists(TheliaLibrary::getImageDirectory().$rawName);
    }

    public function testAnEmptyFileWithAnExitCodeOfZeroIsAFailureToo(): void
    {
        [, $row] = $this->waitingVideo();

        $this->handler(new FakeProcessExecutor(writesAnEmptyConversion: true))(new ConvertVideo((int) $row->getId()));

        self::assertSame(VideoFailure::ConversionFailed->value, CmsVideoConversionQuery::create()->findPk($row->getId())->getFailure());
    }

    public function testWithoutFfmpegTheJobFailsAtOnceAndRunsNothing(): void
    {
        [$video, $row] = $this->waitingVideo();
        $executor = new FakeProcessExecutor(ffmpegPresent: false);

        $this->handler($executor)(new ConvertVideo((int) $row->getId()));

        $row = CmsVideoConversionQuery::create()->findPk($row->getId());
        self::assertSame(VideoConversionStatus::Failed->value, $row->getStatus());
        self::assertSame(VideoFailure::FfmpegMissing->value, $row->getFailure());
        self::assertSame([], $executor->work(), 'No conversion was tried.');
        self::assertSame('video/quicktime', LibraryImageQuery::create()->findPk($video->getId())->getMimeType());
    }

    public function testAVideoWithoutPosterIsReadyAllTheSame(): void
    {
        [, $row] = $this->waitingVideo();

        $this->handler(new FakeProcessExecutor(posterSucceeds: false))(new ConvertVideo((int) $row->getId()));

        $row = CmsVideoConversionQuery::create()->findPk($row->getId());
        self::assertSame(VideoConversionStatus::Ready->value, $row->getStatus());
        self::assertNull($row->getPosterImageId());
    }

    public function testAJobDeliveredTwiceOrAfterTheConversionRunsNothing(): void
    {
        [, $row] = $this->waitingVideo();
        $this->handler(new FakeProcessExecutor())(new ConvertVideo((int) $row->getId()));
        $this->stored[] = (int) CmsVideoConversionQuery::create()->findPk($row->getId())->getPosterImageId();

        $again = new FakeProcessExecutor();
        $this->handler($again)(new ConvertVideo((int) $row->getId()));

        self::assertSame([], $again->commands, 'A converted video is not converted again.');
    }

    public function testAVideoDeletedWhileItWaitedLeavesNoRow(): void
    {
        [$video, $row] = $this->waitingVideo();
        $this->getService(LibraryImageService::class)->deleteImage($video->getId());
        $executor = new FakeProcessExecutor();

        $this->handler($executor)(new ConvertVideo((int) $row->getId()));

        self::assertNull(CmsVideoConversionQuery::create()->findPk($row->getId()));
        self::assertSame([], $executor->commands);
    }

    private function handler(FakeProcessExecutor $executor): ConvertVideoHandler
    {
        return new ConvertVideoHandler(
            new FfmpegBinary($executor),
            $executor,
            $this->getService(LibraryImageService::class),
            $this->getService(CmsMediaLibrary::class),
        );
    }

    /**
     * @return array{LibraryImage, CmsVideoConversion} a phone video stored as sent, and its row as a queued conversion leaves it
     */
    private function waitingVideo(): array
    {
        $upload = QuickTimeVideos::small();
        $video = $this->getService(LibraryImageService::class)->createImage($upload, $upload->getClientOriginalName(), $this->locale());
        $this->stored[] = (int) $video->getId();
        $this->getService(CmsMediaLibrary::class)->tag($video);

        $row = (new CmsVideoConversion())->setLibraryImageId($video->getId())->setStatus(VideoConversionStatus::Processing->value);
        $row->save();

        return [$video, $row];
    }
}
