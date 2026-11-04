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

namespace TheliaCMS\Http;

use TheliaCMS\Consent\ConsentSettings;
use TheliaCMS\TheliaCMS;

/**
 * Whether the pages of the module send a Content-Security-Policy, and the
 * hosts it lets pictures and fonts come from.
 *
 * Off until an administrator turns it on: a policy that blocks the pixel of a
 * pasted snippet breaks the measurement of a site without a word, so it is
 * meant to run in report-only first.
 */
final readonly class ContentSecurityPolicySettings
{
    public const string MODE = 'csp_mode';
    public const string HOSTS = 'csp_hosts';

    /** The fonts of a theme that loads Google Fonts, as Flexy does. */
    public const string GOOGLE_FONTS = 'https://fonts.gstatic.com';

    /** Where the consent banner of Axeptio loads its pictures from. */
    public const string AXEPTIO = 'https://*.axept.io';

    public function __construct(
        private ConsentSettings $consent,
    ) {
    }

    public function mode(): ContentSecurityPolicyMode
    {
        return ContentSecurityPolicyMode::fromStorage(TheliaCMS::getConfigValue(self::MODE));
    }

    /**
     * The hosts saved, or, before anything was saved, those the site is known
     * to need.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $raw = TheliaCMS::getConfigValue(self::HOSTS);

        return null === $raw ? $this->defaultHosts() : self::parse((string) $raw);
    }

    /**
     * @return list<string>
     */
    public function defaultHosts(): array
    {
        return $this->consent->isConfigured() ? [self::GOOGLE_FONTS, self::AXEPTIO] : [self::GOOGLE_FONTS];
    }

    public function save(ContentSecurityPolicyMode $mode, string $hosts): void
    {
        TheliaCMS::setConfigValue(self::MODE, $mode->value);
        TheliaCMS::setConfigValue(self::HOSTS, implode("\n", self::parse($hosts)));
    }

    /**
     * Typed by a human in a text area: one per line, or separated by spaces.
     *
     * @return list<string>
     */
    public static function parse(string $hosts): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/\s+/', trim($hosts)) ?: [],
            static fn (string $host): bool => '' !== $host,
        )));
    }

    /**
     * The entries that are not a source a policy can name.
     *
     * @return list<string>
     */
    public static function invalid(string $hosts): array
    {
        return array_values(array_filter(self::parse($hosts), static fn (string $host): bool => !ContentSecurityPolicy::isSource($host)));
    }
}
