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

namespace TheliaCMS\Media\Video;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Thelia\Model\Lang;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsVideoConversion;
use TheliaCMS\Model\CmsVideoConversionQuery;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\Service\LibraryImageService;
use TheliaLibrary\TheliaLibrary;

/**
 * Converts a video of the media library for the web, and puts it back in the
 * place of the file that was sent: same row, so same id, so the same address
 * (`/video-library/<id>.mp4`) for every page that already points at it.
 *
 * A job runs once to its end: a row that is no longer processing (a job
 * delivered twice, a video converted meanwhile) is left as it is. A failure is
 * recorded on the row and never thrown: a queue would play it again, and the
 * same file fails the same way.
 */
#[AsMessageHandler]
final readonly class ConvertVideoHandler
{
    /** Ten minutes of a phone video take about two on a server: half an hour is a file that will not end. */
    private const int CONVERSION_TIMEOUT = 1800;

    private const int POSTER_TIMEOUT = 120;

    /** The second of the video the poster is taken from. */
    private const int POSTER_SECOND = 2;

    public function __construct(
        private FfmpegBinary $ffmpeg,
        private ProcessExecutor $executor,
        private LibraryImageService $images,
        private CmsMediaLibrary $library,
    ) {
    }

    public function __invoke(ConvertVideo $message): void
    {
        $row = CmsVideoConversionQuery::create()->findPk($message->conversionId);

        if (!$row instanceof CmsVideoConversion || VideoConversionStatus::Processing->value !== $row->getStatus()) {
            return;
        }

        $video = LibraryImageQuery::create()->findPk($row->getLibraryImageId());

        // The video was deleted while it waited.
        if (!$video instanceof LibraryImage) {
            $row->delete();

            return;
        }

        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'theliacms-video-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();

        try {
            $filesystem->mkdir($directory);
            $this->convert($row, $video, $directory);
        } catch (\Throwable $exception) {
            $this->fail($row, VideoFailure::Unexpected, $exception::class);
        } finally {
            $filesystem->remove($directory);
        }
    }

    private function convert(CmsVideoConversion $row, LibraryImage $video, string $directory): void
    {
        $source = TheliaLibrary::getImageDirectory().$video->getFileName();

        if (null === $video->getFileName() || !is_file($source)) {
            $this->fail($row, VideoFailure::FileMissing);

            return;
        }

        if (!$this->ffmpeg->isAvailable()) {
            $this->fail($row, VideoFailure::FfmpegMissing);

            return;
        }

        $converted = $directory.\DIRECTORY_SEPARATOR.'converted.mp4';
        $outcome = $this->executor->run($this->ffmpeg->conversionCommand($source, $converted), self::CONVERSION_TIMEOUT);

        // A file that ffmpeg left empty is no video, whatever its exit code.
        if (!$outcome->succeeded() || !is_file($converted) || 0 === filesize($converted)) {
            $this->fail($row, VideoFailure::ConversionFailed, $outcome->errorOutput);

            return;
        }

        // The poster is taken from the converted file, which is already
        // upright and at its final size, and before it is moved into the library.
        $posterFile = $this->takePoster($converted, $directory);

        $locale = Lang::getDefaultLanguage()->getLocale();
        $title = $video->setLocale($locale)->getTitle();

        $this->images->updateImage($video->getId(), new File($converted), null, $locale);

        $row->setPosterImageId($this->storePoster($posterFile, $title, $locale))
            ->setStatus(VideoConversionStatus::Ready->value)
            ->setFailure(null)
            ->setDetail(null)
            ->save();
    }

    /**
     * One frame about two seconds in, or the first one of a clip that is shorter.
     * A video without a poster is still a video: nothing here fails the job.
     */
    private function takePoster(string $converted, string $directory): ?string
    {
        $poster = $directory.\DIRECTORY_SEPARATOR.'poster.jpg';

        foreach ([self::POSTER_SECOND, 0] as $seconds) {
            $this->executor->run($this->ffmpeg->posterCommand($converted, $poster, $seconds), self::POSTER_TIMEOUT);

            if (is_file($poster) && filesize($poster) > 0) {
                return $poster;
            }
        }

        return null;
    }

    /**
     * The poster joins the library as an image of the CMS, decorative: it
     * shows what the video shows and the video carries the meaning.
     */
    private function storePoster(?string $posterFile, ?string $videoTitle, string $locale): ?int
    {
        if (null === $posterFile) {
            return null;
        }

        try {
            $name = trim(pathinfo((string) $videoTitle, \PATHINFO_FILENAME));
            $poster = $this->images->createImage(new File($posterFile), ('' === $name ? 'video' : $name).' (poster).jpg', $locale);
            $poster->setDecorative(1)->save();
            $this->library->tag($poster);

            return (int) $poster->getId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function fail(CmsVideoConversion $row, VideoFailure $failure, string $detail = ''): void
    {
        // The last line of what ffmpeg said is the one that names the error.
        $lines = preg_split('/\R/', trim($detail)) ?: [];
        $detail = mb_substr(trim((string) end($lines)), 0, 255);

        $row->setStatus(VideoConversionStatus::Failed->value)
            ->setFailure($failure->value)
            ->setDetail('' === $detail ? null : $detail)
            ->save();
    }
}
