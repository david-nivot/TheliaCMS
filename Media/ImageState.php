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
 * What the media library recorded about the description of an image in one
 * language: the choice an `alt=""` in published HTML cannot tell by itself.
 */
enum ImageState
{
    /** A non-blank alternative text exists in the language. */
    case Described;

    /** The author chose that the image says nothing. */
    case Decorative;

    /** Neither: the image was forgotten. */
    case Undescribed;
}
