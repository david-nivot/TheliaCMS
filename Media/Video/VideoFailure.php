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
 * Why a conversion failed, as the code kept on its row.
 */
enum VideoFailure: string
{
    case FfmpegMissing = 'ffmpeg-missing';
    case ConversionFailed = 'conversion-failed';
    case FileMissing = 'file-missing';
    case Unexpected = 'unexpected';

    /** What the editor is told, a key of the translations of the module. */
    public function message(): string
    {
        return match ($this) {
            self::FfmpegMissing => 'The video could not be converted: ffmpeg is not available on the server.',
            self::ConversionFailed => 'The video could not be converted. It may be damaged or in a format ffmpeg cannot read.',
            self::FileMissing => 'The video file is missing from the library.',
            self::Unexpected => 'The video could not be converted, for a reason the server did not give.',
        };
    }
}
