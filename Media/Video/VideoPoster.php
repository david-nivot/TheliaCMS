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

namespace TheliaCMS\Media\Video;

use TheliaCMS\Content\ImageProportions;
use TheliaCMS\Content\ImageSource;
use TheliaCMS\Model\CmsVideoConversionQuery;

/**
 * The image taken from a video when it was converted: what a section that
 * shows the video draws as its poster when the author picked none.
 */
final readonly class VideoPoster
{
    /**
     * The address of the poster of a video of the library, null when it has
     * none (a video never converted, a conversion that failed, a poster since
     * deleted).
     */
    public static function addressOf(mixed $video): ?string
    {
        $id = \is_int($video) || (\is_string($video) && 1 === preg_match('/^\d+$/', $video)) ? (int) $video : 0;

        if ($id <= 0) {
            return null;
        }

        $poster = CmsVideoConversionQuery::create()->filterByLibraryImageId($id)->findOne()?->getPosterImageId();

        return null === $poster ? null : ImageSource::framed($poster, ImageProportions::ORIGINAL, 50, 50, 100);
    }
}
