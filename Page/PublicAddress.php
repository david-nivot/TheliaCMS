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

namespace TheliaCMS\Page;

use Thelia\Tools\URL;
use TheliaCMS\Menu\SiteRoot;
use TheliaCMS\TheliaCMS;

/**
 * The address a visitor reaches a page at in a language, as the sitemap and
 * the webhooks give it to the outside: absolute, on the host of the language.
 */
final class PublicAddress
{
    public static function of(URL $urls, int $pageId, string $locale): ?string
    {
        try {
            $url = $urls->retrieve(TheliaCMS::PAGE_VIEW, $pageId, $locale)->toString();
        } catch (\Throwable) {
            return null;
        }

        // The home page answers on "/" only: its own address is a 301 to it
        // (CmsPageViewListener), and an address that redirects is one a
        // crawler reports instead of indexing. The host stays the one of the
        // language, as AlternateUrls gives it.
        if ($pageId === (int) TheliaCMS::getConfigValue('home_page_id', '0')) {
            return SiteRoot::of($url);
        }

        return $url;
    }
}
