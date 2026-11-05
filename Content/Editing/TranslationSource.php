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
 * The draft a language is written from, with the fingerprint of its words as
 * it was read: what the translation is later compared with. Taken before the
 * model writes, so an edit of the source in the meantime makes the result
 * stale at once.
 */
final readonly class TranslationSource
{
    /**
     * @param array<string, string> $fingerprint the hash of the words of each section, by section id
     * @param string|null           $header      the hash of the words of the header of the content, null when it has none to follow (an item)
     */
    public function __construct(
        public Document $document,
        public array $fingerprint,
        public ?string $header = null,
    ) {
    }
}
