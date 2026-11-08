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
 * How the list of the library is drawn: cards with a large thumbnail, or one
 * compact row per file with its format, size and weight in columns.
 */
enum MediaView: string
{
    case Grid = 'grid';
    case List = 'list';
}
