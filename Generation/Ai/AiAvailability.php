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

namespace TheliaCMS\Generation\Ai;

use Symfony\Contracts\Service\ResetInterface;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiUsageRegistryInterface;
use Thelia\Domain\Ai\Exception\AiKeyDecryptionException;
use Thelia\Domain\Ai\Exception\InactiveAiUsageException;
use Thelia\Domain\Ai\Exception\UnknownAiUsageException;
use Thelia\Domain\Ai\Provider\AiTargetResolver;
use Thelia\Domain\Ai\Service\AiSettings;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\Text\TextGenerator;

/**
 * Whether the AI of the shop can serve a usage of the module now: what the
 * screens ask before they offer a button that would fail.
 *
 * A usage is available when the master switch is on, the usage is turned on in
 * its own settings (`AiUsageRegistryInterface::isActive()`, which the gateway
 * checks first at each call), a connection and a model serve it
 * (`AiTargetResolver::resolve()`, which the gateway runs next and which the
 * registry does not: its `isActive()` does not look at the model), and the
 * jurisdiction of that connection is one the shop accepts. The same checks the
 * gateway makes, in the same order, so a button is hidden exactly when its
 * call would be refused for one of them.
 *
 * Not looked at: the monthly cap and the budget left. They are spent as the
 * shop writes, and a screen that hid its buttons at the ceiling would hide
 * them for a reason that goes away by itself; the call says it when it
 * is refused.
 *
 * The answer is kept for the request, one resolution by usage however many
 * buttons a screen has, and forgotten between two requests of a worker that
 * lives longer (ResetInterface).
 *
 * Not final: the tests of the screens replace this service by one that
 * answers without a connection, the database of the tests having none, and
 * the unit test of the rules scripts the one method that reads the connection.
 */
class AiAvailability implements ResetInterface
{
    /**
     * Every usage of the module a screen may offer a button for.
     *
     * @var list<string>
     */
    public const array USAGES = [
        PageGenerator::USAGE,
        PageGenerator::TRANSLATION_USAGE,
        ComponentGenerator::USAGE,
        TextGenerator::USAGE,
    ];

    private ?bool $enabled = null;

    /** @var array<string, AiUnavailability|null> usage => why it cannot run, null when it can */
    private array $reasons = [];

    public function __construct(
        private readonly AiUsageRegistryInterface $usages,
        private readonly AiTargetResolver $targets,
        private readonly AiSettings $settings,
    ) {
    }

    public function isAvailable(string $usageId): bool
    {
        return null === $this->reason($usageId);
    }

    /**
     * Why the usage cannot run, null when it can.
     */
    public function reason(string $usageId): ?AiUnavailability
    {
        if (\array_key_exists($usageId, $this->reasons)) {
            return $this->reasons[$usageId];
        }

        return $this->reasons[$usageId] = $this->evaluate($usageId);
    }

    /**
     * The usages of the module that cannot run, with the reason of each.
     *
     * @return array<string, AiUnavailability>
     */
    public function unavailableUsages(): array
    {
        $unavailable = [];

        foreach (self::USAGES as $usageId) {
            $reason = $this->reason($usageId);

            if (null !== $reason) {
                $unavailable[$usageId] = $reason;
            }
        }

        return $unavailable;
    }

    public function reset(): void
    {
        $this->enabled = null;
        $this->reasons = [];
    }

    private function evaluate(string $usageId): ?AiUnavailability
    {
        $this->enabled ??= $this->settings->isEnabled();

        if (!$this->enabled) {
            return AiUnavailability::Disabled;
        }

        try {
            if (!$this->usages->isActive($usageId)) {
                return AiUnavailability::UsageOff;
            }

            $jurisdiction = $this->jurisdictionServing($usageId);
        } catch (UnknownAiUsageException) {
            return AiUnavailability::Unknown;
        } catch (InactiveAiUsageException) {
            return AiUnavailability::NoModel;
        } catch (AiKeyDecryptionException) {
            return AiUnavailability::KeyUnreadable;
        }

        if ($this->settings->isEuropeanUnionOnly() && AiJurisdiction::EuropeanUnion !== $jurisdiction) {
            return AiUnavailability::Jurisdiction;
        }

        return null;
    }

    /**
     * The jurisdiction of the connection that would serve the usage.
     *
     * @throws UnknownAiUsageException
     * @throws InactiveAiUsageException no active connection with a model for the usage
     * @throws AiKeyDecryptionException
     */
    protected function jurisdictionServing(string $usageId): AiJurisdiction
    {
        return $this->targets->resolve($usageId)->jurisdiction;
    }
}
