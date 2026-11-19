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
 * A record with no title in the language asked is named by the one of another
 * language, the default first ("Démo [fr]"), or by its number. The query names
 * the title asked `i.title` and selects `fallback.title` and `fallback.locale`.
 */
final class TranslatedTitle
{
    /** Empty rather than null, so an empty search still finds a record with no title. */
    public const string EXPRESSION = "COALESCE(NULLIF(TRIM(i.title), ''), fallback.title, '')";

    /** The records with no title last. */
    public const string SORT = self::EXPRESSION." = '', ".self::EXPRESSION;

    /** Joined only for the rows with no title in the language asked. */
    public static function join(string $table, string $id): string
    {
        return \sprintf(
            " LEFT JOIN %1\$s fallback ON (i.title IS NULL OR TRIM(i.title) = '') AND fallback.id = %2\$s AND fallback.locale = (SELECT other.locale FROM %1\$s other WHERE other.id = %2\$s AND TRIM(other.title) <> '' ORDER BY other.locale = (SELECT l.locale FROM lang l WHERE l.by_default = 1 LIMIT 1) DESC, other.locale LIMIT 1)",
            $table,
            $id,
        );
    }

    /**
     * @param array<string, mixed> $row with `id`, `title`, `fallback_title` and `fallback_locale`
     */
    public static function of(array $row): string
    {
        $title = trim((string) $row['title']);

        if ('' !== $title) {
            return $title;
        }

        $other = trim((string) ($row['fallback_title'] ?? ''));

        return '' === $other
            ? \sprintf('#%d', (int) $row['id'])
            : \sprintf('%s [%s]', $other, substr((string) $row['fallback_locale'], 0, 2));
    }
}
