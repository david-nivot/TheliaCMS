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
use TheliaCMS\Media\Video\VideoConversionStatus;
use TheliaCMS\Media\Video\VideoFailure;
use TheliaCMS\Model\CmsVideoConversion;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;
use TheliaLibrary\Model\LibraryImage;

/**
 * The state of a video in the media library: shown on its card and on its
 * sheet, and a video that is not ready is not offered to a page.
 */
final class VideoConversionScreenTest extends AdminScreenTestCase
{
    public function testTheListShowsProcessingAndFailedVideosAndNothingForAReadyOne(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $processing = $this->video(VideoConversionStatus::Processing);
        $failed = $this->video(VideoConversionStatus::Failed, VideoFailure::ConversionFailed);
        $ready = Mp4Videos::row($library, $this->locale(), 'Converted');
        $unconverted = Mp4Videos::row($library, $this->locale(), 'Old');

        $list = $this->screen('/admin/cms/media');
        $state = static fn (int $id): ?string => ($badge = $list->filter(\sprintf('[data-testid="cms-media-card-%d"] [data-testid="cms-media-video-state"]', $id)))->count() > 0 ? $badge->attr('data-state') : null;

        self::assertSame('processing', $state((int) $processing->getId()));
        self::assertSame('failed', $state((int) $failed->getId()));
        self::assertNull($state((int) $unconverted->getId()), 'A video with no row is a web file.');
        self::assertNull($state((int) $ready->getId()));
    }

    public function testTheSheetSaysWhyAConversionFailed(): void
    {
        $failed = $this->video(VideoConversionStatus::Failed, VideoFailure::FfmpegMissing);

        $sheet = $this->screen('/admin/cms/media/'.$failed->getId());

        self::assertSame('failed', $sheet->filter('[data-testid="cms-media-video-state"]')->attr('data-state'));
        self::assertStringContainsString('ffmpeg', $sheet->filter('[data-testid="cms-media-video-state"]')->text());
    }

    public function testThePickerOffersReadyVideosOnly(): void
    {
        $library = $this->getService(CmsMediaLibrary::class);
        $ready = Mp4Videos::row($library, $this->locale(), 'Ready');
        $this->video(VideoConversionStatus::Processing);
        $this->video(VideoConversionStatus::Failed, VideoFailure::ConversionFailed);

        $this->client->request('GET', '/admin/cms/media/picker?kind=video', [], [], self::XHR);
        $items = json_decode((string) $this->client->getResponse()->getContent(), true)['items'];

        self::assertSame([(int) $ready->getId()], array_column($items, 'id'));
        self::assertSame('ready', $items[0]['conversion']);
    }

    private function video(VideoConversionStatus $status, ?VideoFailure $failure = null): LibraryImage
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale(), $status->value);
        (new CmsVideoConversion())
            ->setLibraryImageId($video->getId())
            ->setStatus($status->value)
            ->setFailure($failure?->value)
            ->save();

        return $video;
    }
}
