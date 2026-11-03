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

namespace TheliaCMS\Form\Front;

/**
 * The path of the site a visitor goes back to after sending a form.
 *
 * It comes from the Referer, which the caller writes: the host is compared with
 * the one being served, and the path is kept only when a browser reads it as a
 * path of this site. A path that starts with two slashes or with a backslash
 * is read as an address on another site, so it is an open redirect.
 */
final readonly class ReturnPath
{
    public static function of(string $referer, string $servedHost): string
    {
        if ('' === $referer) {
            return '/';
        }

        $host = parse_url($referer, \PHP_URL_HOST);

        if (\is_string($host) && $host !== $servedHost) {
            return '/';
        }

        $path = (string) (parse_url($referer, \PHP_URL_PATH) ?: '/');

        if (!self::staysOnTheSite($path)) {
            return '/';
        }

        $query = (string) parse_url($referer, \PHP_URL_QUERY);

        return '' === $query ? $path : $path.'?'.$query;
    }

    private static function staysOnTheSite(string $path): bool
    {
        return 1 === preg_match('#^/(?![/\\\\])#', $path);
    }
}
