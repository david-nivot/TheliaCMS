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

namespace TheliaCMS\Media;

/**
 * A library image as the publish pipeline needs it: its identifier, its stored
 * format and the size it really has on disk.
 */
final readonly class MediaFile
{
    public function __construct(
        public int $id,
        public string $format,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }

    /**
     * Whether a stored format is a vector one: the library serves it as it is,
     * with no resized copy and no other format, so an address built for a
     * raster image would be refused.
     */
    public static function isVectorFormat(string $format): bool
    {
        return 'svg' === strtolower($format);
    }

    public function isVector(): bool
    {
        return self::isVectorFormat($this->format);
    }
}
