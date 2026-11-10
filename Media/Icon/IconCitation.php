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

namespace TheliaCMS\Media\Icon;

/**
 * How many pages, catalogue items and components of the site cite an icon: in
 * their documents, and in the Twig template of a component.
 */
final readonly class IconCitation
{
    public function __construct(
        public int $pages,
        public int $items,
        public int $components,
    ) {
    }

    public function isCited(): bool
    {
        return $this->pages + $this->items + $this->components > 0;
    }
}
