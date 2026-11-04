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

namespace TheliaCMS\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Http\ContentSecurityPolicy;
use TheliaCMS\Http\ContentSecurityPolicyMode;

/**
 * The policy holds what a page may load pictures and fonts from, and who may
 * frame it: never scripts, which the snippets and the consent layer write
 * inline.
 */
final class ContentSecurityPolicyTest extends TestCase
{
    public function testNothingIsSentWhenTheModeIsOff(): void
    {
        self::assertNull(ContentSecurityPolicy::for(ContentSecurityPolicyMode::Off, ['https://cdn.example.com'], '/cms/csp-report'));
    }

    public function testEnforcingSendsThePolicyUnderItsOwnHeader(): void
    {
        $policy = ContentSecurityPolicy::for(ContentSecurityPolicyMode::Enforce, ['https://fonts.gstatic.com', 'https://*.axept.io'], '/cms/csp-report');

        self::assertNotNull($policy);
        self::assertSame('Content-Security-Policy', $policy->header);
        self::assertSame(
            "img-src 'self' data: https://fonts.gstatic.com https://*.axept.io; font-src 'self' data: https://fonts.gstatic.com https://*.axept.io; frame-ancestors 'self'",
            $policy->value,
        );
    }

    public function testReportingOnlyAsksForTheReports(): void
    {
        $policy = ContentSecurityPolicy::for(ContentSecurityPolicyMode::ReportOnly, [], '/cms/csp-report');

        self::assertNotNull($policy);
        self::assertSame('Content-Security-Policy-Report-Only', $policy->header);
        self::assertSame("img-src 'self' data:; font-src 'self' data:; frame-ancestors 'self'; report-uri /cms/csp-report", $policy->value);
    }

    public function testNoScriptDirectiveIsEverWritten(): void
    {
        foreach ([ContentSecurityPolicyMode::ReportOnly, ContentSecurityPolicyMode::Enforce] as $mode) {
            $value = ContentSecurityPolicy::for($mode, ['https://cdn.example.com'], '/r')?->value;

            self::assertStringNotContainsString('script-src', (string) $value);
            self::assertStringNotContainsString('default-src', (string) $value);
            self::assertStringNotContainsString('nonce', (string) $value);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function sources(): iterable
    {
        yield 'host' => ['cdn.example.com'];
        yield 'host with a scheme' => ['https://cdn.example.com'];
        yield 'any sub-domain' => ['https://*.axept.io'];
        yield 'port' => ['https://cdn.example.com:8443'];
        yield 'path' => ['https://cdn.example.com/pictures/'];
        yield 'every host over https' => ['https:'];
    }

    #[DataProvider('sources')]
    public function testASourceIsKept(string $source): void
    {
        self::assertTrue(ContentSecurityPolicy::isSource($source));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function notSources(): iterable
    {
        yield 'everything' => ['*'];
        yield 'a keyword' => ["'unsafe-inline'"];
        yield 'another directive' => ['cdn.example.com; script-src *'];
        yield 'a list in one' => ['a.example.com,b.example.com'];
        yield 'a javascript scheme' => ['javascript:'];
        yield 'a quote' => ["cdn.example.com'"];
        yield 'blank' => [' '];
        yield 'a new line' => ["cdn.example.com\nscript-src *"];
        yield 'a trailing new line' => ["cdn.example.com\n"];
    }

    #[DataProvider('notSources')]
    public function testWhatIsNotASourceIsRefused(string $source): void
    {
        self::assertFalse(ContentSecurityPolicy::isSource($source));
    }

    public function testASourceThatIsNotOneNeverReachesTheHeader(): void
    {
        $policy = ContentSecurityPolicy::for(ContentSecurityPolicyMode::Enforce, ['cdn.example.com; script-src *', 'https://ok.example.com'], null);

        self::assertSame("img-src 'self' data: https://ok.example.com; font-src 'self' data: https://ok.example.com; frame-ancestors 'self'", $policy?->value);
    }

    public function testAnUnknownStoredModeIsOff(): void
    {
        self::assertSame(ContentSecurityPolicyMode::Off, ContentSecurityPolicyMode::fromStorage(null));
        self::assertSame(ContentSecurityPolicyMode::Off, ContentSecurityPolicyMode::fromStorage('strict'));
        self::assertSame(ContentSecurityPolicyMode::ReportOnly, ContentSecurityPolicyMode::fromStorage('report-only'));
        self::assertSame(ContentSecurityPolicyMode::Enforce, ContentSecurityPolicyMode::fromStorage(' Enforce '));
    }
}
