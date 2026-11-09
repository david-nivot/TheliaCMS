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

namespace TheliaCMS\Generation;

/**
 * Why a file joined to a brief was not taken.
 */
enum ReferenceFileRefusal: string
{
    case Format = 'format';
    case TooLarge = 'too-large';
    case TooLong = 'too-long';
    case Unreadable = 'unreadable';
    case Empty = 'empty';
    case Failed = 'failed';

    /** What the editor is told, a key of the translations of the module. */
    public function message(): string
    {
        return match ($this) {
            self::Format => 'Only a .md or .txt file can be joined.',
            self::TooLarge => 'The file is larger than the server accepts.',
            self::TooLong => 'The file is longer than 100,000 characters.',
            self::Unreadable => 'The file is not a readable text file.',
            self::Empty => 'The file says nothing.',
            self::Failed => 'The file did not arrive whole. Send it again.',
        };
    }
}
