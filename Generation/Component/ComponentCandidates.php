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
     * The component as the answer wrote it (ComponentPrompt::json), or null
     * when the row holds none.
     */
    public static function componentOf(?string $json): ?SiteComponent
    {
        $answer = json_decode((string) $json, true);

        if (!\is_array($answer) || !\is_string($answer['code'] ?? null)) {
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
        );
    }
}
