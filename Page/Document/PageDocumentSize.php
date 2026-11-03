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

namespace TheliaCMS\Page\Document;

/**
 * A file size as a visitor reads it: "812 B", "1.5 KB", "2.4 MB".
 *
 * The unit names are given by the caller, who translates them: "o", "Ko" and
 * "Mo" are what a French reader expects, and a size is not worth a locale
 * database. Binary multiples (1024), one decimal at most.
 */
final class PageDocumentSize
{
    private function __construct()
    {
    }

    /**
     * @param array{byte: string, kilobyte: string, megabyte: string} $units
     *
     * @return string|null null for a size that is not known (zero or less)
     */
    public static function readable(int $bytes, array $units): ?string
    {
        if ($bytes <= 0) {
            return null;
        }

        return match (true) {
            $bytes < 1024 => $bytes.' '.$units['byte'],
            $bytes < 1_048_576 => self::decimal($bytes / 1024).' '.$units['kilobyte'],
            default => self::decimal($bytes / 1_048_576).' '.$units['megabyte'],
        };
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
