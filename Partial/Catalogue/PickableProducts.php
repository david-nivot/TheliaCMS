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
use Thelia\Model\Map\ProductTableMap;

/**
 * The products an editor may put in a "Product" block, searched as they type.
 *
 * Plain SQL rather than a Propel query: one round trip matching the
 * reference, the name in the language of the page and the barcode of any sale
 * element, which Propel would split over joins and a sub-query for no gain.
 */
final readonly class PickableProducts
{
    /** Enough to find a product by a few letters of its name, short enough to read. */
    public const int LIMIT = 20;

    /** The default category tells where a product is shelved, read through `TreeOrder`. */
    private const string SELECT = <<<'SQL'
        SELECT p.id, p.ref, i.title, p.visible, shelf.title AS category_title, shelf_path.trail AS category_trail
        FROM product p
        LEFT JOIN product_i18n i ON i.id = p.id AND i.locale = :locale
        LEFT JOIN product_category pc ON pc.product_id = p.id AND pc.category_id = (SELECT MIN(d.category_id) FROM product_category d WHERE d.product_id = p.id AND d.default_category = 1)
        LEFT JOIN tree_node shelf ON shelf.id = pc.category_id
        LEFT JOIN tree_path shelf_path ON shelf_path.id = pc.category_id
        SQL;

    /**
     * Products whose reference starts with the text, whose name holds it, or
     * one of whose sale elements carries it as barcode, or whose category
     * (or one above it) holds it in its name. An exact reference comes
     * first, then the products in the order of the tree of their category,
     * each shelf by name. Online ones only, unless the offline ones are
     * asked too: a block shows a product, a menu may be prepared before it
     * goes online.
     *
     * @return list<PickableItem>
     */
    public function search(string $text, string $locale, bool $withOffline = false): array
    {
        return $this->fetch(
            $this->select().$this->matching($withOffline)." ORDER BY p.ref = :text DESC, shelf_path.sort_key IS NULL, shelf_path.sort_key, COALESCE(NULLIF(TRIM(i.title), ''), p.ref), p.ref LIMIT %d",
            $this->parameters($text, $locale),
        );
    }

    /** How many products the search matches, of which it answers the first. */
    public function total(string $text, string $locale, bool $withOffline = false): int
    {
        return $this->count($this->select().$this->matching($withOffline), $this->parameters($text, $locale));
    }

    private function matching(bool $withOffline): string
    {
        return ' WHERE '.($withOffline ? '' : 'p.visible = 1 AND ').<<<'SQL'
            (
                p.ref LIKE :prefix
                OR i.title LIKE :contains
                OR EXISTS (SELECT 1 FROM product_sale_elements pse WHERE pse.product_id = p.id AND pse.ean_code = :text)
                OR
            SQL.' '.TreeOrder::searched('shelf', 'shelf_path').' LIKE :contains)';
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $text, string $locale): array
    {
        $escaped = addcslashes($text, '%_\\');

        return ['locale' => $locale, 'prefix' => $escaped.'%', 'contains' => '%'.$escaped.'%', 'text' => $text];
    }

    /**
     * The product a block already points at, online or not, so the editor can
     * say it went offline rather than show an empty setting.
     */
    public function find(int $id, string $locale): ?PickableItem
    {
        return $this->fetch($this->select().' WHERE p.id = :id LIMIT %d', ['locale' => $locale, 'id' => $id])[0] ?? null;
    }

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
        $statement = Propel::getReadConnection(ProductTableMap::DATABASE_NAME)->prepare(\sprintf($sql, self::LIMIT));
        $statement->execute($parameters);

        return array_map(
            static function (array $row): PickableItem {
                $title = trim((string) $row['title']);
                $ref = (string) $row['ref'];
                $name = '' === $title ? $ref : \sprintf('%s (%s)', $title, $ref);

                return new PickableItem(
                    (int) $row['id'],
                    $name,
                    1 === (int) $row['visible'],
                    false,
                    $name,
                    TreeOrder::within($row['category_trail'], $row['category_title']),
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
        $statement = Propel::getReadConnection(ProductTableMap::DATABASE_NAME)->prepare(TreeOrder::countOf($sql));
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }
}
