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

namespace TheliaCMS\Generation\Component;

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Model\CmsPageGeneration;
use TheliaCMS\Model\CmsPageGenerationQuery;

/**
 * The calls of the generator of components, read back from the ledger: a
 * candidate to look at, and the history of a component.
 */
final readonly class ComponentCandidates
{
    public function find(int $logId): ?ComponentCandidate
    {
        GenerationLog::interruptStale(GenerationTarget::TYPE_COMPONENT);

        $row = CmsPageGenerationQuery::create()
            ->filterById($logId)
            ->filterByTargetType(GenerationTarget::TYPE_COMPONENT)
            ->findOne();

        return null === $row ? null : self::fromRow($row);
    }

    /**
     * The calls that changed a component, the latest first.
     *
     * @return list<ComponentCandidate>
     */
    public function history(int $componentId, int $limit = 20): array
    {
        GenerationLog::interruptStale(GenerationTarget::TYPE_COMPONENT, $componentId);

        return array_map(self::fromRow(...), iterator_to_array(
            CmsPageGenerationQuery::create()
                ->filterByTargetType(GenerationTarget::TYPE_COMPONENT)
                ->filterByTargetId($componentId)
                ->orderByCreatedAt(Criteria::DESC)
                ->orderById(Criteria::DESC)
                ->limit($limit)
                ->find(),
            false,
        ));
    }

    /**
     * Files the calls that made a new component under its row once it is
     * kept, so its history starts with the request it came from.
     *
     * @param list<int> $logIds
     */
    public function attach(array $logIds, int $componentId): void
    {
        CmsPageGenerationQuery::create()
            ->filterByTargetType(GenerationTarget::TYPE_COMPONENT)
            ->filterByTargetId(0)
            ->filterById($logIds, Criteria::IN)
            ->update(['TargetId' => $componentId]);
    }

    /**
     * What a call keeps in the `document` of its row: the component as the
     * answer wrote it (ComponentPrompt::json), with the name it was copied
     * from and why after a copy; for a call that found the component the
     * site already has, its name and the reason, and no component.
     */
    public static function documentOf(?SiteComponent $component, ?string $existing = null, ?string $basedOn = null, string $reason = ''): string
    {
        $flags = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT;

        if (null === $component) {
            return json_encode(['outcome' => ComponentResult::EXISTING, 'existing' => $existing, 'reason' => $reason], $flags);
        }

        if (null === $basedOn) {
            return ComponentPrompt::json($component);
        }

        return json_encode(['outcome' => ComponentResult::COMPONENT, 'based_on' => $basedOn, 'reason' => $reason] + json_decode(ComponentPrompt::json($component), true, 8, \JSON_THROW_ON_ERROR), $flags);
    }

    /**
     * The component as the answer wrote it (ComponentPrompt::json), or null
     * when the row holds none: a row that says `existing` never holds one,
     * whatever else it carries. A row written before the outcomes has no
     * `outcome` and is a component.
     */
    public static function componentOf(?string $json): ?SiteComponent
    {
        $answer = json_decode((string) $json, true);

        if (!\is_array($answer) || !\is_string($answer['code'] ?? null) || ComponentResult::COMPONENT !== ($answer['outcome'] ?? ComponentResult::COMPONENT)) {
            return null;
        }

        $props = json_decode(\is_string($answer['props'] ?? null) ? $answer['props'] : '', true);
        $sample = json_decode(\is_string($answer['sample'] ?? null) ? $answer['sample'] : '', true);

        return new SiteComponent(
            $answer['code'],
            (string) ($answer['label'] ?? ''),
            (string) ($answer['description'] ?? ''),
            \is_array($props) && array_is_list($props) ? array_values(array_filter($props, 'is_array')) : [],
            \is_array($sample) ? $sample : [],
            (string) ($answer['template'] ?? ''),
            (string) ($answer['css'] ?? ''),
        );
    }

    private static function fromRow(CmsPageGeneration $row): ComponentCandidate
    {
        $answer = json_decode((string) $row->getDocument(), true);
        $answer = \is_array($answer) ? $answer : [];
        $existing = ComponentResult::EXISTING === ($answer['outcome'] ?? null);

        return new ComponentCandidate(
            (int) $row->getId(),
            (string) $row->getKind(),
            (string) $row->getStatus(),
            (string) $row->getPrompt(),
            (int) $row->getTargetId(),
            (string) $row->getLocale(),
            GenerationLog::STATUS_DONE === $row->getStatus() ? self::componentOf($row->getDocument())?->withProvenance(ProvenanceMark::normalize($row->getProvenance())) : null,
            $row->getError(),
            (float) $row->getCostUsd(),
            (int) $row->getTurns(),
            (int) $row->getDurationMs(),
            $row->getCreatedAt(),
            $existing && GenerationLog::STATUS_DONE === $row->getStatus() ? ComponentResult::EXISTING : ComponentResult::COMPONENT,
            $existing && \is_string($answer['existing'] ?? null) ? $answer['existing'] : null,
            \is_string($answer['based_on'] ?? null) ? $answer['based_on'] : null,
            \is_string($answer['reason'] ?? null) ? $answer['reason'] : '',
        );
    }
}
