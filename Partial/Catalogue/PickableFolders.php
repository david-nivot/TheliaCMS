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

namespace TheliaCMS\Partial\Catalogue;

use Thelia\Model\Folder;
use Thelia\Model\FolderQuery;

/**
 * The folders an editor may pick for a block of the theme: the ones shown on
 * the site, in the order the shop gave them.
 */
final readonly class PickableFolders
{
    /**
     * @return list<Folder>
     */
    public function visible(): array
    {
        return iterator_to_array(
            FolderQuery::create()->filterByVisible(1)->orderByPosition()->find(),
            false,
        );
    }
}
