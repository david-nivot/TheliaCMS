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

use TheliaCMS\Media\Video\VideoConversionStatus;

/**
 * An image, or a video, as the media screens show it.
 */
final readonly class MediaItem
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $url,
        public ?string $thumbnailUrl,
        public ?string $alt,
        public bool $decorative,
        public ?string $caption,
        public ?int $width,
        public ?int $height,
        public ?int $fileSize,
        public ?string $format,
        public array $tags,
        public int $usageCount,
        public bool $video = false,
        public VideoConversionStatus $conversion = VideoConversionStatus::Ready,
        public ?string $conversionProblem = null,
    ) {
    }

    /**
     * Whether the image can be published as it is.
     *
     * An image that says nothing must say so explicitly (`alt=""`): an empty
     * alt attribute and a missing description are indistinguishable to a
     * screen reader, so the choice is recorded rather than guessed.
     */
    public function isDescribed(): bool
    {
        // A video has no alternative text to give: it never waits for one.
        return $this->video || $this->decorative || (null !== $this->alt && '' !== trim($this->alt));
    }

    public function dimensions(): ?string
    {
        if (null === $this->width || null === $this->height) {
            return null;
        }

        return $this->width.' × '.$this->height;
    }

    /**
     * File weight in the largest unit that keeps it readable.
     */
    public function weight(): ?string
    {
        if (null === $this->fileSize) {
            return null;
        }

        if ($this->fileSize < 1024) {
            return $this->fileSize.' B';
        }

        if ($this->fileSize < 1024 * 1024) {
            return round($this->fileSize / 1024).' kB';
        }

        return round($this->fileSize / (1024 * 1024), 1).' MB';
    }
}
