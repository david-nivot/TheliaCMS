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
 * What the backfill did, or would do, for one translation of the ledger.
 */
final readonly class TranslationBackfillOutcome
{
    /**
     * @param string      $content the content, such as "page #5" or "product #3"
     * @param string|null $reason  why it was left alone; null when it is tied
     */
    public function __construct(
        public string $content,
        public string $locale,
        public string $sourceLocale,
        public ?string $reason = null,
    ) {
    }

    public function isTied(): bool
    {
        return null === $this->reason;
    }
}
