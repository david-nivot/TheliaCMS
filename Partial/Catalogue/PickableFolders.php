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

    private const string SELECT = <<<'SQL'
        SELECT f.id, f.parent, i.title, fallback.title AS fallback_title, fallback.locale AS fallback_locale, parent_i18n.title AS parent_title, f.visible
        FROM folder f
        LEFT JOIN folder_i18n i ON i.id = f.id AND i.locale = :locale
        LEFT JOIN folder_i18n parent_i18n ON parent_i18n.id = f.parent AND parent_i18n.locale = :locale
        SQL;

    private const string MATCHING = ' WHERE '.TranslatedTitle::EXPRESSION.' LIKE :contains';

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
     * Folders whose title in the language holds the text, online or not,
     * top-level ones first. Each is named with its parent: "News" may sit
     * under two sections of the site.
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale): array
    {
        return $this->fetch($this->select().self::MATCHING.' ORDER BY f.parent = 0 DESC, '.TranslatedTitle::SORT.', parent_i18n.title, f.id LIMIT %d', $this->parameters($text, $locale));
    }

    /** How many folders the search matches, of which it answers the first. */
    public function total(string $text, string $locale): int
    {
        return $this->count($this->select().self::MATCHING, $this->parameters($text, $locale));
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
        return self::SELECT.TranslatedTitle::join('folder_i18n', 'f.id');
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

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $chains = AncestorChains::of('folder', 'folder_i18n', array_map(static fn (array $row): int => (int) $row['parent'], $rows), (string) $parameters['locale']);

        return array_map(
            static function (array $row) use ($chains): PickableItem {
                $title = TranslatedTitle::of($row);
                $parent = trim((string) $row['parent_title']);

                return new PickableItem(
                    (int) $row['id'],
                    '' === $parent ? $title : \sprintf('%s (%s)', $title, $parent),
                    1 === (int) $row['visible'],
                    false,
                    $title,
                    $chains[(int) $row['parent']] ?? null,
                );
            },
            $rows,
        );
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function count(string $sql, array $parameters): int
    {
        $statement = Propel::getReadConnection(FolderTableMap::DATABASE_NAME)->prepare('SELECT COUNT(*) FROM ('.$sql.') matched');
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
