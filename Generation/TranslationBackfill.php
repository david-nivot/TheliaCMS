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
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentTarget;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Model\CmsPageGeneration;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Model\CmsPageQuery;

/**
 * Ties the translations written before a site was updated to the draft they
 * were written from, when the ledger of the generator proves it is still the
 * one.
 *
 * Only what is proven: the ledger keeps the call that wrote each translation
 * (`translation` kind, `done`, the brief `Translated from <locale>.`) and
 * when it started, which is when the source was read. A source not saved
 * since (its `updated_at` is not after that start) holds the words the
 * translation was written from, so the translation is current. Any other
 * translation stays untracked: a fingerprint taken from a source that may have
 * changed since would call a stale translation up to date.
 *
 * Idempotent: a content that is followed is left as it is.
 */
final readonly class TranslationBackfill
{
    private const string BRIEF = '/^Translated from ([A-Za-z]{2,3}(?:_[A-Za-z0-9]+)?)\.$/';

    public function __construct(
        private ContentTargets $targets,
        private TranslationFreshness $freshness,
    ) {
    }

    /**
     * @param bool $apply false for a dry run: the outcomes are the same, nothing is written
     *
     * @return list<TranslationBackfillOutcome> one for each content and language the ledger holds a translation of
     */
    public function run(bool $apply): array
    {
        $outcomes = [];

        foreach ($this->latestCalls() as $call) {
            $outcomes[] = $this->tie($call, $apply);
        }

        return $outcomes;
    }

    /**
     * The last call of each content and language that wrote a whole draft
     * (a brief or a translation), when it is a translation: a draft written
     * from a brief since is no longer one.
     *
     * @return list<CmsPageGeneration>
     */
    private function latestCalls(): array
    {
        $latest = [];

        foreach (CmsPageGenerationQuery::create()
            ->filterByKind([GenerationRequest::KIND_BRIEF, GenerationRequest::KIND_TRANSLATION], Criteria::IN)
            ->filterByStatus(GenerationLog::STATUS_DONE)
            ->orderById()
            ->find() as $call) {
            $latest[$call->getTargetType().':'.$call->getTargetId().':'.$call->getLocale()] = $call;
        }

        return array_values(array_filter($latest, static fn (CmsPageGeneration $call): bool => GenerationRequest::KIND_TRANSLATION === $call->getKind()));
    }

    private function tie(CmsPageGeneration $call, bool $apply): TranslationBackfillOutcome
    {
        $type = (string) $call->getTargetType();
        $id = (int) $call->getTargetId();
        $locale = (string) $call->getLocale();
        $content = $type.' #'.$id;
        $sourceLocale = self::sourceLocaleOf($call);

        if (null === $sourceLocale) {
            return new TranslationBackfillOutcome($content, $locale, '', 'The brief of the call does not say which language it was written from.');
        }

        $tied = static fn (?string $reason = null): TranslationBackfillOutcome => new TranslationBackfillOutcome($content, $locale, $sourceLocale, $reason);
        $pair = $this->pair($type, $id, $locale, $sourceLocale);

        if (null === $pair) {
            return $tied('The page is gone or in the bin, or the type of the content is unknown.');
        }

        [$target, $source] = $pair;
        $written = $target->row();

        if (null !== $written->followedSource()) {
            return $tied('Already followed.');
        }

        $draft = Document::fromJson($written->draftDocument());

        if (null === $draft || $draft->isEmpty()) {
            return $tied('This language holds no draft.');
        }

        if (null === $this->freshness->read($source)) {
            return $tied('The language it was written from holds no draft.');
        }

        $saved = $source->row()->updatedAt();

        if (null === $saved || $saved > $call->getCreatedAt()) {
            return $tied('The source was saved after the call that wrote the translation: nothing proves it is the draft it was written from.');
        }

        if ($apply) {
            $this->freshness->follow($target, $source);
        }

        return $tied();
    }

    /**
     * @return array{ContentTarget, ContentTarget}|null the translation and its source, null when the content is not there to follow
     */
    private function pair(string $type, int $id, string $locale, string $sourceLocale): ?array
    {
        if ('page' === $type) {
            $page = CmsPageQuery::create()->findPk($id);

            return null === $page || null !== $page->getDeletedAt() ? null : [$this->targets->forPage($page, $locale), $this->targets->forPage($page, $sourceLocale)];
        }

        $itemType = CatalogueItemType::tryFrom($type);

        return null === $itemType ? null : [$this->targets->forItemKey($itemType, $id, $locale), $this->targets->forItemKey($itemType, $id, $sourceLocale)];
    }

    private static function sourceLocaleOf(CmsPageGeneration $call): ?string
    {
        $brief = json_decode((string) $call->getBrief(), true);

        if (!\is_array($brief) || !\is_string($brief['text'] ?? null) || 1 !== preg_match(self::BRIEF, $brief['text'], $match)) {
            return null;
        }

        return $match[1];
    }
}
