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

/**
 * Where each record of a tree (pages, categories, folders) sits, read in one
 * statement: the titles above it, and a key that sorts the tree as the back
 * office shows it, each branch under its parent, siblings by position, then
 * title. A list sorted by it reads as a tree, and a search that reads the
 * path finds the records under a parent by the name of the parent.
 *
 * The recursion carries numbers only (the record, an ancestor, how far up):
 * the titles and the key are put together afterwards by GROUP_CONCAT, so no
 * string grows in the recursion, where MySQL would fix its length and its
 * collation from the first row.
 *
 * `tree_node` gives, per record, `title` (as shown, "Démo [fr]" or "#12"
 * when it has none in the language) and `plain` (the title searched, with no
 * mark). `tree_path` gives `trail` (the titles above it, shown),
 * `plain_trail` (the same, searched) and `sort_key`. A record whose parent is
 * gone sits at the top. The statement binds `:locale`.
 */
final class TreeOrder
{
    public const string SEPARATOR = ' › ';

    private const string END = "/* end of the tree */\n";

    /** Deeper than any tree a back office is used with; stops a corrupt one. */
    private const int MAX_DEPTH = 32;

    /** As `TranslatedTitle::of()` names a record, in SQL. */
    private const string SHOWN = "CASE WHEN NULLIF(TRIM(i.title), '') IS NOT NULL THEN TRIM(i.title) WHEN NULLIF(TRIM(fallback.title), '') IS NOT NULL THEN CONCAT(TRIM(fallback.title), ' [', LEFT(fallback.locale, 2), ']') ELSE CONCAT('#', t.id) END";

    /**
     * The opening `WITH RECURSIVE …` of a statement reading `tree_node` and
     * `tree_path` of the table.
     */
    public static function with(string $table, string $i18n): string
    {
        return \sprintf(
            <<<'SQL'
                WITH RECURSIVE tree_node AS (
                    SELECT t.id, t.parent, %3$s AS title, %4$s AS plain,
                        ROW_NUMBER() OVER (PARTITION BY t.parent ORDER BY t.position, %4$s = '', %4$s, t.id) AS rank_in_parent
                    FROM %1$s t
                    LEFT JOIN %2$s i ON i.id = t.id AND i.locale = :locale%5$s
                ),
                tree_up AS (
                    SELECT n.id AS node, n.id AS ancestor, n.parent AS next_up, 0 AS distance FROM tree_node n
                    UNION ALL
                    SELECT u.node, a.id, a.parent, u.distance + 1
                    FROM tree_up u
                    JOIN tree_node a ON a.id = u.next_up
                    WHERE u.next_up <> 0 AND u.distance < %6$d
                ),
                tree_path AS (
                    SELECT u.node AS id,
                        GROUP_CONCAT(CASE WHEN u.distance > 0 THEN a.title END ORDER BY u.distance DESC SEPARATOR '%7$s') AS trail,
                        GROUP_CONCAT(CASE WHEN u.distance > 0 THEN a.plain END ORDER BY u.distance DESC SEPARATOR '%7$s') AS plain_trail,
                        GROUP_CONCAT(LPAD(a.rank_in_parent, 6, '0') ORDER BY u.distance DESC SEPARATOR '.') AS sort_key
                    FROM tree_up u
                    JOIN tree_node a ON a.id = u.ancestor
                    GROUP BY u.node
                )
                /* end of the tree */

                SQL,
            $table,
            $i18n,
            self::SHOWN,
            TranslatedTitle::EXPRESSION,
            TranslatedTitle::join($i18n, 't.id'),
            self::MAX_DEPTH,
            self::SEPARATOR,
        );
    }

    /**
     * A statement opened by `with()` turned into the count of its rows: the
     * `WITH` stays in front, the reading goes into the count.
     */
    public static function countOf(string $statement): string
    {
        [$tree, $reading] = explode(self::END, $statement, 2) + [1 => ''];

        if ('' === $reading) {
            throw new \LogicException('The statement does not open with TreeOrder::with().');
        }

        return $tree.self::END.'SELECT COUNT(*) FROM ('.$reading.') matched';
    }

    /**
     * What a search reads of a record of the tree: its title and those above
     * it. `$node` and `$path` are the aliases of `tree_node` and `tree_path`.
     */
    public static function searched(string $node, string $path): string
    {
        return \sprintf("CONCAT_WS('%s', %s.plain_trail, %s.plain)", self::SEPARATOR, $path, $node);
    }

    /**
     * The whole path of a record of the tree, itself included: where a
     * product or a content filed in it sits. Null when it is in none.
     */
    public static function within(?string $trail, ?string $title): ?string
    {
        if (null === $title || '' === $title) {
            return null;
        }

        return null === $trail || '' === $trail ? $title : $trail.self::SEPARATOR.$title;
    }

    /**
     * A record named for a field that holds it: its title, and the whole path
     * above it, so two of the same title under parents of the same title stay
     * apart once chosen.
     */
    public static function name(string $title, ?string $trail): string
    {
        return null === $trail || '' === $trail ? $title : \sprintf('%s (%s)', $title, $trail);
    }
}
