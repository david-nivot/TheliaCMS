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

namespace TheliaCMS\Generation\Guardrail;

use Thelia\Domain\Ai\Guardrail\GuardrailMode;
use Thelia\Model\AiUsageSettingQuery;
use TheliaCMS\Generation\PageGenerator;

/**
 * Whether the merchant's settings of a usage that takes a joined file (the
 * pages, by default, or the components) leave the judge of joined files out. The usage lists it, but a merchant who chooses the
 * guardrails of the usage replaces that list (the AI layer runs the ones
 * chosen, or those the usage declares when none is chosen): a list that does
 * not name `reference_file` lets a file through unjudged, without a word.
 *
 * Read the way the core reads it (AiGuardrailRunner::selection(), internal to
 * the core): an empty or unreadable choice keeps what the usage declares.
 * A judge chosen in `annotate` mode is a choice of the merchant and is kept.
 */
final readonly class ReferenceFileJudgeSetting
{
    public function leavesTheJudgeOut(string $usage = PageGenerator::USAGE): bool
    {
        $chosen = trim((string) AiUsageSettingQuery::create()->filterByUsageId($usage)->findOne()?->getGuardrails());

        if ('' === $chosen) {
            return false;
        }

        $decoded = json_decode($chosen, true);

        if (!\is_array($decoded)) {
            return false;
        }

        $mode = $decoded[ReferenceFileGuardrail::NAME] ?? null;

        return !\is_string($mode) || null === GuardrailMode::tryFrom($mode);
    }
}
