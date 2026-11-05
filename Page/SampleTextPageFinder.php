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

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Content\Pipeline\PlaceholderContentChecker;
use TheliaCMS\Install\LegalPageTemplates;
use TheliaCMS\Model\CmsPageContentQuery;

/**
 * Pages that are online and still say what the installer wrote in them.
 *
 * The legal pages a site owes its visitors are created as drafts holding
 * instructions, and publishing one has been refused since the guard in
 * CmsPageWriter. That guard came after the sites did: a site installed before it
 * can have those instructions live, in its sitemap and in search results, and
 * nothing anywhere says so. Somebody has to be told, on a screen they open.
 *
 * What it costs. One query that asks the database to look for a single word from
 * each sample sentence in the published pages, returning identifiers only. The
 * pages it names are then read and confirmed with the same check publishing uses,
 * so the answer here and the refusal there can never disagree. On a site of a few
 * hundred pages the first query reads the content table once and the second reads
 * a handful of rows; nothing loads the whole site into memory, which a plain walk
 * over the published pages would.
 */
final readonly class SampleTextPageFinder
{
    /**
     * Pages named on screen at most, so a site that never wrote any of them does
     * not turn the warning into the page.
     */
    private const int NAMED_AT_MOST = 12;

    public function __construct(
        private PlaceholderContentChecker $placeholders,
        private FoundPageNamer $namer,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     pages: list<array{id: int, locale: string, language: string, language_id: int, title: string}>,
     * }
     */
    public function publishedPagesStillHoldingSampleText(string $locale): array
    {
        $found = [];

        foreach ($this->candidates() as [$pageId, $contentLocale, $html]) {
            if (!$this->placeholders->isPlaceholder($html)) {
                continue;
            }

            $found[] = ['id' => $pageId, 'locale' => $contentLocale];
        }

        return [
            'total' => \count($found),
            'pages' => $this->namer->named(\array_slice($found, 0, self::NAMED_AT_MOST), $locale),
        ];
    }

    /**
     * Published content holding a word only a sample sentence uses.
     *
     * A superset on purpose: the word is looked for in the markup, where the
     * sentence around it may have been broken up by tags, so the answer is
     * confirmed in PHP. Being a superset is what keeps it cheap, and being
     * confirmed is what keeps it right.
     *
     * @return list<array{0: int, 1: string, 2: string}>
     */
    private function candidates(): array
    {
        $words = $this->wordsOfTheSampleSentences();

        if ([] === $words) {
            return [];
        }

        $query = CmsPageContentQuery::create()
            ->filterByPublishedAt(null, Criteria::ISNOTNULL)
            ->useCmsPageQuery()
                ->filterByDeletedAt(null, Criteria::ISNULL)
            ->endUse();

        foreach ($words as $index => $word) {
            $query->condition('word'.$index, 'CmsPageContent.PublishedHtml LIKE ?', '%'.$word.'%');
        }

        $query->combine(array_map(static fn (int $index): string => 'word'.$index, array_keys($words)), Criteria::LOGICAL_OR);

        $rows = $query->select(['PageId', 'Locale', 'PublishedHtml'])->find()->toArray();
        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = [(int) $row['PageId'], (string) $row['Locale'], (string) $row['PublishedHtml']];
        }

        return $candidates;
    }

    /**
     * One word per sample sentence, long enough to be its own signature.
     *
     * Derived from the sentences rather than listed here, so a sentence changed in
     * LegalPageTemplates cannot leave this looking for a word nobody writes any
     * more. Letters only: a word with an apostrophe or an accent in it is the same
     * word spelled several ways once it has been through a form field, and a
     * pattern for `LIKE` has to match the bytes in the column.
     *
     * @return list<string>
     */
    private function wordsOfTheSampleSentences(): array
    {
        $words = [];

        foreach (LegalPageTemplates::sentences() as $sentence) {
            $longest = '';

            foreach (preg_split('/[^\p{L}]+/u', $sentence, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (1 === preg_match('/^[A-Za-z]{6,}$/', $word) && \strlen($word) > \strlen($longest)) {
                    $longest = $word;
                }
            }

            if ('' !== $longest) {
                $words[] = $longest;
            }
        }

        return array_values(array_unique($words));
    }
}
