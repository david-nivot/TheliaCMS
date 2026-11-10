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

use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaLibrary\Service\LibraryImageFileNamer;

/**
 * What the media library takes as a video, which depends on the server: with
 * ffmpeg (and a TheliaLibrary that stores MOV and WebM files), any phone video
 * up to the raw ceiling, converted in the background; without, MP4 up to 50 MB
 * as it has always been.
 */
final readonly class VideoIntake
{
    public function __construct(private FfmpegBinary $ffmpeg)
    {
    }

    public function converts(): bool
    {
        return $this->libraryStoresRawVideos() && $this->ffmpeg->isAvailable();
    }

    /**
     * The video types an upload is accepted in.
     *
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return $this->converts() ? [...CmsMediaType::ACCEPTED_VIDEO_MIME_TYPES, ...CmsMediaType::RAW_VIDEO_MIME_TYPES] : CmsMediaType::ACCEPTED_VIDEO_MIME_TYPES;
    }

    public function maxBytes(): int
    {
        return $this->converts() ? CmsMediaType::MAX_RAW_VIDEO_BYTES : CmsMediaType::MAX_VIDEO_BYTES;
    }

    /**
     * Whether the PHP limits of the server let the largest raw video through:
     * a body above `post_max_size` is dropped whole, and the browser is only
     * told 403 or nothing.
     */
    public function phpLimitsAllow(int $effectiveLimit): bool
    {
        return $effectiveLimit >= $this->maxBytes();
    }

    /**
     * An older TheliaLibrary stores MP4 only: a MOV is refused there as an
     * unreadable file, so nothing is promised.
     */
    private function libraryStoresRawVideos(): bool
    {
        return [] === array_diff(CmsMediaType::RAW_VIDEO_MIME_TYPES, LibraryImageFileNamer::VIDEO_MIME_TYPES);
    }
}
