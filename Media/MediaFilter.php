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
 * What the list of the library can be narrowed to, besides a search and a tag.
 */
enum MediaFilter: string
{
    /** Neither an alternative text in the language edited nor decorative. */
    case ToDescribe = 'to-describe';
    /** Shown by no page and no catalogue item. */
    case Unused = 'unused';
    case Decorative = 'decorative';
    /** Images that have an icon name (`cms:…`). */
    case Icons = 'icons';
}
