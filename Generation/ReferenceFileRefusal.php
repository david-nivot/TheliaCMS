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
 * Why a file joined to a brief or to a change was not taken.
 */
enum ReferenceFileRefusal: string
{
    case Format = 'format';
    case TooLarge = 'too-large';
    case TooLong = 'too-long';
    case Unreadable = 'unreadable';
    case Empty = 'empty';
    case Failed = 'failed';
    case TooHeavy = 'too-heavy';
    case UnreadablePdf = 'unreadable-pdf';
    case NoText = 'no-text';
    case JudgeLeftOut = 'judge-left-out';
    case JudgeLeftOutOfComponents = 'judge-left-out-of-components';
    case HeavyPdf = 'heavy-pdf';

    /** What the editor is told, a key of the translations of the module. */
    public function message(): string
    {
        return match ($this) {
            self::Format => 'Only a .md, .txt or .pdf file can be joined.',
            self::TooLarge => 'The file is larger than the server accepts.',
            self::TooLong => 'The file is longer than 100,000 characters.',
            self::Unreadable => 'The file is not a readable text file.',
            self::Empty => 'The file says nothing.',
            self::Failed => 'The file did not arrive whole. Send it again.',
            self::TooHeavy => 'The file is larger than 5 MB.',
            self::UnreadablePdf => 'The PDF cannot be read: it is damaged or protected by a password.',
            self::NoText => 'The PDF holds no text: a scanned document is not read. Join its text as a .txt file.',
            self::HeavyPdf => 'The PDF is too heavy to read: once decompressed, its pages hold far more than the generator reads. Join its text as a .txt file.',
            self::JudgeLeftOut => 'A joined file is checked by the guardrail reference_file, which the AI settings of the usage theliacms.page_generation leave out. Add it there, or join no file.',
            self::JudgeLeftOutOfComponents => 'A joined file is checked by the guardrail reference_file, which the AI settings of the usage theliacms.component leave out. Add it there, or join no file.',
        };
    }
}
