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

use TheliaLibrary\Model\LibraryImageQuery;

/**
 * The address a video prop is drawn from. A video of the library is served
 * as it was uploaded, from a route of its own: no zone, no width.
 */
final readonly class VideoSource
{
    /**
     * The address of the file of a video of the library, null when the value
     * names no video: an id that holds nothing, or one that is an image.
     */
    public static function of(mixed $video): ?string
    {
        $id = \is_int($video) || (\is_string($video) && 1 === preg_match('/^\d+$/', $video)) ? (int) $video : 0;

        if ($id <= 0) {
            return null;
        }

        $library = LibraryImageQuery::create()->findPk($id);

        if (null === $library || null === $library->getFileName() || !$library->isVideo()) {
            return null;
        }

        return '/video-library/'.$library->getId().'.mp4';
    }
}
