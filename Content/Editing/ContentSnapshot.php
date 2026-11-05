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

namespace TheliaCMS\Content\Editing;

/**
 * The document of a content with its rendering and its provenance mark, as a
 * draft, a publication or a revision holds them.
 */
final readonly class ContentSnapshot
{
    public function __construct(
        public ?string $document,
        public ?string $html,
        public ?string $provenance,
    ) {
    }
}
