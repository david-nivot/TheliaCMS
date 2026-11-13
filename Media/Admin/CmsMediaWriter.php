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

namespace TheliaCMS\Media\Admin;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Icon\IconCacheInvalidator;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Icon\IconNameTakenException;
use TheliaCMS\Media\Icon\InvalidIconNameException;
use TheliaCMS\Media\Svg\PreparedSvgStore;
use TheliaCMS\Media\Svg\SvgUpload;
use TheliaCMS\Media\Video\VideoConversions;
use TheliaCMS\Media\Video\VideoIntake;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Exception\UnsupportedLibraryImageException;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Service\LibraryImageService;
use TheliaLibrary\Service\Svg\SvgSanitizer;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;
use TheliaLibrary\Service\Svg\UnreadableSvgReason;

/**
 * Every write the media screens perform. Nothing is persisted in a controller.
 */
final readonly class CmsMediaWriter
{
    public function __construct(
        private LibraryImageService $images,
        private CmsMediaLibrary $library,
        private SvgSanitizerInterface $svgSanitizer,
        private PreparedSvgStore $preparedSvgs,
        private IconNames $iconNames,
        private IconCacheInvalidator $iconCache,
        private VideoIntake $videoIntake,
        private VideoConversions $videoConversions,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Stores uploaded files and marks them as CMS content, each on its own:
     * a file of another format, too large or not an image is refused and
     * the others are stored.
     *
     * The formats are the ones of the module, not all the ones the library
     * would take: the screens say JPEG, PNG, WebP and SVG, and MP4 videos. An
     * SVG is not stored here: it is cleaned once, so a file that cannot be read
     * is refused at once, then kept apart for the preparation screen, where the
     * editor sees what the cleaning removed before it enters the library.
     *
     * With ffmpeg on the server a phone video (MOV, WebM, an MP4 of up to 100 MB)
     * is stored as sent and queued for its conversion: it is listed as
     * processing and replaced, under the same id, by a web MP4. Without ffmpeg
     * only an MP4 of up to 50 MB is taken, as it is.
     *
     * An `$imagesOnly` caller (a section, a page: places that draw a picture)
     * has an MP4 refused as a format it does not take, and nothing is stored.
     *
     * @param list<UploadedFile> $files
     */
    public function add(array $files, string $locale, bool $imagesOnly = false): MediaUpload
    {
        $stored = [];
        $refused = [];
        $prepared = [];

        foreach ($files as $file) {
            if ($file->isValid() && SvgUpload::isSvg($file)) {
                try {
                    $token = $this->prepareSvg($file);

                    if (null === $token) {
                        $refused[] = ['name' => $file->getClientOriginalName(), 'refusal' => MediaUploadRefusal::Failed];
                    } else {
                        $prepared[] = ['token' => $token, 'name' => $file->getClientOriginalName()];
                    }
                } catch (UnreadableSvgException $exception) {
                    $refused[] = ['name' => $file->getClientOriginalName(), 'refusal' => MediaUploadRefusal::forUnreadableSvg($exception->reason)];
                }

                continue;
            }

            $refusal = $this->refusalOf($file, $imagesOnly);

            if (null === $refusal) {
                try {
                    $image = $this->images->createImage($file, $file->getClientOriginalName(), $locale);
                    $this->library->tag($image);
                    $this->convertIfVideo($image);
                    $stored[] = $image;

                    continue;
                } catch (UnsupportedLibraryImageException) {
                    $refusal = MediaUploadRefusal::Unreadable;
                }
            }

            $refused[] = ['name' => $file->getClientOriginalName(), 'refusal' => $refusal];
        }

        return new MediaUpload($stored, $refused, $prepared);
    }

    /**
     * Cleans an SVG once, to refuse what cannot be read, and keeps the bytes as
     * they were sent: the preparation screen starts again from them.
     *
     * @return string|null the token of the file on the preparation screen; null when the file cannot be read, which did not arrive whole
     *
     * @throws UnreadableSvgException
     */
    public function prepareSvg(UploadedFile $file): ?string
    {
        // Read nothing that the library would refuse for its weight.
        if ($file->getSize() > SvgSanitizer::MAXIMUM_BYTES) {
            throw new UnreadableSvgException(UnreadableSvgReason::TooLarge);
        }

        // A file that cannot be read did not arrive whole: not a broken XML.
        $content = @file_get_contents($file->getPathname());

        if (false === $content) {
            return null;
        }

        $this->svgSanitizer->sanitize($content);

        return $this->preparedSvgs->put($content, $file->getClientOriginalName());
    }

    /**
     * Stores an SVG the editor has seen, under the name of the file it came in,
     * and gives it an icon name when one is asked for.
     *
     * @param string|null $iconName the name, without its prefix; the caller has checked that it is valid and free
     *
     * @throws UnreadableSvgException
     * @throws InvalidIconNameException
     * @throws IconNameTakenException   when another image took the name since it was checked
     */
    public function addPreparedSvg(string $svg, string $name, string $locale, ?string $iconName = null): LibraryImage
    {
        $image = $this->withTemporarySvg($svg, $name, fn (File $file): LibraryImage => $this->images->createImage($file, $name, $locale));
        $this->library->tag($image);

        if (null !== $iconName) {
            try {
                $this->iconNames->assign((int) $image->getId(), $iconName);
            } catch (\Throwable $exception) {
                // The file was added for its name: without it, it is not added, whatever stopped the name.
                // A cleanup that fails is logged and does not hide what stopped the name.
                try {
                    $this->images->deleteImage($image->getId());
                } catch (\Throwable $cleanup) {
                    $this->logger->error('The image {image} added for the icon name "{name}" could not be deleted after the name was refused.', [
                        'image' => $image->getId(),
                        'name' => $iconName,
                        'exception' => $cleanup,
                    ]);
                }

                throw $exception;
            }
        }

        return $image;
    }

    /**
     * Puts an SVG the editor has seen in the place of the file of an image,
     * which keeps its row and therefore its address.
     *
     * @throws UnreadableSvgException
     */
    public function replaceWithPreparedSvg(LibraryImage $image, string $svg, string $name, string $locale): void
    {
        $this->withTemporarySvg($svg, $name, fn (File $file): LibraryImage => $this->images->updateImage($image->getId(), $file, null, $locale));

        // An icon draws the new file: what ux-icons kept of it, and the file the names remember, are stale.
        $this->iconNames->reset();
        $iconName = $this->iconNames->nameOf($image->getId());

        if (null !== $iconName) {
            $this->iconCache->forget($iconName);
        }
    }

    /**
     * The file handed to the library bears the name the editor sent: the
     * library titles an image after it where the language has no title yet,
     * as it does for a file sent straight from the sheet.
     *
     * @param \Closure(File): LibraryImage $store
     */
    private function withTemporarySvg(string $svg, string $name, \Closure $store): LibraryImage
    {
        // The library moves the file it is given into its own directory.
        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'theliacms-svg-'.bin2hex(random_bytes(6));
        $stem = trim((string) preg_replace('/[^\w.\- ]+/u', '-', pathinfo($name, \PATHINFO_FILENAME)), ' .-');
        $path = $directory.\DIRECTORY_SEPARATOR.('' === $stem ? 'image' : $stem).'.svg';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($path, $svg);

        try {
            return $store(new File($path));
        } finally {
            // Moved once stored; left behind when refused.
            $filesystem->remove($directory);
        }
    }

    /**
     * A video has no alternative text and is never decorative: those two are
     * left as they are, whatever the data holds.
     *
     * @param array{title: ?string, alt?: ?string, caption: ?string, decorative?: ?bool, tags: ?string, file: ?UploadedFile} $data
     */
    public function save(LibraryImage $image, string $locale, array $data): void
    {
        // An SVG does not replace the file here: it goes through the
        // preparation screen first, so the editor sees what is stored.
        if ($data['file'] instanceof UploadedFile && !SvgUpload::isSvg($data['file'])) {
            // Replacing goes through the library so the old file is removed and
            // the new one is measured; the row, and therefore the URL every
            // published page points at, is kept.
            $this->images->updateImage($image->getId(), $data['file'], null, $locale);
            $image->reload();

            if ($this->convertIfVideo($image)) {
                $image->reload();
            }
        }

        $image->setLocale($locale)->setCaption($data['caption']);

        // A form without the two fields (a video, an icon) leaves them as they are.
        if (!$image->isVideo() && \array_key_exists('alt', $data)) {
            $image->setDecorative(true === ($data['decorative'] ?? null) ? 1 : 0)->setAlt($data['alt'] ?? null);
        }

        $title = null === $data['title'] ? '' : trim($data['title']);

        if ('' !== $title) {
            $image->setTitle($title);
        }

        $image->save();

        $this->library->retag($image, $this->splitTags($data['tags']));
    }

    /**
     * What the image shows in a language, or that it is decorative: the two
     * answers of the describe queue.
     */
    public function describe(LibraryImage $image, string $locale, ?string $alt, bool $decorative): void
    {
        if ($image->isVideo()) {
            return;
        }

        $image->setDecorative($decorative ? 1 : 0);
        $image->setLocale($locale)->setAlt($decorative ? null : $alt);
        $image->save();
    }

    /**
     * Decorative or not, for every language at once; the texts the image has
     * are kept, for the day it carries information again.
     */
    public function setDecorative(LibraryImage $image, bool $decorative): void
    {
        if ($image->isVideo()) {
            return;
        }

        $image->setDecorative($decorative ? 1 : 0)->save();
    }

    public function delete(LibraryImage $image): void
    {
        // The name goes after the image: a row left behind by a failure is ignored when names are read.
        $this->images->deleteImage($image->getId());
        $this->iconNames->remove($image->getId());
        $this->videoConversions->forget((int) $image->getId());
    }

    /**
     * Queues the conversion of a video just stored, when the server converts.
     * The conversion may have run already (a server without a queue runs it in
     * the request): the video is read again from the library.
     */
    private function convertIfVideo(LibraryImage $image): bool
    {
        if (!$image->isVideo() || !$this->videoIntake->converts()) {
            return false;
        }

        $this->videoConversions->queue($image);
        $image->reload();

        return true;
    }

    private function refusalOf(UploadedFile $file, bool $imagesOnly): ?MediaUploadRefusal
    {
        if (!$file->isValid()) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? MediaUploadRefusal::TooLarge
                : MediaUploadRefusal::Failed;
        }

        // The type read in the content of the file decides, never its extension.
        if (!$imagesOnly && \in_array($file->getMimeType(), $this->videoIntake->mimeTypes(), true)) {
            return $file->getSize() > $this->videoIntake->maxBytes()
                ? ($this->videoIntake->converts() ? MediaUploadRefusal::RawVideoTooHeavy : MediaUploadRefusal::VideoTooHeavy)
                : null;
        }

        // A phone video the server cannot convert: said as it is, not as an unknown format.
        if (!$imagesOnly && \in_array($file->getMimeType(), CmsMediaType::RAW_VIDEO_MIME_TYPES, true)) {
            return MediaUploadRefusal::VideoNeedsFfmpeg;
        }

        if (!\in_array($file->getMimeType(), CmsMediaType::ACCEPTED_MIME_TYPES, true)) {
            return MediaUploadRefusal::Format;
        }

        if ($file->getSize() > CmsMediaType::MAX_IMAGE_BYTES) {
            return MediaUploadRefusal::TooHeavy;
        }

        // The header says how large the picture is without decoding it; a file
        // whose header cannot be read is left to the library, which refuses it.
        $dimensions = @getimagesize($file->getPathname());

        return false !== $dimensions && $dimensions[0] * $dimensions[1] > CmsMediaType::MAX_IMAGE_PIXELS ? MediaUploadRefusal::TooManyPixels : null;
    }

    /**
     * @return list<string>
     */
    private function splitTags(?string $tags): array
    {
        if (null === $tags || '' === trim($tags)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $tag): bool => '' !== $tag));
    }
}
