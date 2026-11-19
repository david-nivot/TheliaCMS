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
use Thelia\Model\Folder;
use Thelia\Model\FolderQuery;
use Thelia\Model\Map\FolderTableMap;

/**
 * The folders an editor may pick: the ones shown on the site, in the order the
 * shop gave them, for a block of the theme; any of them, searched by their
 * title, for a menu entry.
 */
final readonly class PickableFolders
{
    /** Enough to find a folder by a few letters of its title, short enough to read. */
    public const int LIMIT = 20;

    /** Read through `TreeOrder`: the title, the titles above, the place in the tree. */
    private const string SELECT = <<<'SQL'
        SELECT f.id, node.title, path.trail, f.visible
        FROM folder f
        JOIN tree_node node ON node.id = f.id
        JOIN tree_path path ON path.id = f.id
        SQL;

    /**
     * @return list<Folder>
     */
    public function visible(): array
    {
        return iterator_to_array(
            FolderQuery::create()->filterByVisible(1)->orderByPosition()->find(),
            false,
        );
    }

    /**
     * Folders whose title in the language, or the title of a folder above
     * them, holds the text, online or not, in the order of the tree. Each is
     * named with the folders above it: "News" may sit under two sections of
     * the site.
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale): array
    {
        return $this->fetch($this->select().$this->matching().' ORDER BY path.sort_key, f.id LIMIT %d', $this->parameters($text, $locale));
    }

    /** How many folders the search matches, of which it answers the first. */
    public function total(string $text, string $locale): int
    {
        return $this->count($this->select().$this->matching(), $this->parameters($text, $locale));
    }

    private function matching(): string
    {
        return ' WHERE '.TreeOrder::searched('node', 'path').' LIKE :contains';
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $text, string $locale): array
    {
        return ['locale' => $locale, 'contains' => '%'.addcslashes($text, '%_\\').'%'];
    }

    /**
     * The folder an entry already points at, online or not.
     */
    public function find(int $id, string $locale): ?PickableItem
    {
        return $this->fetch($this->select().' WHERE f.id = :id LIMIT %d', ['locale' => $locale, 'id' => $id])[0] ?? null;
    }

    /** With the title in another language, so a record written in one language only stays findable. */
    private function select(): string
    {
        return TreeOrder::with('folder', 'folder_i18n').self::SELECT;
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return list<PickableItem>
     */
    private function fetch(string $sql, array $parameters): array
    {
        $statement = Propel::getReadConnection(FolderTableMap::DATABASE_NAME)->prepare(\sprintf($sql, self::LIMIT));
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
        $statement = Propel::getReadConnection(FolderTableMap::DATABASE_NAME)->prepare(TreeOrder::countOf($sql));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
