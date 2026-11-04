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

use Thelia\Domain\Ai\AiProvenance;
use TheliaCMS\Content\Document;

/**
 * What a call to the generator produced: a document the validator accepted,
 * the values it wrote for the fields of the page when it was asked for some,
 * and what it took to get it.
 */
final readonly class GenerationResult
{
    /**
     * @param array<string, int|float|string|bool|null>|null $fields     by field code, null when no field was asked for
     * @param list<string>                                   $leftOut    what the brief asked and the page does not hold, one sentence each
     * @param array<string, string>                          $seo        the `meta_title` and `meta_description` the answer proposed and the page may take (within their length), by column; empty when none was asked or fit
     * @param AiProvenance|null                              $provenance the mark of the core on the last answer; null when the shop turned the mark off for the usage
     */
    public function __construct(
        public Document $document,
        public string $model,
        public ?string $requestId,
        public Usage $usage,
        public float $costUsd,
        public int $durationMs,
        public int $turns,
        public ?int $logId = null,
        public ?array $fields = null,
        public array $leftOut = [],
        public ?AiProvenance $provenance = null,
        public array $seo = [],
    ) {
    }
}
