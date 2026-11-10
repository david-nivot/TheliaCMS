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

/**
 * One row of the list of the pages of an element: a page, or a catalogue item
 * whose document holds the element (the number counts both).
 */
final readonly class LinkedPage
{
    /**
     * @param string          $type   `page`, or the type of the catalogue item (`product`, `category`…)
     * @param PageStatus|null $status in the language of the screen; none for a catalogue item
     */
    public function __construct(
        public string $type,
        public int $id,
        public string $title,
        public ?PageStatus $status,
        public bool $binned,
    ) {
    }

    public function isPage(): bool
    {
        return 'page' === $this->type;
    }
}
