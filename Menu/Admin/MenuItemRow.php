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

namespace TheliaCMS\Menu\Admin;

use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Menu\ResolvedTarget;
use TheliaCMS\Model\CmsMenuItem;

/**
 * One row of the menu editor: the entry, where it sits, and what it resolves to
 * in the language being edited.
 */
final readonly class MenuItemRow
{
    public function __construct(
        public CmsMenuItem $item,
        public int $depth,
        public MenuTargetType $type,
        public ResolvedTarget $target,
        public bool $canMoveUp,
        public bool $canMoveDown,
        /** Whether the entry is left out of the site, by itself or because an entry above it is. */
        public bool $hidden = false,
        /** Whether it is only because of an entry above it. */
        public bool $hiddenByParent = false,
    ) {
    }

    public function id(): int
    {
        return (int) $this->item->getId();
    }
}
