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

/**
 * The Content-Security-Policy of a page of the module.
 *
 * It says where pictures and fonts may come from, and that only the site may
 * frame the page. It says nothing of scripts: the consent layer and the
 * snippets an administrator pastes are written inline, and a nonce cannot be
 * put into pasted HTML nor kept by a page the cache shares. What it guards is
 * what a stylesheet or a `style` attribute could load from another host, and
 * a tracking pixel loaded before the visitor agreed: the second line behind
 * the CSS filter and the consent gate.
 */
final readonly class ContentSecurityPolicy
{
    /**
     * A host source (scheme, `*.` for any sub-domain, port, path), or `https:`
     * alone. No keyword, no `*`, nothing that ends a directive.
     */
    private const string SOURCE = '#^(?:https:|(?:(?:https?|wss?)://)?(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?::(?:[0-9]{1,5}|\*))?(?:/[a-z0-9._~!$&()*+=:@%/-]*)?)$#iD';

    private function __construct(
        public string $header,
        public string $value,
    ) {
    }

    /**
     * @param list<string> $hosts where pictures and fonts may come from besides the site
     */
    public static function for(ContentSecurityPolicyMode $mode, array $hosts, ?string $reportUri): ?self
    {
        $header = $mode->header();

        if (null === $header) {
            return null;
        }

        $sources = implode('', array_map(
            static fn (string $host): string => ' '.$host,
            array_values(array_unique(array_filter($hosts, self::isSource(...)))),
        ));

        $directives = [
            "img-src 'self' data:".$sources,
            "font-src 'self' data:".$sources,
            "frame-ancestors 'self'",
        ];

        if (ContentSecurityPolicyMode::ReportOnly === $mode && null !== $reportUri && '' !== $reportUri) {
            $directives[] = 'report-uri '.$reportUri;
        }

        return new self($header, implode('; ', $directives));
    }

    public static function isSource(string $source): bool
    {
        return 1 === preg_match(self::SOURCE, $source);
    }
}
