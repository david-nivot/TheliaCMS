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

/**
 * What the menu entry form produces, once the fields that do not apply to the
 * chosen target type have been dropped.
 */
final readonly class MenuItemData
{
    public function __construct(
        public MenuTargetType $type,
        public ?int $targetId,
        public ?string $url,
        public ?string $label,
        public int $parent,
        public bool $openNewTab,
        public bool $visible = true,
    ) {
    }
}
