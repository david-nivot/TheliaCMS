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
 * The pages of an element, as the drawer shows them: the total the screens
 * display, the first rows, and what is left.
 */
final readonly class LinkedPages
{
    /**
     * @param list<LinkedPage> $shown the first rows; never more than the total
     */
    public function __construct(
        public LinkedPageKind $kind,
        public string $key,
        public int $total,
        public array $shown,
        public ?string $listUrl,
    ) {
    }

    public function more(): int
    {
        return $this->total - \count($this->shown);
    }
}
