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

namespace TheliaCMS\Form;

use Thelia\Model\CountryQuery;
use Thelia\Model\Lang;

/**
 * The answers of a country field: the countries the shop shows, named in one
 * language and sorted the way a reader of that language expects.
 */
final readonly class CountryChoices
{
    /**
     * @return list<string>
     */
    public function forLocale(string $locale): array
    {
        $fallback = Lang::getDefaultLanguage()->getLocale();
        $names = [];

        foreach (CountryQuery::create()->filterByVisible(1)->find() as $country) {
            // A country nobody translated into this language is named by intl
            // from its ISO code, then by the default language: dropping it
            // would take it out of the list without anybody noticing.
            $name = trim((string) $country->setLocale($locale)->getTitle());

            if ('' === $name) {
                $name = self::regionName((string) $country->getIsoalpha2(), $locale);
            }

            if ('' === $name && $fallback !== $locale) {
                $name = trim((string) $country->setLocale($fallback)->getTitle());
            }

            if ('' !== $name) {
                $names[$name] = $name;
            }
        }

        return self::sorted(array_values($names), $locale);
    }

    public static function regionName(string $isoCode, string $locale): string
    {
        $code = strtoupper($isoCode);

        if (2 !== \strlen($code) || 'ZZ' === $code || !class_exists(\Locale::class)) {
            return '';
        }

        $name = \Locale::getDisplayRegion('-'.$code, $locale);

        // For a code it does not know, intl gives the code back; ZZ, above, is
        // the one it names "Unknown Region".
        if ($code === $name) {
            return '';
        }

        return $name;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function sorted(array $names, string $locale): array
    {
        $collator = class_exists(\Collator::class) ? \Collator::create($locale) : null;

        if ($collator instanceof \Collator) {
            $collator->sort($names);

            return array_values($names);
        }

        sort($names, \SORT_STRING | \SORT_FLAG_CASE);

        return $names;
    }
}
