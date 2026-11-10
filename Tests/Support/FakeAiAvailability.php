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

use TheliaCMS\Generation\Ai\AiAvailability;
use TheliaCMS\Generation\Ai\AiUnavailability;

/**
 * The AI of the shop as the screens see it in the tests: every usage of the
 * module available unless a test turns one off. The database of the tests has
 * no connection and no model, so the real service would answer "no model" for
 * every usage and hide every button the screens are tested for.
 *
 * Standing in for the service of the container, it keeps the answers a test
 * gives it and nothing else: no registry, no resolver, no settings.
 */
final class FakeAiAvailability extends AiAvailability
{
    /** @var array<string, AiUnavailability> */
    private array $unavailable = [];

    public function __construct()
    {
        // Nothing to resolve: the parent constructor would ask for the collaborators of the real service.
    }

    public static function everythingAvailable(): self
    {
        return new self();
    }

    public function turnOff(AiUnavailability $reason = AiUnavailability::NoModel, string ...$usages): void
    {
        foreach ([] === $usages ? self::USAGES : $usages as $usageId) {
            $this->unavailable[$usageId] = $reason;
        }
    }

    public function turnOn(): void
    {
        $this->unavailable = [];
    }

    public function reason(string $usageId): ?AiUnavailability
    {
        return $this->unavailable[$usageId] ?? null;
    }

    public function reset(): void
    {
    }
}
