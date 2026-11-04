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
 * The proportions an editor picks for the pictures of a section, in place of a
 * width and a height in pixels: the image as it is, or a ratio from a short
 * list. The columns add "band", the strip of fixed height of a row of logos.
 *
 * A ratio is written to the picture as the box `data-cms-crop` knows, one
 * within the bounds of the Image section, so the publication serves the zone
 * of the file that fills it, with the arithmetic of `ImageFrame`.
 */
final class ImageProportions
{
    public const string ORIGINAL = 'original';

    public const string BAND = 'band';

    /** Ratio => box in pixels, in the bounds of the box of the Image section. */
    private const array BOXES = [
        '16:9' => [1600, 900],
        '4:3' => [1600, 1200],
        '3:2' => [1500, 1000],
        '1:1' => [1000, 1000],
        '3:4' => [1200, 1600],
    ];

    /**
     * @return list<string>
     */
    public static function ratios(): array
    {
        return array_keys(self::BOXES);
    }

    public static function isRatio(mixed $value): bool
    {
        return \is_string($value) && isset(self::BOXES[$value]);
    }

    /**
     * The box of a ratio, null for anything else (the image as it is, the band).
     *
     * @return array{int, int}|null width, height
     */
    public static function box(mixed $value): ?array
    {
        return self::isRatio($value) ? self::BOXES[$value] : null;
    }

    /**
     * The `aspect-ratio` of a ratio: `16/9`.
     */
    public static function aspectRatio(string $ratio): string
    {
        return str_replace(':', '/', $ratio);
    }
}
