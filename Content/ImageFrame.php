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
 * The part of a picture that fills a box of another proportion.
 *
 * The editor keeps a focal point and a zoom (percent of the picture, steps of
 * one and ten); the media library serves a zone of pixels `x,y,w,h`. This class
 * turns the first into the second. It knows nothing of the database: the size
 * of the original is an argument, so the arithmetic is tested on its own.
 *
 * The section says what the editor chose with `data-cms-crop` on the `<img>`
 * (`focus x, focus y, zoom, box width, box height`); the rewriter, which knows
 * the size of the file, asks `zone()` and takes the attribute out. A box of
 * `0,0` is the proportions of the picture itself: the zone is then the picture
 * zoomed, with no crop.
 */
final class ImageFrame
{
    public const string ATTRIBUTE = 'data-cms-crop';

    /**
     * The attribute to write on the picture of a box that crops it.
     */
    public static function attribute(int $focusX, int $focusY, int $zoom, int $boxWidth, int $boxHeight): string
    {
        return \sprintf(' %s="%d,%d,%d,%d,%d"', self::ATTRIBUTE, $focusX, $focusY, $zoom, $boxWidth, $boxHeight);
    }

    /**
     * What an attribute says, or null when it is not five integers within the
     * bounds of the section: the value of an attribute is whatever an author
     * typed, and only what the section could have written is passed on.
     *
     * @return array{focusX: int, focusY: int, zoom: int, boxWidth: int, boxHeight: int}|null
     */
    public static function parse(string $value): ?array
    {
        if (1 !== preg_match('/^(\d{1,3}),(\d{1,3}),(\d{1,3}),(\d{1,4}),(\d{1,4})$/', trim($value), $numbers)) {
            return null;
        }

        [, $focusX, $focusY, $zoom, $boxWidth, $boxHeight] = array_map('intval', $numbers);

        $ownProportions = 0 === $boxWidth && 0 === $boxHeight;
        $boxWithinBounds = $boxWidth >= ImageSection::MIN_WIDTH && $boxWidth <= ImageSection::MAX_WIDTH
            && $boxHeight >= ImageSection::MIN_HEIGHT && $boxHeight <= ImageSection::MAX_HEIGHT
            && $boxWidth <= $boxHeight * ImageSection::MAX_RATIO && $boxHeight <= $boxWidth * ImageSection::MAX_RATIO;

        $withinBounds = $focusX <= 100 && $focusY <= 100
            && $zoom >= 100 && $zoom <= 400 && 0 === $zoom % ImageSection::ZOOM_STEP
            && ($ownProportions || $boxWithinBounds);

        return $withinBounds ? ['focusX' => $focusX, 'focusY' => $focusY, 'zoom' => $zoom, 'boxWidth' => $boxWidth, 'boxHeight' => $boxHeight] : null;
    }

    /**
     * The zone an address of the media library names: `x,y,w,h` in the region
     * segment of `/image-library/{id}/{region}/...`. Null for `full`, for any
     * other region (`square`, `pct:...`) and for an address that is not the
     * library's: only four integers are read, and whether they lie inside the
     * picture is for the caller, who knows its size.
     *
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    public static function region(string $address): ?array
    {
        if (1 !== preg_match('#^/image-library/\d+/(\d{1,5}),(\d{1,5}),(\d{1,5}),(\d{1,5})/#', trim($address), $numbers)) {
            return null;
        }

        return ['x' => (int) $numbers[1], 'y' => (int) $numbers[2], 'w' => (int) $numbers[3], 'h' => (int) $numbers[4]];
    }

    /**
     * The zone of the original, in its pixels, that fills the box.
     *
     * The largest zone of the box proportions, shrunk by the zoom, centred on
     * the focal point and moved back inside the picture. The zone has the
     * proportions of the box to the pixel (its height follows its width), and
     * is never outside the original: the library refuses a zone that starts
     * past an edge.
     *
     * A box of `0, 0` is the proportions of the picture itself.
     *
     * @return array{x: int, y: int, w: int, h: int}|null null when the zone is the whole picture, which is served as it is
     */
    public static function zone(int $originalWidth, int $originalHeight, int $boxWidth, int $boxHeight, int $focusX, int $focusY, int $zoom): ?array
    {
        if (0 === $boxWidth && 0 === $boxHeight) {
            $boxWidth = $originalWidth;
            $boxHeight = $originalHeight;
        }

        if ($originalWidth < 1 || $originalHeight < 1 || $boxWidth < 1 || $boxHeight < 1) {
            return null;
        }

        $ratio = $boxWidth / $boxHeight;
        $largestWidth = $originalWidth / $originalHeight >= $ratio ? $originalHeight * $ratio : (float) $originalWidth;

        $width = max(1, min($originalWidth, (int) floor($largestWidth * 100 / max(100, $zoom) + 1e-9)));
        $height = max(1, min($originalHeight, (int) round($width / $ratio)));

        $x = max(0, min($originalWidth - $width, (int) round($originalWidth * max(0, min(100, $focusX)) / 100 - $width / 2)));
        $y = max(0, min($originalHeight - $height, (int) round($originalHeight * max(0, min(100, $focusY)) / 100 - $height / 2)));

        if (0 === $x && 0 === $y && $width === $originalWidth && $height === $originalHeight) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'w' => $width, 'h' => $height];
    }
}
