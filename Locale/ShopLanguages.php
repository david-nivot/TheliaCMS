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

namespace TheliaCMS\Locale;

use Thelia\Model\LangQuery;

/**
 * Asks the shop which languages it has, to check a locale a browser sent
 * rather than trust it.
 */
final readonly class ShopLanguages
{
    /**
     * The shop has this language, switched on or not.
     */
    public function exists(string $locale): bool
    {
        return LangQuery::create()->filterByLocale($locale)->exists();
    }

    /**
     * The shop has this language and has switched it on.
     */
    public function isActive(string $locale): bool
    {
        return LangQuery::create()->filterByActive(true)->filterByLocale($locale)->exists();
    }
}
