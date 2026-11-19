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

/**
 * One line of a searched reference source: what the editor lists, and whether
 * the item is still one the page can show.
 */
final readonly class PickableItem
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $online,
        public bool $binned = false,
        // For a list: the title alone, and where the item sits under it.
        public ?string $label = null,
        public ?string $path = null,
    ) {
    }
}
