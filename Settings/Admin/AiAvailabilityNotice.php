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

namespace TheliaCMS\Settings\Admin;

use TheliaCMS\Generation\Ai\AiAvailability;
use TheliaCMS\Generation\Ai\AiUnavailability;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\Text\TextGenerator;
use TheliaCMS\Http\Admin\AdminControllerSupport;

/**
 * The sentences of the line the settings screen shows an administrator when
 * the AI cannot serve the module: why, and for which of the things the module
 * does with it. Empty when everything is available.
 */
final readonly class AiAvailabilityNotice
{
    private const array SENTENCES = [
        'usage_off' => 'Turned off in the AI settings: %usages%.',
        'no_model' => 'No connection or model set up for: %usages%.',
        'key_unreadable' => 'The key of the connection cannot be read for: %usages%.',
        'jurisdiction' => 'The connection is outside the European Union, which the AI settings refuse: %usages%.',
        'unknown' => 'Not known to the AI layer: %usages%.',
    ];

    public function __construct(
        private AiAvailability $availability,
        private AdminControllerSupport $support,
    ) {
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        $unavailable = $this->availability->unavailableUsages();

        if ([] === $unavailable) {
            return [];
        }

        // The master switch speaks for every usage at once.
        if (\in_array(AiUnavailability::Disabled, $unavailable, true)) {
            return [$this->support->translate('The AI is turned off for the whole shop.')];
        }

        $byReason = [];

        foreach ($unavailable as $usageId => $reason) {
            $byReason[$reason->value][] = $this->label($usageId);
        }

        $sentences = [];

        foreach ($byReason as $reason => $labels) {
            $sentences[] = $this->support->translate(self::SENTENCES[$reason], ['%usages%' => implode(', ', $labels)]);
        }

        return $sentences;
    }

    private function label(string $usageId): string
    {
        return match ($usageId) {
            PageGenerator::USAGE => $this->support->translate('writing pages from a brief'),
            PageGenerator::TRANSLATION_USAGE => $this->support->translate('translating pages'),
            ComponentGenerator::USAGE => $this->support->translate('making components'),
            TextGenerator::USAGE => $this->support->translate('writing texts from a brief'),
            default => $usageId,
        };
    }
}
