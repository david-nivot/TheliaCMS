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

namespace TheliaCMS\Page;

use Propel\Runtime\Propel;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\TranslationFreshness;

/**
 * The pages whose draft in a language was written from a draft that changed
 * since: what the list of pages and the dashboard tell.
 *
 * One query reads the translated rows with the draft of their source, and the
 * words are compared in PHP: a fingerprint of words cannot be computed in SQL,
 * and the cost does not grow with the number of pages in statements, only in
 * the size of the drafts read. A page in the bin and the model of a page type
 * are not pages of the site and are left out, as everywhere pages are read.
 */
final readonly class StalePages
{
    public function __construct(
        private TranslationFreshness $freshness,
    ) {
    }

    /**
     * @param list<int> $pageIds the pages of the list, whose translation in this language is looked at
     *
     * @return array<int, true> keyed by page id
     */
    public function ids(string $locale, array $pageIds): array
    {
        if ([] === $pageIds) {
            return [];
        }

        $stale = [];

        foreach ($this->translated($locale, $pageIds) as $row) {
            if ($this->isStale($row)) {
                $stale[(int) $row['page_id']] = true;
            }
        }

        return $stale;
    }

    /**
     * @return array<string, int> the number of pages to translate again, by locale
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->translated(null, null) as $row) {
            if ($this->isStale($row)) {
                $counts[(string) $row['locale']] = ($counts[(string) $row['locale']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isStale(array $row): bool
    {
        $recorded = TranslationFreshness::decode((string) $row['source_fingerprint']);
        $source = Document::fromJson((string) $row['source_draft']);

        if (null === $recorded || null === $source) {
            return false;
        }

        return $this->freshness->compare((string) $row['translated_from'], $recorded, $this->freshness->source($source))->isStale();
    }

    /**
     * The translated rows of the live pages of the site, with the draft of the
     * language each was written from.
     *
     * @param list<int>|null $pageIds
     *
     * @return list<array<string, mixed>>
     */
    private function translated(?string $locale, ?array $pageIds): array
    {
        $sql = 'SELECT t.page_id, t.locale, t.translated_from, t.source_fingerprint, s.draft_document AS source_draft'
            .' FROM cms_page_content t'
            .' JOIN cms_page p ON p.id = t.page_id AND p.deleted_at IS NULL AND p.model = 0'
            .' JOIN cms_page_content s ON s.page_id = t.page_id AND s.locale = t.translated_from'
            .' WHERE t.translated_from IS NOT NULL AND t.source_fingerprint IS NOT NULL AND s.draft_document IS NOT NULL';
        $parameters = [];

        if (null !== $locale) {
            $sql .= ' AND t.locale = ?';
            $parameters[] = $locale;
        }

        if (null !== $pageIds) {
            $sql .= ' AND t.page_id IN ('.implode(',', array_map(intval(...), $pageIds)).')';
        }

        $statement = Propel::getConnection('TheliaMain')->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
