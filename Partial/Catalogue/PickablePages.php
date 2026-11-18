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

namespace TheliaCMS\Partial\Catalogue;

use Propel\Runtime\Propel;
use TheliaCMS\Model\Map\CmsPageTableMap;

/**
 * The pages of the CMS a block may point at, searched by their title: a site
 * holds too many of them for a select. The models of the page templates and
 * the pages in the bin are none of them.
 */
final readonly class PickablePages
{
    /** Enough to find a page by a few letters of its title, short enough to read. */
    public const int LIMIT = 20;

    private const string SELECT = <<<'SQL'
        SELECT p.id, i.title, parent_i18n.title AS parent_title, p.visible
        FROM cms_page p
        LEFT JOIN cms_page_i18n i ON i.id = p.id AND i.locale = :locale
        LEFT JOIN cms_page_i18n parent_i18n ON parent_i18n.id = p.parent AND parent_i18n.locale = :locale
        SQL;

    private const string KEPT = 'p.model = 0 AND p.deleted_at IS NULL';

    /**
     * Pages whose title in the language holds the text, the pages at the
     * root first: the sections of a site are what a list is set under.
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale): array
    {
        return $this->fetch(
            self::SELECT.' WHERE '.self::KEPT.' AND i.title LIKE :contains ORDER BY p.parent = 0 DESC, i.title, parent_i18n.title LIMIT %d',
            ['locale' => $locale, 'contains' => '%'.addcslashes($text, '%_\\').'%'],
        );
    }

    /**
     * The page a block already points at, online or not, so the editor can say
     * it went offline rather than show an empty setting. A page in the bin is
     * found too: the list under it is empty until it comes back.
     */
    public function find(int $id, string $locale): ?PickableItem
    {
        return $this->fetch(self::SELECT.' WHERE p.model = 0 AND p.id = :id LIMIT %d', ['locale' => $locale, 'id' => $id])[0] ?? null;
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return list<PickableItem>
     */
    private function fetch(string $sql, array $parameters): array
    {
        $statement = Propel::getReadConnection(CmsPageTableMap::DATABASE_NAME)->prepare(\sprintf($sql, self::LIMIT));
        $statement->execute($parameters);

        return array_map(
            static function (array $row): PickableItem {
                $title = trim((string) $row['title']);
                $title = '' === $title ? \sprintf('#%d', (int) $row['id']) : $title;
                $parent = trim((string) $row['parent_title']);

                return new PickableItem(
                    (int) $row['id'],
                    '' === $parent ? $title : \sprintf('%s (%s)', $title, $parent),
                    1 === (int) $row['visible'],
                );
            },
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }
}
