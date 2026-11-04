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
 * What a call needed beyond its outcome, kept on its ledger row and shown on
 * the history line: the effort asked of the model, the corrections it took,
 * the cut answers asked again, and the tools it called with their counts.
 */
final readonly class RunDetails
{
    /**
     * @param array<string, int> $tools how many times each tool was called, by name
     */
    public function __construct(
        public ?string $effort = null,
        public int $corrections = 0,
        public int $retries = 0,
        public array $tools = [],
    ) {
    }

    /**
     * The tools called in one turn, added to the counts so far.
     *
     * @param array<string, int> $counts
     * @param list<string>       $names
     *
     * @return array<string, int>
     */
    public static function counted(array $counts, array $names): array
    {
        foreach ($names as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        return $counts;
    }
}
