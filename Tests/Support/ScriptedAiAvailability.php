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

namespace TheliaCMS\Tests\Support;

use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\Provider\AiTargetResolver;
use Thelia\Domain\Ai\Service\AiSettings;
use TheliaCMS\Generation\Ai\AiAvailability;

/**
 * The service with its connection scripted: the jurisdiction it answers, or the
 * exception of the core for a usage, and how many times it was asked.
 */
final class ScriptedAiAvailability extends AiAvailability
{
    public int $resolutions = 0;

    /**
     * @param array<string, \Throwable> $failures
     */
    public function __construct(
        AiUsageRegistryInterface $registry,
        AiTargetResolver $resolver,
        AiSettings $settings,
        private readonly AiJurisdiction $jurisdiction,
        private readonly array $failures,
    ) {
        parent::__construct($registry, $resolver, $settings);
    }

    protected function jurisdictionServing(string $usageId): AiJurisdiction
    {
        ++$this->resolutions;

        if (isset($this->failures[$usageId])) {
            throw $this->failures[$usageId];
        }

        return $this->jurisdiction;
    }
}
