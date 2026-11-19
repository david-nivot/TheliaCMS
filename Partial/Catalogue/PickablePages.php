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

    /** Read through `TreeOrder`: the title, the titles above, the place in the tree. */
    private const string SELECT = <<<'SQL'
        SELECT p.id, node.title, path.trail, p.visible, p.deleted_at
        FROM cms_page p
        JOIN tree_node node ON node.id = p.id
        JOIN tree_path path ON path.id = p.id
        SQL;

    private const string KEPT = 'p.model = 0 AND p.deleted_at IS NULL';

    /**
     * Pages whose title in the language, or the title of a page above them,
     * holds the text, in the order of the tree: each page under its parent,
     * so a section brings its pages. The pages left out are those the field cannot take, such as a page and
     * the pages under it when its parent is chosen.
     *
     * @param list<int> $leftOut
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale, array $leftOut = []): array
    {
        return $this->fetch(
            $this->select().$this->matching($leftOut).' ORDER BY path.sort_key, p.id LIMIT %d',
            $this->parameters($text, $locale),
        );
    }

    /**
     * How many pages the search matches, of which it answers the first.
     *
     * @param list<int> $leftOut
     */
    public function total(string $text, string $locale, array $leftOut = []): int
    {
        return $this->count($this->select().$this->matching($leftOut), $this->parameters($text, $locale));
    }

    /**
     * @param list<int> $leftOut
     */
    private function matching(array $leftOut): string
    {
        $kept = [] === $leftOut
            ? self::KEPT
            : self::KEPT.' AND p.id NOT IN ('.implode(', ', array_map(intval(...), $leftOut)).')';

        return ' WHERE '.$kept.' AND '.TreeOrder::searched('node', 'path').' LIKE :contains';
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $text, string $locale): array
    {
        return ['locale' => $locale, 'contains' => '%'.addcslashes($text, '%_\\').'%'];
    }

    /**
     * The page a block already points at, online or not, so the editor can say
     * it went offline rather than show an empty setting. A page in the bin is
     * found too: the list under it is empty until it comes back.
     */
    public function find(int $id, string $locale): ?PickableItem
    {
        return $this->fetch($this->select().' WHERE p.model = 0 AND p.id = :id LIMIT %d', ['locale' => $locale, 'id' => $id])[0] ?? null;
    }

    /** With the title in another language, so a record written in one language only stays findable. */
    private function select(): string
    {
        return TreeOrder::with('cms_page', 'cms_page_i18n').self::SELECT;
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
            static fn (array $row): PickableItem => new PickableItem(
                (int) $row['id'],
                TreeOrder::name((string) $row['title'], $row['trail']),
                1 === (int) $row['visible'],
                null !== $row['deleted_at'],
                (string) $row['title'],
                $row['trail'],
            ),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function count(string $sql, array $parameters): int
    {
        $statement = Propel::getReadConnection(CmsPageTableMap::DATABASE_NAME)->prepare(TreeOrder::countOf($sql));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
