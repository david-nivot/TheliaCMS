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

use TheliaLibrary\Service\Svg\UnreadableSvgReason;

/**
 * Why a file sent to the library was not stored.
 */
enum MediaUploadRefusal: string
{
    case Format = 'format';
    case TooLarge = 'too-large';
    case TooHeavy = 'too-heavy';
    case VideoTooHeavy = 'video-too-heavy';
    case TooManyPixels = 'too-many-pixels';
    case Unreadable = 'unreadable';
    case Failed = 'failed';
    case SvgDoctype = 'svg-doctype';
    case SvgEntity = 'svg-entity';
    case SvgNotUtf8 = 'svg-not-utf8';
    case SvgMalformed = 'svg-malformed';
    case SvgNotSvg = 'svg-not-svg';
    case SvgTooLarge = 'svg-too-large';
    case SvgNoDimensions = 'svg-no-dimensions';

    /** The refusal that says why the library cannot read an SVG. */
    public static function forUnreadableSvg(UnreadableSvgReason $reason): self
    {
        return match ($reason) {
            UnreadableSvgReason::Doctype => self::SvgDoctype,
            UnreadableSvgReason::Entity => self::SvgEntity,
            UnreadableSvgReason::NotUtf8 => self::SvgNotUtf8,
            UnreadableSvgReason::MalformedXml => self::SvgMalformed,
            UnreadableSvgReason::NotSvgRoot => self::SvgNotSvg,
            UnreadableSvgReason::TooLarge => self::SvgTooLarge,
            UnreadableSvgReason::NoDimensions => self::SvgNoDimensions,
        };
    }

    /** What the editor is told, a key of the translations of the module. */
    public function message(): string
    {
        return match ($this) {
            self::Format => 'Only JPEG, PNG, WebP and SVG images, or MP4 videos, can be uploaded.',
            self::TooLarge => 'The file is larger than the server accepts.',
            self::TooHeavy => 'The image is heavier than 10 MB.',
            self::VideoTooHeavy => 'The video is heavier than 50 MB.',
            self::TooManyPixels => 'The image has more than 25 million pixels.',
            self::Unreadable => 'The file is not a readable image.',
            self::Failed => 'The file did not arrive whole. Send it again.',
            self::SvgDoctype => 'The SVG declares entities or an internal subset, which is refused. Export it again without them.',
            self::SvgEntity => 'The SVG declares entities, which are refused. Export it again without them.',
            self::SvgNotUtf8 => 'The SVG is not encoded in UTF-8.',
            self::SvgMalformed => 'The SVG is not valid XML.',
            self::SvgNotSvg => 'The file is XML but not an SVG: its root element is not svg.',
            self::SvgTooLarge => 'The SVG is too large to be read safely (5 MB or 50,000 elements at most).',
            self::SvgNoDimensions => 'The SVG has no size: give it a viewBox, or a width and a height.',
        };
    }
}
