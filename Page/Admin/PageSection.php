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
 * The page whose pages the list shows, and where it sits: what the breadcrumb
 * of the list draws.
 */
final readonly class PageSection
{
    /**
     * @param list<array{id: int, title: string}> $trail the pages above it, from the root of the site
     */
    public function __construct(
        public int $id,
        public string $title,
        public array $trail,
    ) {
    }
}
