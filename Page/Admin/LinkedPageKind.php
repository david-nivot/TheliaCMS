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

namespace TheliaCMS\Page\Admin;

use TheliaCMS\Security\CmsResources;

/**
 * What a number of pages is read next to: the element whose pages the drawer
 * lists. Each kind answers to the resource of the screen that shows the
 * number, so the list is open to whoever sees the number, no more.
 */
enum LinkedPageKind: string
{
    case Component = 'component';
    case Tag = 'tag';
    case PageType = 'page-type';
    case Media = 'media';

    public function resource(): string
    {
        return match ($this) {
            self::Component, self::PageType => CmsResources::SETTINGS,
            self::Tag => CmsResources::PAGE,
            self::Media => CmsResources::MEDIA,
        };
    }
}
