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
 * The language a content was written from, and what changed in that language
 * since: the draft section by section, and for a page the words of its header.
 */
final readonly class TranslationLink
{
    /**
     * @param list<string> $changed ids of the sections of the source whose words changed, or that were added
     * @param list<string> $removed ids of the sections the source no longer has
     * @param bool         $header  whether the words of the header of the source (title, summary, description, sharing texts) changed: a page only
     */
    public function __construct(
        public string $sourceLocale,
        public array $changed = [],
        public array $removed = [],
        public bool $header = false,
    ) {
    }

    public function isStale(): bool
    {
        return [] !== $this->changed || [] !== $this->removed || $this->header;
    }
}
