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

/**
 * Why a file sent to the library was not stored.
 */
enum MediaUploadRefusal: string
{
    case Format = 'format';
    case TooLarge = 'too-large';
    case TooHeavy = 'too-heavy';
    case TooManyPixels = 'too-many-pixels';
    case Unreadable = 'unreadable';
    case Failed = 'failed';

    /** What the editor is told, a key of the translations of the module. */
    public function message(): string
    {
        return match ($this) {
            self::Format => 'Only JPEG, PNG and WebP images can be uploaded.',
            self::TooLarge => 'The file is larger than the server accepts.',
            self::TooHeavy => 'The image is heavier than 10 MB.',
            self::TooManyPixels => 'The image has more than 25 million pixels.',
            self::Unreadable => 'The file is not a readable image.',
            self::Failed => 'The file did not arrive whole. Send it again.',
        };
    }
}
