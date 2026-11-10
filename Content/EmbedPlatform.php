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

namespace TheliaCMS\Content;

/**
 * The video platforms an `embed` prop knows: a closed list, each with the
 * shape of its identifiers. The same patterns are written in the script the
 * module serves (assets/embed.js), which checks them again before it builds
 * a player.
 */
enum EmbedPlatform: string
{
    case YouTube = 'youtube';
    case Vimeo = 'vimeo';
    case Dailymotion = 'dailymotion';

    /** The pattern of an identifier, anchored. */
    public function idPattern(): string
    {
        return match ($this) {
            self::YouTube => '/^[A-Za-z0-9_-]{11}$/D',
            self::Vimeo => '/^[0-9]{1,12}$/D',
            self::Dailymotion => '/^x[a-z0-9]{4,9}$/D',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::YouTube => 'YouTube',
            self::Vimeo => 'Vimeo',
            self::Dailymotion => 'Dailymotion',
        };
    }

    /** The public page of a video, built from a checked identifier: the link of the `noscript` fallback and what the editor shows back. */
    public function pageUrl(string $id): string
    {
        return match ($this) {
            self::YouTube => 'https://www.youtube.com/watch?v='.$id,
            self::Vimeo => 'https://vimeo.com/'.$id,
            self::Dailymotion => 'https://www.dailymotion.com/video/'.$id,
        };
    }

    public function isId(string $id): bool
    {
        return 1 === preg_match($this->idPattern(), $id);
    }
}
