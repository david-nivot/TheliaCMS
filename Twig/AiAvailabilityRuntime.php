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

namespace TheliaCMS\Twig;

use TheliaCMS\Generation\Ai\AiAvailability;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * The answer behind `cms_ai_available()`: whether the AI of the shop serves a usage
 * of the module. See AiAvailabilityExtension.
 */
final readonly class AiAvailabilityRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private AiAvailability $availability,
    ) {
    }

    public function isAvailable(string $usageId): bool
    {
        return $this->availability->isAvailable($usageId);
    }
}
