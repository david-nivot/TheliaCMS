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
 * A video of a platform, as an `embed` prop keeps it: `platform:id`.
 *
 * Read from the address an author pastes, which is never kept nor printed
 * back: only the host (from a closed list, over https, with no user, port or
 * odd character) and the identifier (a strict pattern per platform) are
 * taken from it, and everything the page draws is built from those two.
 */
final readonly class EmbedAddress
{
    private function __construct(
        public EmbedPlatform $platform,
        public string $id,
    ) {
    }

    /**
     * The video an address names, or the value this class kept earlier
     * (`youtube:dQw4w9WgXcQ`); null for anything else.
     */
    public static function from(string $address): ?self
    {
        $address = trim($address);

        if ('' === $address || \strlen($address) > 2048 || !mb_check_encoding($address, 'UTF-8') || 1 === preg_match('/[\x00-\x20\x7f-\x{9f}\\\\]/u', $address)) {
            return null;
        }

        if (1 === preg_match('/^(youtube|vimeo|dailymotion):([^:]+)$/D', $address, $stored)) {
            $platform = EmbedPlatform::from($stored[1]);

            return $platform->isId($stored[2]) ? new self($platform, $stored[2]) : null;
        }

        $parts = parse_url($address);

        if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || !isset($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        return match ($host) {
            'youtube.com', 'www.youtube.com', 'm.youtube.com' => self::youtube('/watch' === $path ? $query['v'] ?? null : self::segment($path, '#^/(?:shorts|embed|live)/([^/]+)$#')),
            'youtube-nocookie.com', 'www.youtube-nocookie.com' => self::youtube(self::segment($path, '#^/embed/([^/]+)$#')),
            'youtu.be' => self::youtube(self::segment($path, '#^/([^/]+)$#')),
            'vimeo.com', 'www.vimeo.com' => self::make(EmbedPlatform::Vimeo, self::segment($path, '#^/(?:channels/[A-Za-z0-9_-]+/)?([0-9]+)$#')),
            'player.vimeo.com' => self::make(EmbedPlatform::Vimeo, self::segment($path, '#^/video/([0-9]+)$#')),
            'dailymotion.com', 'www.dailymotion.com' => self::make(EmbedPlatform::Dailymotion, self::segment($path, '#^/(?:embed/)?video/([a-z0-9]+)(?:_[^/]*)?$#')),
            'dai.ly' => self::make(EmbedPlatform::Dailymotion, self::segment($path, '#^/([^/]+)$#')),
            default => null,
        };
    }

    /** What an `embed` prop stores. */
    public function value(): string
    {
        return $this->platform->value.':'.$this->id;
    }

    public function pageUrl(): string
    {
        return $this->platform->pageUrl($this->id);
    }

    private static function youtube(mixed $id): ?self
    {
        return self::make(EmbedPlatform::YouTube, $id);
    }

    private static function make(EmbedPlatform $platform, mixed $id): ?self
    {
        return \is_string($id) && $platform->isId($id) ? new self($platform, $id) : null;
    }

    private static function segment(string $path, string $pattern): ?string
    {
        return 1 === preg_match($pattern, $path, $match) ? $match[1] : null;
    }
}
