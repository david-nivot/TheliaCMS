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

namespace TheliaCMS\Generation\Component;

/**
 * A picture or a video of the media library, as the generator of components
 * is told of it: what its sample may point at.
 */
final readonly class SampleMediaItem
{
    private const int TEXT_LENGTH = 120;

    public function __construct(
        public int $id,
        public bool $video,
        public string $text,
        public ?int $width,
        public ?int $height,
    ) {
    }

    /**
     * One line of the user message. The text is written by whoever filled the
     * library: one line, cut short.
     */
    public function line(): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->text));

        if (mb_strlen($text) > self::TEXT_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::TEXT_LENGTH - 1)).'…';
        }

        $size = null !== $this->width && null !== $this->height && $this->width > 0 && $this->height > 0
            ? \sprintf(', %d × %d', $this->width, $this->height)
            : '';

        return \sprintf('- %d (%s%s): %s', $this->id, $this->video ? 'video' : 'image', $size, '' === $text ? 'no description' : $text);
    }
}
