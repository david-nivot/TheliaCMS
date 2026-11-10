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

/**
 * Where a video sent to the media library stands. A video without a row is a
 * file that was never converted (stored before the conversion existed, or an
 * MP4 sent without ffmpeg on the server): it is read as ready.
 */
enum VideoConversionStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /** Whether a page may use the video: only a converted one is. */
    public function isUsable(): bool
    {
        return self::Ready === $this;
    }
}
