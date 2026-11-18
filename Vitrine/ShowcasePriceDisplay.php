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

namespace TheliaCMS\Vitrine;

use Thelia\Model\ConfigQuery;
use TheliaCMS\Settings\SiteMode;
use TheliaCMS\TheliaCMS;

/**
 * A showcase site shows no price: the module tells the core and the theme through the
 * setting the core keeps for it, `price_display_mode`, set to `on_request` while the site
 * is a showcase. The core 3.4 then leaves the prices out of its front API and refuses an
 * add to the cart, SEOne leaves the offers out of the structured data, and Flexy draws
 * neither price, nor cart, nor currency. A core 3.3 has no such setting: the variable is
 * written all the same, and the theme reads it.
 *
 * The value the shop had is kept aside and put back when the site is a shop again. A shop
 * that set `on_request` itself keeps it: nothing is kept aside then, nothing is put back.
 */
final readonly class ShowcasePriceDisplay
{
    /** The setting of the core (`Thelia\Domain\Pricing\PriceVisibility\PriceDisplayMode`, core 3.4), named here so the module loads on a core 3.3. */
    public const string CORE_SETTING = 'price_display_mode';

    /** Nobody is shown a price. */
    public const string NO_PRICE = 'on_request';

    /** What the core reads when the setting is not there. */
    private const string CORE_DEFAULT = 'visible';

    /** Setting of the module: the value of the core setting before the site became a showcase, empty when there is none to put back. */
    public const string VALUE_BEFORE_SHOWCASE = 'price_display_mode_before_showcase';

    public function follow(SiteMode $mode): void
    {
        $current = (string) ConfigQuery::read(self::CORE_SETTING, '');

        if ($mode->isShowcase()) {
            if (self::NO_PRICE === $current) {
                return;
            }

            TheliaCMS::setConfigValue(self::VALUE_BEFORE_SHOWCASE, '' === $current ? self::CORE_DEFAULT : $current);
            ConfigQuery::write(self::CORE_SETTING, self::NO_PRICE);

            return;
        }

        $before = (string) TheliaCMS::getConfigValue(self::VALUE_BEFORE_SHOWCASE, '');

        if ('' === $before) {
            return;
        }

        // Put back only what showcase mode set: a value changed by hand meanwhile is the shop's word.
        if (self::NO_PRICE === $current) {
            ConfigQuery::write(self::CORE_SETTING, $before);
        }

        TheliaCMS::setConfigValue(self::VALUE_BEFORE_SHOWCASE, '');
    }
}
