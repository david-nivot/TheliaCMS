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

use TheliaCMS\Content\Document;

/**
 * A kept draft ready to be put back: its document, and the provenance mark
 * it brings along, or none.
 */
final readonly class RestorableDraft
{
    public function __construct(
        public Document $document,
        public ?string $provenance,
    ) {
    }
}
