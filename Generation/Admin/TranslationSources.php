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

namespace TheliaCMS\Generation\Admin;

use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Content\Editing\TranslationSource;
use TheliaCMS\Generation\PageHeader;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageI18nQuery;

/**
 * The languages a target holds a draft in, other than the one on screen:
 * what the screen offers to write this language from, when it holds nothing
 * yet. Only languages the shop has switched on, in their order.
 */
final readonly class TranslationSources
{
    public function __construct(
        private ItemContentRepository $items,
        private TranslationFreshness $freshness,
    ) {
    }

    /**
     * @return list<array{locale: string, label: string}>
     */
    public function forPage(int $pageId, string $locale): array
    {
        $drafts = [];

        foreach (CmsPageContentQuery::create()->filterByPageId($pageId)->find() as $content) {
            $drafts[(string) $content->getLocale()] = $content->getDraftDocument();
        }

        return $this->sources($drafts, $locale);
    }

    /**
     * The draft of the page in a language, with the fingerprint of its words
     * as it is read now.
     */
    public function pageSource(int $pageId, string $sourceLocale): ?TranslationSource
    {
        $held = CmsPageI18nQuery::create()->filterById($pageId)->filterByLocale($sourceLocale)->findOne();

        return $this->fingerprinted(
            Document::fromJson(CmsPageContentQuery::create()->filterByPageId($pageId)->filterByLocale($sourceLocale)->findOne()?->getDraftDocument()),
            (null === $held ? new PageHeader() : PageHeader::of($held))->byColumn(),
        );
    }

    /**
     * The header of the page in a language, null when it says nothing there.
     */
    public function pageHeader(int $pageId, string $sourceLocale): ?PageHeader
    {
        $held = CmsPageI18nQuery::create()->filterById($pageId)->filterByLocale($sourceLocale)->findOne();
        $header = null === $held ? null : PageHeader::of($held);

        return null === $header || $header->isEmpty() ? null : $header;
    }

    /**
     * @return list<array{locale: string, label: string}>
     */
    public function forItem(CatalogueItemType $type, int $id, string $locale): array
    {
        return $this->sources($this->items->draftsByLocale($type, $id), $locale);
    }

    public function itemSource(CatalogueItemType $type, int $id, string $sourceLocale): ?TranslationSource
    {
        return $this->fingerprinted($this->items->draftDocument($type, $id, $sourceLocale));
    }

    /**
     * @param array<string, string|null>|null $header the words of the header of the content, null for one that has none to follow
     */
    private function fingerprinted(?Document $document, ?array $header = null): ?TranslationSource
    {
        return null === $document ? null : $this->freshness->source($document, $header);
    }

    /**
     * @param array<string, string|null> $drafts the draft document by locale
     *
     * @return list<array{locale: string, label: string}>
     */
    private function sources(array $drafts, string $locale): array
    {
        $sources = [];

        /** @var Lang $lang */
        foreach (LangQuery::create()->filterByActive(1)->orderByPosition()->find() as $lang) {
            $candidate = (string) $lang->getLocale();

            if ($candidate === $locale || !self::holdsSomething($drafts[$candidate] ?? null)) {
                continue;
            }

            $sources[] = ['locale' => $candidate, 'label' => (string) $lang->getTitle()];
        }

        return $sources;
    }

    private static function holdsSomething(?string $json): bool
    {
        $document = Document::fromJson($json);

        return $document instanceof Document && !$document->isEmpty();
    }
}
