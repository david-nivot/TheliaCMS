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

use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;

/**
 * The address an image prop is drawn from, for the sections the module draws
 * itself.
 */
final readonly class ImageSource
{
    /**
     * An address of elsewhere as it is; an image of the library at its full
     * size, in its own format, which the publication turns into a set of
     * widths like any other image of a page.
     */
    public static function of(mixed $image): ?string
    {
        if (\is_string($image) && PropSchema::isExternalImage($image)) {
            return $image;
        }

        $library = self::library($image);

        return null === $library ? null : self::address($library, 'full');
    }

    /**
     * The address of an image the template of a component prints itself, with
     * the part its editor keeps: the zone of the file `ImageFrame` computes, in
     * pixels, which the publication then turns into its set of widths. The
     * address `of()` gives when nothing is set, so a component nobody framed
     * draws the same markup as before.
     *
     * An image kept elsewhere has no zone to ask for: its address as it is. A
     * file the library has not measured yet is served whole, the zone needing
     * its size.
     */
    public static function framed(mixed $image, string $proportions, int $focusX, int $focusY, int $zoom): ?string
    {
        $whole = self::of($image);

        if (null === $whole || (ImageProportions::ORIGINAL === $proportions && $zoom <= 100)) {
            return $whole;
        }

        $box = ImageProportions::ORIGINAL === $proportions ? [0, 0] : ImageProportions::box($proportions);
        $library = self::library($image);

        if (null === $box || null === $library || null === $library->getWidth() || null === $library->getHeight()) {
            return $whole;
        }

        $zone = ImageFrame::zone((int) $library->getWidth(), (int) $library->getHeight(), $box[0], $box[1], $focusX, $focusY, $zoom);

        return null === $zone ? $whole : self::address($library, \sprintf('%d,%d,%d,%d', $zone['x'], $zone['y'], $zone['w'], $zone['h']));
    }

    private static function library(mixed $image): ?LibraryImage
    {
        if (\is_string($image) && PropSchema::isExternalImage($image)) {
            return null;
        }

        $id = (int) $image;

        if ($id <= 0) {
            return null;
        }

        $library = LibraryImageQuery::create()->findPk($id);

        return null === $library || null === $library->getFileName() ? null : $library;
    }

    private static function address(LibraryImage $library, string $region): string
    {
        return '/image-library/'.$library->getId().'/'.$region.'/max/0/default.'.strtolower(pathinfo((string) $library->getFileName(), \PATHINFO_EXTENSION) ?: 'jpg');
    }

    /**
     * The alternative text an image prop is drawn with. An image of the
     * library is described there, once for every page: its text in the
     * language, or none when it is decorative. The text the section holds
     * answers for a picture kept elsewhere, and for an image of the library
     * not described yet, as sections written before kept it.
     */
    public static function alt(mixed $image, string $sectionAlt, string $locale): string
    {
        $id = \is_string($image) && PropSchema::isExternalImage($image) ? 0 : (int) $image;
        $library = $id > 0 ? LibraryImageQuery::create()->findPk($id) : null;

        if (null === $library) {
            return trim($sectionAlt);
        }

        if (1 === (int) $library->getDecorative()) {
            return '';
        }

        $described = trim((string) $library->setLocale($locale)->getAlt());

        return '' !== $described ? $described : trim($sectionAlt);
    }
}
