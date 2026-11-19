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
use Thelia\Model\Map\ContentTableMap;

/**
 * The contents of the shop a menu entry may point at, searched by their
 * title: a site keeping its news there soon holds too many for a select.
 *
 * Each is named with its default folder and the folders above it: two
 * contents often share a title
 * ("Legal notice", "About us") in folders that tell them apart.
 */
final readonly class PickableContents
{
    /** Enough to find a content by a few letters of its title, short enough to read. */
    public const int LIMIT = 20;

    /** The default folder tells where a content is filed, read through `TreeOrder`. */
    private const string SELECT = <<<'SQL'
        SELECT c.id, i.title, fallback.title AS fallback_title, fallback.locale AS fallback_locale, folder.title AS folder_title, folder_path.trail AS folder_trail, c.visible
        FROM content c
        LEFT JOIN content_i18n i ON i.id = c.id AND i.locale = :locale
        LEFT JOIN content_folder cf ON cf.content_id = c.id AND cf.folder_id = (SELECT MIN(d.folder_id) FROM content_folder d WHERE d.content_id = c.id AND d.default_folder = 1)
        LEFT JOIN tree_node folder ON folder.id = cf.folder_id
        LEFT JOIN tree_path folder_path ON folder_path.id = cf.folder_id
        SQL;

    /**
     * Contents whose title in the language, or the title of their folder or
     * of a folder above it, holds the text, online or not: a menu may be
     * prepared before what it points at goes online. In the order of the
     * tree of their folder, each folder by title.
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale): array
    {
        return $this->fetch($this->select().$this->matching().' ORDER BY folder_path.sort_key IS NULL, folder_path.sort_key, '.TranslatedTitle::SORT.', c.id LIMIT %d', $this->parameters($text, $locale));
    }

    /** How many contents the search matches, of which it answers the first. */
    public function total(string $text, string $locale): int
    {
        return $this->count($this->select().$this->matching(), $this->parameters($text, $locale));
    }

    private function matching(): string
    {
        return ' WHERE ('.TranslatedTitle::EXPRESSION.' LIKE :contains OR '.TreeOrder::searched('folder', 'folder_path').' LIKE :contains)';
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $text, string $locale): array
    {
        return ['locale' => $locale, 'contains' => '%'.addcslashes($text, '%_\\').'%'];
    }

    /**
     * The content an entry already points at, online or not.
     */
    public function find(int $id, string $locale): ?PickableItem
    {
        return $this->fetch($this->select().' WHERE c.id = :id LIMIT %d', ['locale' => $locale, 'id' => $id])[0] ?? null;
    }

    /** With the title in another language, so a record written in one language only stays findable. */
    private function select(): string
    {
        return TreeOrder::with('folder', 'folder_i18n').self::SELECT.TranslatedTitle::join('content_i18n', 'c.id');
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return list<PickableItem>
     */
    private function fetch(string $sql, array $parameters): array
    {
        $statement = Propel::getReadConnection(ContentTableMap::DATABASE_NAME)->prepare(\sprintf($sql, self::LIMIT));
        $statement->execute($parameters);

        return array_map(
            static function (array $row): PickableItem {
                $title = TranslatedTitle::of($row);
                $folder = TreeOrder::within($row['folder_trail'], $row['folder_title']);

                return new PickableItem(
                    (int) $row['id'],
                    TreeOrder::name($title, $folder),
                    1 === (int) $row['visible'],
                    false,
                    $title,
                    $folder,
                );
            },
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function count(string $sql, array $parameters): int
    {
        $statement = Propel::getReadConnection(ContentTableMap::DATABASE_NAME)->prepare(TreeOrder::countOf($sql));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
