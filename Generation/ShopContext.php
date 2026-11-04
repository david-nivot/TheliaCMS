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

namespace TheliaCMS\Generation;

use Thelia\Model\ConfigQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * What the model is told about the site it writes for: its name, what it
 * says about itself, the language of the page and the currency of the shop.
 * Read at every call: these are settings, and a renamed shop is renamed in
 * the next page.
 */
final readonly class ShopContext
{
    /**
     * @return array{store_name: string, store_description: string, language: string, locale: string, currency: string}
     */
    public function describe(string $locale): array
    {
        $lang = LangQuery::create()->findOneByLocale($locale);
        $currency = CurrencyQuery::create()->findOneByByDefault(1);

        return [
            'store_name' => trim((string) ConfigQuery::read('store_name', '')),
            'store_description' => trim((string) ConfigQuery::read('store_description', '')),
            'language' => (string) ($lang?->getTitle() ?? $locale),
            'locale' => $locale,
            'currency' => (string) ($currency?->getCode() ?? ''),
        ];
    }

    /**
     * The default language of the shop, the one of its back office: what a
     * label an editor reads in a form is written in.
     *
     * @return array{language: string, locale: string}
     */
    public function defaultLanguage(): array
    {
        $lang = Lang::getDefaultLanguage();

        return ['language' => (string) $lang->getTitle(), 'locale' => (string) $lang->getLocale()];
    }
}
