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

use Thelia\Core\Security\AccessManager;

/**
 * What the list of pages does to a selection, in the order of its select.
 */
enum PageBulkAction: string
{
    case Show = 'show';
    case Hide = 'hide';
    case Tag = 'tag';
    case Trash = 'trash';
    case Publish = 'publish';
    case Unpublish = 'unpublish';

    /**
     * The right each one takes, checked for the action and not once for the
     * form: an editor who may change pages may not empty them into the bin.
     */
    public function access(): string
    {
        return self::Trash === $this ? AccessManager::DELETE : AccessManager::UPDATE;
    }

    /**
     * Asked again on a screen that says what will really happen: the bin takes
     * the pages under a selected one, and a publication can be refused page by
     * page, so neither is the count of the boxes ticked.
     */
    public function isConfirmed(): bool
    {
        return self::Trash === $this || self::Publish === $this;
    }
}
