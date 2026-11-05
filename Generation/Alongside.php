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

namespace TheliaCMS\Generation;

/**
 * What the answer of the generator saves for a page besides its document:
 * the values of the fields of its type, the title and the description search
 * engines show, and the header of the page for a translation. One value, so
 * the callback of a run does not grow with every one of them.
 */
final readonly class Alongside
{
    /**
     * @param array<string, mixed>|null $fields the values of the fields of the page, null when none was asked for
     * @param array<string, string>     $seo    `meta_title` and `meta_description`, by column
     */
    public function __construct(
        public ?array $fields = null,
        public array $seo = [],
        public ?PageHeader $header = null,
    ) {
    }
}
