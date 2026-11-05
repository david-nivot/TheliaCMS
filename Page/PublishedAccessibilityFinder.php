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
use TheliaCMS\Content\Pipeline\AccessibilityChecker;

/**
 * Pages that are online with an image nobody described, or a link that says
 * nothing.
 *
 * The check runs when a page is published, and a page can be online without
 * having been through it: published before the check existed, by an import, or
 * by its publication date. Somebody has to be told, on a screen they open.
 *
 * What it costs. The published HTML of the pages that can be seen is read a
 * hundred at a time, as only an image or a link can be a problem and no
 * SQL narrows more than that, and each chunk is judged with one question to
 * the media library. Nothing holds the whole site in memory; the time is that
 * of parsing each page once, on request, as the screens that show it are opened.
 */
final readonly class PublishedAccessibilityFinder
{
    /**
     * Pages named on screen at most, so a site that never fixed them does not
     * turn the warning into the page.
     */
    private const int NAMED_AT_MOST = 12;

    private const int CHUNK = 100;

    public function __construct(
        private AccessibilityChecker $checker,
        private FoundPageNamer $namer,
    ) {
    }

    /**
     * @return array{
     *     total: int,
     *     pages: list<array{id: int, locale: string, language: string, language_id: int, title: string, problems: list<string>}>,
     * }
     */
    public function publishedPagesWithProblems(string $locale, int $namedAtMost = self::NAMED_AT_MOST): array
    {
        $found = $this->scan();
        $named = $this->namer->named(array_map(static fn (array $page): array => ['id' => $page['id'], 'locale' => $page['locale']], \array_slice($found, 0, $namedAtMost)), $locale);
        $pages = [];

        foreach ($named as $index => $page) {
            $pages[] = $page + ['problems' => $found[$index]['problems']];
        }

        return ['total' => \count($found), 'pages' => $pages];
    }

    /**
     * @return list<array{id: int, locale: string, problems: list<string>}>
     */
    private function scan(): array
    {
        $found = [];
        $connection = Propel::getConnection('TheliaMain');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Pages a visitor can reach, as `PublishedPageRepository` reads them:
        // visible, outside the bin, inside the publication window, never the
        // model of a type.
        $statement = $connection->prepare(<<<'SQL'
            SELECT c.page_id, c.locale, c.published_html
            FROM cms_page_content c
            INNER JOIN cms_page p ON p.id = c.page_id
            WHERE c.published_at IS NOT NULL
              AND c.published_html IS NOT NULL
              AND (c.published_html LIKE '%<img%' OR c.published_html LIKE '%<a%')
              AND p.deleted_at IS NULL
              AND p.visible = 1
              AND p.model = 0
              AND (p.publish_at IS NULL OR p.publish_at <= :now_start)
              AND (p.unpublish_at IS NULL OR p.unpublish_at > :now_end)
            ORDER BY c.page_id, c.locale
            LIMIT :limit OFFSET :offset
            SQL);

        for ($offset = 0;; $offset += self::CHUNK) {
            $statement->bindValue('now_start', $now);
            $statement->bindValue('now_end', $now);
            $statement->bindValue('limit', self::CHUNK, \PDO::PARAM_INT);
            $statement->bindValue('offset', $offset, \PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

            if ([] === $rows) {
                break;
            }

            // An image is described, or not, in a language: each language of
            // the chunk is judged in its own, with one question to the library.
            $htmlByLocale = [];

            foreach ($rows as $index => $row) {
                $htmlByLocale[(string) $row['locale']][$index] = (string) $row['published_html'];
            }

            foreach ($htmlByLocale as $contentLocale => $htmlByIndex) {
                foreach ($this->checker->checkMany($htmlByIndex, (string) $contentLocale) as $index => $problems) {
                    if ([] !== $problems) {
                        $found[] = ['id' => (int) $rows[$index]['page_id'], 'locale' => (string) $contentLocale, 'problems' => $problems];
                    }
                }
            }

            if (\count($rows) < self::CHUNK) {
                break;
            }
        }

        usort($found, static fn (array $left, array $right): int => [$left['id'], $left['locale']] <=> [$right['id'], $right['locale']]);

        return $found;
    }
}
