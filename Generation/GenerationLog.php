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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Ai\AiProvenance;
use Thelia\Model\AdminQuery;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\Model\CmsPageGeneration;
use TheliaCMS\Model\CmsPageGenerationQuery;

/**
 * The ledger: one row per call, whatever its outcome. The history an editor
 * iterates from, and the spend the site reads.
 *
 * The cost of a call is the sum of what the usage log of the core says its
 * requests cost; the row keeps the sum and the id of the last request in that
 * log. The site has no price list of its own.
 */
final readonly class GenerationLog
{
    public const string STATUS_DONE = 'done';
    public const string STATUS_REFUSED = 'refused';
    public const string STATUS_INVALID = 'invalid';
    public const string STATUS_FAILED = 'failed';

    /** A call that started and has not ended: written before the first request to the model. */
    public const string STATUS_RUNNING = 'running';

    /** A running call nobody touched for STALE_AFTER seconds: the process died, the call has no other outcome. */
    public const string STATUS_INTERRUPTED = 'interrupted';

    /** A running call touches its row at every turn; one silent for this long (30 minutes) is read as dead. */
    public const int STALE_AFTER = 1800;

    /** A change made by hand, recorded with the calls so the history reads in order. */
    public const string KIND_MANUAL = 'manual';

    public function __construct(
        private SecurityContext $securityContext,
    ) {
    }

    /**
     * @param float             $costUsd  what the requests of the call cost, as the core logged them
     * @param int|null          $logId    the row of the usage log of the core written for the last request of the call, null when none was
     * @param list<WebPageRead> $webPages the pages the call read, counted
     * @param list<string>      $leftOut  what the answer says the page leaves out of the brief
     * @param int|null          $rowId    the row begin() wrote for the call, completed here; a new row when null
     */
    public function record(
        GenerationRequest $request,
        string $status,
        string $prompt,
        string $model,
        Usage $usage,
        int $turns,
        int $durationMs,
        float $costUsd,
        ?int $logId,
        ?string $requestId = null,
        ?string $stopReason = null,
        ?string $stopCategory = null,
        ?string $error = null,
        ?string $document = null,
        array $webPages = [],
        array $leftOut = [],
        ?AiProvenance $provenance = null,
        ?int $rowId = null,
        RunDetails $details = new RunDetails(),
    ): int {
        $row = $this->rowOf($rowId)
            ->setTargetType($request->target->type)
            ->setTargetId($request->target->id)
            ->setLocale($request->locale)
            ->setKind($request->kind)
            ->setPrompt($prompt)
            ->setBrief(self::briefJson($request->brief))
            ->setModel($model)
            ->setRequestId($requestId)
            ->setStatus($status)
            ->setStopReason($stopReason)
            ->setStopCategory($stopCategory)
            ->setInputTokens($usage->inputTokens)
            ->setOutputTokens($usage->outputTokens)
            ->setCacheReadTokens($usage->cacheReadTokens)
            ->setCacheWriteTokens($usage->cacheWriteTokens)
            ->setTurns($turns)
            ->setCostUsd(number_format($costUsd, 6, '.', ''))
            ->setLogId($logId)
            ->setDurationMs($durationMs)
            ->setError(null !== $error ? mb_substr($error, 0, 60000) : null)
            ->setDocument($document)
            ->setProvenance(ProvenanceMark::encode($provenance))
            ->setWebPages([] === $webPages ? null : json_encode(array_map(static fn (WebPageRead $page): array => $page->toArray(), $webPages), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))
            ->setLeftOut([] === $leftOut ? null : json_encode($leftOut, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId());
        self::setDetails($row, $details);

        $row->save();

        return (int) $row->getId();
    }

    /**
     * A call that made or changed a component of the site. A new component
     * has no row until the editor keeps it: its calls are recorded under the
     * id 0, and count in the spend all the same.
     */
    public function recordComponent(
        int $componentId,
        string $locale,
        string $kind,
        string $request,
        string $status,
        string $model,
        Usage $usage,
        int $turns,
        int $durationMs,
        float $costUsd,
        ?int $logId,
        ?string $requestId = null,
        ?string $stopReason = null,
        ?string $stopCategory = null,
        ?string $error = null,
        ?string $component = null,
        ?AiProvenance $provenance = null,
        ?int $rowId = null,
        RunDetails $details = new RunDetails(),
    ): int {
        $row = $this->rowOf($rowId)
            ->setTargetType(GenerationTarget::TYPE_COMPONENT)
            ->setTargetId($componentId)
            ->setLocale($locale)
            ->setKind($kind)
            ->setPrompt($request)
            ->setBrief(json_encode(['text' => $request], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR))
            ->setModel($model)
            ->setRequestId($requestId)
            ->setStatus($status)
            ->setStopReason($stopReason)
            ->setStopCategory($stopCategory)
            ->setInputTokens($usage->inputTokens)
            ->setOutputTokens($usage->outputTokens)
            ->setCacheReadTokens($usage->cacheReadTokens)
            ->setCacheWriteTokens($usage->cacheWriteTokens)
            ->setTurns($turns)
            ->setCostUsd(number_format($costUsd, 6, '.', ''))
            ->setLogId($logId)
            ->setDurationMs($durationMs)
            ->setError(null !== $error ? mb_substr($error, 0, 60000) : null)
            ->setDocument($component)
            ->setProvenance(ProvenanceMark::encode($provenance))
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId());
        self::setDetails($row, $details);
        $row->save();

        return (int) $row->getId();
    }

    /**
     * Writes the row of a call before its first request to the model, with
     * the status `running`: a call that dies leaves a row, not a gap.
     */
    public function begin(GenerationRequest $request, string $prompt): int
    {
        $row = (new CmsPageGeneration())
            ->setTargetType($request->target->type)
            ->setTargetId($request->target->id)
            ->setLocale($request->locale)
            ->setKind($request->kind)
            ->setPrompt($prompt)
            ->setBrief(self::briefJson($request->brief))
            ->setStatus(self::STATUS_RUNNING)
            ->setCostUsd('0.000000')
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId());
        $row->save();

        return (int) $row->getId();
    }

    /**
     * The same for a call that writes a component.
     */
    public function beginComponent(int $componentId, string $locale, string $kind, string $request): int
    {
        $row = (new CmsPageGeneration())
            ->setTargetType(GenerationTarget::TYPE_COMPONENT)
            ->setTargetId($componentId)
            ->setLocale($locale)
            ->setKind($kind)
            ->setPrompt($request)
            ->setBrief(json_encode(['text' => $request], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR))
            ->setStatus(self::STATUS_RUNNING)
            ->setCostUsd('0.000000')
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId());
        $row->save();

        return (int) $row->getId();
    }

    /**
     * A running call is alive: its row says how far it got, and `updated_at`
     * moves. One statement, no model loaded.
     */
    public function touch(int $rowId, string $model, Usage $usage, int $turns, int $durationMs, float $costUsd, ?int $logId): void
    {
        CmsPageGenerationQuery::create()
            ->filterById($rowId)
            ->filterByStatus(self::STATUS_RUNNING)
            ->update([
                'Model' => $model,
                'InputTokens' => $usage->inputTokens,
                'OutputTokens' => $usage->outputTokens,
                'CacheReadTokens' => $usage->cacheReadTokens,
                'CacheWriteTokens' => $usage->cacheWriteTokens,
                'Turns' => $turns,
                'DurationMs' => $durationMs,
                'CostUsd' => number_format($costUsd, 6, '.', ''),
                'LogId' => $logId,
                'UpdatedAt' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * The call running now for a target in a language, if one is: the guard
     * refuses a second call on the same page. A running row nobody touched
     * for STALE_AFTER seconds is first written `interrupted`, so it is no
     * call running.
     */
    public function runningFor(GenerationTarget $target, string $locale): ?CmsPageGeneration
    {
        self::interruptStale($target->type, $target->id, $locale);

        return CmsPageGenerationQuery::create()
            ->filterByTargetType($target->type)
            ->filterByTargetId($target->id)
            ->filterByLocale($locale)
            ->filterByStatus(self::STATUS_RUNNING)
            ->orderById(Criteria::DESC)
            ->findOne();
    }

    /**
     * The name of the editor who started a call, null when the row has none
     * (a command) or the account is gone.
     */
    public function starterOf(CmsPageGeneration $row): ?string
    {
        $admin = null === $row->getCreatedBy() ? null : AdminQuery::create()->findPk($row->getCreatedBy());

        if (null === $admin) {
            return null;
        }

        $name = trim($admin->getFirstname().' '.$admin->getLastname());

        return '' !== $name ? $name : $admin->getLogin();
    }

    /**
     * What a call needed beyond its outcome, as the row kept it.
     *
     * @return array<string, int>
     */
    public static function toolsOf(CmsPageGeneration $row): array
    {
        $json = (string) $row->getTools();

        if ('' === $json) {
            return [];
        }

        try {
            $counts = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($counts) ? array_map('intval', array_filter($counts, 'is_int')) : [];
    }

    /**
     * What the answer of a call said the page leaves out of the brief. A row
     * written before the column, or by a call that left nothing out, holds
     * nothing.
     *
     * @return list<string>
     */
    public static function leftOutOf(CmsPageGeneration $row): array
    {
        $json = (string) $row->getLeftOut();

        if ('' === $json) {
            return [];
        }

        try {
            $sentences = json_decode($json, true, 2, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($sentences) ? array_values(array_filter($sentences, 'is_string')) : [];
    }

    /**
     * The web pages a call read, as it recorded them. A row written before
     * the column, or by a call that read none, holds none.
     *
     * @return list<WebPageRead>
     */
    public static function webPagesOf(CmsPageGeneration $row): array
    {
        $json = (string) $row->getWebPages();

        if ('' === $json) {
            return [];
        }

        try {
            $rows = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($rows) ? array_values(array_map(static fn (mixed $page): WebPageRead => WebPageRead::fromArray(\is_array($page) ? $page : []), $rows)) : [];
    }

    /**
     * A change made by hand on the document: no call, no cost, and a row all
     * the same, so the history reads in order and the next request starts
     * from the corrected text.
     */
    public function recordManual(GenerationTarget $target, string $locale, string $document, string $note): int
    {
        $row = (new CmsPageGeneration())
            ->setTargetType($target->type)
            ->setTargetId($target->id)
            ->setLocale($locale)
            ->setKind(self::KIND_MANUAL)
            ->setPrompt($note)
            ->setStatus(self::STATUS_DONE)
            ->setCostUsd('0.000000')
            ->setDocument($document)
            ->setCreatedBy($this->securityContext->getAdminUser()?->getId());

        $row->save();

        return (int) $row->getId();
    }

    /**
     * The brief the target was last written from in a language, as the
     * editor typed it: the screen puts it back in the form, so a page is
     * written again from its own brief rather than from a blank.
     */
    public function latestBrief(GenerationTarget $target, string $locale): ?Brief
    {
        $brief = $this->latestBriefJson($target, $locale);

        if (null === $brief) {
            return null;
        }

        // Without the file it came with: the ledger holds none of its text,
        // and a page written again from its brief is written without the
        // file unless the editor joins it again.
        return new Brief($brief['text'], \is_string($brief['tone'] ?? null) ? $brief['tone'] : null, \is_string($brief['audience'] ?? null) ? $brief['audience'] : null);
    }

    /**
     * The file the latest brief of the target came with, as the ledger keeps
     * it: its name and its length in characters. Null without one.
     *
     * @return array{name: string, length: int}|null
     */
    public function latestBriefReference(GenerationTarget $target, string $locale): ?array
    {
        $reference = $this->latestBriefJson($target, $locale)['reference'] ?? null;

        if (!\is_array($reference) || !\is_string($reference['name'] ?? null) || '' === $reference['name'] || !\is_int($reference['length'] ?? null)) {
            return null;
        }

        return ['name' => $reference['name'], 'length' => $reference['length']];
    }

    /**
     * @return array<string, mixed>|null the JSON of the latest brief of the target, with a text
     */
    private function latestBriefJson(GenerationTarget $target, string $locale): ?array
    {
        $row = CmsPageGenerationQuery::create()
            ->filterByTargetType($target->type)
            ->filterByTargetId($target->id)
            ->filterByLocale($locale)
            ->filterByKind(GenerationRequest::KIND_BRIEF)
            ->orderByCreatedAt(Criteria::DESC)
            ->orderById(Criteria::DESC)
            ->findOne();

        $brief = null === $row?->getBrief() ? null : json_decode((string) $row->getBrief(), true);

        if (!\is_array($brief) || !\is_string($brief['text'] ?? null) || '' === trim($brief['text'])) {
            return null;
        }

        return $brief;
    }

    /**
     * The calls made for a target in a language, the latest first.
     *
     * @return list<CmsPageGeneration>
     */
    public function history(GenerationTarget $target, string $locale, int $limit = 50): array
    {
        self::interruptStale($target->type, $target->id, $locale);

        return array_values(iterator_to_array(
            CmsPageGenerationQuery::create()
                ->filterByTargetType($target->type)
                ->filterByTargetId($target->id)
                ->filterByLocale($locale)
                ->orderByCreatedAt(Criteria::DESC)
                ->orderById(Criteria::DESC)
                ->limit($limit)
                ->find(),
            false,
        ));
    }

    /**
     * One row of the ledger, if it belongs to the target: a screen shows the
     * outcome of the call it just made from its id, and an id of another
     * page's call says nothing here.
     */
    public function find(int $id, GenerationTarget $target, string $locale): ?CmsPageGeneration
    {
        self::interruptStale($target->type, $target->id, $locale);

        return CmsPageGenerationQuery::create()
            ->filterById($id)
            ->filterByTargetType($target->type)
            ->filterByTargetId($target->id)
            ->filterByLocale($locale)
            ->findOne();
    }

    /**
     * Writes `interrupted` on the calls of a target type that are still
     * `running` and were not touched for STALE_AFTER seconds: the ledger is
     * where a call that died is found out, nothing else watches it.
     * `updated_at` is left as the last touch, which is when the call was
     * last seen alive.
     *
     * @param int|null    $targetId null for every target of the type
     * @param string|null $locale   null for every language
     */
    public static function interruptStale(string $targetType, ?int $targetId = null, ?string $locale = null): void
    {
        $query = CmsPageGenerationQuery::create()
            ->filterByTargetType($targetType)
            ->filterByStatus(self::STATUS_RUNNING)
            ->filterByUpdatedAt(date('Y-m-d H:i:s', time() - self::STALE_AFTER), Criteria::LESS_THAN);

        if (null !== $targetId) {
            $query->filterByTargetId($targetId);
        }

        if (null !== $locale) {
            $query->filterByLocale($locale);
        }

        $query->update(['Status' => self::STATUS_INTERRUPTED]);
    }

    private function rowOf(?int $rowId): CmsPageGeneration
    {
        return (null === $rowId ? null : CmsPageGenerationQuery::create()->findPk($rowId)) ?? new CmsPageGeneration();
    }

    private static function setDetails(CmsPageGeneration $row, RunDetails $details): void
    {
        $row->setEffort($details->effort)
            ->setCorrections($details->corrections)
            ->setRetries($details->retries)
            ->setTools([] === $details->tools ? null : json_encode($details->tools, \JSON_THROW_ON_ERROR));
    }

    /**
     * The brief as the ledger keeps it. Of a file joined to it: its name, its
     * length and a fingerprint, never its text, which the core keeps with the
     * prompt when the merchant keeps prompts, for as long as it keeps them.
     */
    private static function briefJson(Brief $brief): string
    {
        $json = ['text' => $brief->text, 'tone' => $brief->tone, 'audience' => $brief->audience];

        if (null !== $brief->reference) {
            $json['reference'] = ['name' => $brief->reference->name, 'length' => $brief->reference->length(), 'fingerprint' => $brief->reference->fingerprint()];
        }

        return json_encode($json, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * What the site spent since a date, in US dollars.
     */
    public function spentSince(\DateTimeInterface $since): float
    {
        $sum = CmsPageGenerationQuery::create()
            ->filterByCreatedAt($since, Criteria::GREATER_EQUAL)
            ->withColumn('SUM(cms_page_generation.cost_usd)', 'spent')
            ->select(['spent'])
            ->findOne();

        return (float) ($sum ?? 0);
    }
}
