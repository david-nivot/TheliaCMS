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
use Thelia\Model\Map\CategoryTableMap;

/**
 * The categories an editor may put in a "Category" block, searched as they
 * type.
 *
 * Each is named with the categories above it: a catalogue often holds several
 * "Vegetables", and only the categories above them tell them apart.
 */
final readonly class PickableCategories
{
    /** Enough to find a category by a few letters of its name, short enough to read. */
    public const int LIMIT = 20;

    /** Read through `TreeOrder`: the title, the titles above, the place in the tree. */
    private const string SELECT = <<<'SQL'
        SELECT c.id, node.title, path.trail, c.visible
        FROM category c
        JOIN tree_node node ON node.id = c.id
        JOIN tree_path path ON path.id = c.id
        SQL;

    /**
     * Categories whose name in the language of the page, or the name of a
     * category above them, holds the text, in the order of the tree: each
     * under its parent, so an aisle brings its shelves. Online ones only,
     * unless the offline ones are asked too, as a menu does.
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale, bool $withOffline = false): array
    {
        return $this->fetch(
            $this->select().$this->matching($withOffline).' ORDER BY path.sort_key, c.id LIMIT %d',
            $this->parameters($text, $locale),
        );
    }

    /** How many categories the search matches, of which it answers the first. */
    public function total(string $text, string $locale, bool $withOffline = false): int
    {
        return $this->count($this->select().$this->matching($withOffline), $this->parameters($text, $locale));
    }

    private function matching(bool $withOffline): string
    {
        return ' WHERE '.($withOffline ? '' : 'c.visible = 1 AND ').TreeOrder::searched('node', 'path').' LIKE :contains';
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $text, string $locale): array
    {
        return ['locale' => $locale, 'contains' => '%'.addcslashes($text, '%_\\').'%'];
    }

    /**
     * The category a block already points at, online or not, so the editor can
     * say it went offline rather than show an empty setting.
     */
    public function find(int $id, string $locale): ?PickableItem
    {
        return $this->fetch($this->select().' WHERE c.id = :id LIMIT %d', ['locale' => $locale, 'id' => $id])[0] ?? null;
    }

    /** With the title in another language, so a record written in one language only stays findable. */
    private function select(): string
    {
        return TreeOrder::with('category', 'category_i18n').self::SELECT;
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return list<PickableItem>
     */
    private function fetch(string $sql, array $parameters): array
    {
        $statement = Propel::getReadConnection(CategoryTableMap::DATABASE_NAME)->prepare(\sprintf($sql, self::LIMIT));
        $statement->execute($parameters);

        return array_map(
            static fn (array $row): PickableItem => new PickableItem(
                (int) $row['id'],
                TreeOrder::name((string) $row['title'], $row['trail']),
                1 === (int) $row['visible'],
                false,
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
        $statement = Propel::getReadConnection(CategoryTableMap::DATABASE_NAME)->prepare(TreeOrder::countOf($sql));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
