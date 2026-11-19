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
 * Each is named with its parent: a catalogue often holds several "Vegetables",
 * and only the category above them tells them apart.
 */
final readonly class PickableCategories
{
    /** Enough to find a category by a few letters of its name, short enough to read. */
    public const int LIMIT = 20;

    private const string SELECT = <<<'SQL'
        SELECT c.id, c.parent, i.title, fallback.title AS fallback_title, fallback.locale AS fallback_locale, parent_i18n.title AS parent_title, c.visible
        FROM category c
        LEFT JOIN category_i18n i ON i.id = c.id AND i.locale = :locale
        LEFT JOIN category_i18n parent_i18n ON parent_i18n.id = c.parent AND parent_i18n.locale = :locale
        SQL;

    /**
     * Categories whose name in the language of the page holds the text,
     * top-level ones first. Online ones only, unless the offline ones are
     * asked too, as a menu does.
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale, bool $withOffline = false): array
    {
        return $this->fetch(
            $this->select().$this->matching($withOffline).' ORDER BY c.parent = 0 DESC, '.TranslatedTitle::SORT.', parent_i18n.title, c.id LIMIT %d',
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
        return ' WHERE '.($withOffline ? '' : 'c.visible = 1 AND ').TranslatedTitle::EXPRESSION.' LIKE :contains';
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
        return self::SELECT.TranslatedTitle::join('category_i18n', 'c.id');
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

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $chains = AncestorChains::of('category', 'category_i18n', array_map(static fn (array $row): int => (int) $row['parent'], $rows), (string) $parameters['locale']);

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
        $statement = Propel::getReadConnection(CategoryTableMap::DATABASE_NAME)->prepare('SELECT COUNT(*) FROM ('.$sql.') matched');
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
