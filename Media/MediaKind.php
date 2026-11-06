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

use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Service\LibraryImageFileNamer;

/**
 * What a row of the library holds. The library stores a video in the table of
 * the images, told apart by its `mime_type`: a video is the types
 * TheliaLibrary lists as such, anything else is an image, a NULL type
 * included (rows stored before the type was recorded).
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';

    public static function of(LibraryImage $image): self
    {
        return $image->isVideo() ? self::Video : self::Image;
    }

    /**
     * The `WHERE` condition on the `mime_type` column of the given table
     * alias that keeps this kind: no value to bind, the types are constants.
     */
    public function condition(string $alias = 'li'): string
    {
        $types = implode(',', array_map(static fn (string $type): string => "'".$type."'", LibraryImageFileNamer::VIDEO_MIME_TYPES));

        return self::Video === $this
            ? \sprintf('%s.mime_type IN (%s)', $alias, $types)
            : \sprintf('(%1$s.mime_type IS NULL OR %1$s.mime_type NOT IN (%2$s))', $alias, $types);
    }
}
