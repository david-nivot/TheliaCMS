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

namespace TheliaCMS\Tests\Unit\Form;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Form\Front\ReturnPath;

/**
 * The Referer is written by the caller: after a form, the visitor goes back to
 * a path of this site, never to the site the Referer names.
 */
final class ReturnPathTest extends TestCase
{
    #[DataProvider('referers')]
    public function testGoesBackToAPathOfTheSiteOnly(string $referer, string $expected): void
    {
        self::assertSame($expected, ReturnPath::of($referer, 'host'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function referers(): iterable
    {
        yield 'a page with a query' => ['http://host/page?x=1', '/page?x=1'];
        yield 'the home page without a path' => ['http://host', '/'];
        yield 'a path without a host' => ['/page', '/page'];
        yield 'a path starting with two slashes' => ['http://host//other.site/x', '/'];
        yield 'a path starting with a backslash' => ['http://host/\\other.site', '/'];
        yield 'two slashes written encoded' => ['http://host/%2F%2Fother.site', '/%2F%2Fother.site'];
        yield 'another host' => ['http://other.site/page', '/'];
        yield 'an address relative to the scheme' => ['//other.site/x', '/'];
        yield 'a javascript address' => ['javascript:alert(1)', '/'];
        yield 'no Referer' => ['', '/'];
        yield 'a tab between two slashes' => ["http://host/\t/other.site", '/_/other.site'];
        yield 'a path without a leading slash' => ['page', '/'];
    }
}
