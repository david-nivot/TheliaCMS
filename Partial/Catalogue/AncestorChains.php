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

/**
 * Where a record sits in its tree, from the top down: what tells apart two
 * records of the same title whose parents share a name too ("2026" under the
 * "News" of two sections). Read for the few records a search answers, one
 * statement per level, never for the whole tree.
 */
final class AncestorChains
{
    /** Deeper than any tree of a shop: a loop left by an import stops here. */
    private const int MAX_DEPTH = 32;

    public const string SEPARATOR = ' › ';

    /**
     * The titles from the top of the tree down to each record, the record
     * included, in the language asked or another one (`TranslatedTitle`).
     *
     * @param string    $table the table of the tree, with `id` and `parent`
     * @param string    $i18n  its i18n table
     * @param list<int> $ids
     *
     * @return array<int, string> the chain of each record found, joined by `SEPARATOR`
     */
    public static function of(string $table, string $i18n, array $ids, string $locale): array
    {
        $nodes = [];
        $frontier = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        for ($depth = 0; [] !== $frontier && $depth < self::MAX_DEPTH; ++$depth) {
            $statement = Propel::getReadConnection('TheliaMain')->prepare(\sprintf(
                'SELECT t.id, t.parent, i.title, fallback.title AS fallback_title, fallback.locale AS fallback_locale FROM %s t LEFT JOIN %s i ON i.id = t.id AND i.locale = :locale%s WHERE t.id IN (%s)',
                $table,
                $i18n,
                TranslatedTitle::join($i18n, 't.id'),
                implode(', ', array_map(intval(...), $frontier)),
            ));
            $statement->execute(['locale' => $locale]);
            $frontier = [];

            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $id = (int) $row['id'];
                $parent = (int) $row['parent'];
                $nodes[$id] = ['parent' => $parent, 'title' => TranslatedTitle::of($row)];

                if ($parent > 0 && !isset($nodes[$parent])) {
                    $frontier[] = $parent;
                }
            }

            $frontier = array_values(array_unique($frontier));
        }

        $chains = [];

        foreach ($ids as $id) {
            $titles = [];
            $seen = [];

            for ($node = $id; isset($nodes[$node]) && !isset($seen[$node]); $node = $nodes[$node]['parent']) {
                $seen[$node] = true;
                array_unshift($titles, $nodes[$node]['title']);
            }

            if ([] !== $titles) {
                $chains[$id] = implode(self::SEPARATOR, $titles);
            }
        }

        return $chains;
    }
}
