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

namespace TheliaCMS\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\IpUtils;
use Thelia\Domain\Webhook\WebhookHttpClientFactory;
use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Tests\Support\MinimalPdf;

/**
 * The tool that reads a page for the model is a way into the network of the
 * server: whatever the model, or the page it reads, asks for, nothing inside
 * is reached. The default client of the tool is the one of the core, so these
 * tests run the real guard and send nothing out.
 */
final class ReadUrlToolTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesOfTheInside(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/admin'];
        yield 'loopback on https' => ['https://127.0.0.1/'];
        yield 'private 10/8' => ['http://10.0.0.1/'];
        yield 'private 192.168/16' => ['http://192.168.1.10/'];
        yield 'private 172.16/12' => ['http://172.16.0.5/'];
        yield 'link-local metadata of a cloud' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'loopback in IPv6' => ['http://[::1]/'];
        yield 'a name that resolves to the loopback' => ['http://localhost/'];
    }

    #[DataProvider('addressesOfTheInside')]
    public function testAnAddressOfTheInsideIsRefusedByTheGuardOfTheCore(string $url): void
    {
        $page = (new ReadUrlTool())->read($url);

        self::assertFalse($page->wasRead());
        self::assertSame('blocked_address', $page->errorCode, 'The refusal comes from the client of the core, not from a failed connection.');
        self::assertNull($page->text);
    }

    public function testARedirectToTheInsideIsRefusedToo(): void
    {
        $inner = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'http://169.254.169.254/latest/meta-data/']]));
        $tool = new ReadUrlTool((new NoPrivateNetworkHttpClient($inner, [...IpUtils::PRIVATE_SUBNETS]))->withOptions(WebhookHttpClientFactory::OPTIONS));

        $page = $tool->read('https://example.com/go');

        self::assertSame('blocked_address', $page->errorCode, 'Every hop of a redirect goes through the guard.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addressesNotAllowed(): iterable
    {
        yield 'a file' => ['file:///etc/passwd', 'invalid_url'];
        yield 'a file with a host' => ['file://example.com/etc/passwd', 'url_not_allowed'];
        yield 'ftp' => ['ftp://example.com/a', 'url_not_allowed'];
        yield 'credentials' => ['https://user:secret@example.com/', 'url_not_allowed'];
        yield 'a port of a service' => ['http://example.com:6379/', 'url_not_allowed'];
        yield 'no scheme' => ['example.com/page', 'invalid_url'];
        yield 'empty' => ['', 'invalid_url'];
    }

    #[DataProvider('addressesNotAllowed')]
    public function testOnlyHttpOnTheUsualPortsWithoutCredentialsIsRead(string $url, string $code): void
    {
        $tool = new ReadUrlTool(new MockHttpClient(static fn () => throw new \LogicException('Nothing may be requested.')));

        self::assertSame($code, $tool->read($url)->errorCode);
    }

    public function testTheHostsTheCallerNamesAreTheOnlyOnesReadIncludingAfterARedirect(): void
    {
        $tool = new ReadUrlTool(new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains($url, 'example.com')
            ? new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'https://other.example.org/x']])
            : throw new \LogicException('The other host may not be requested.')));

        self::assertSame('url_not_allowed', $tool->read('https://other.example.org/', ['example.com'])->errorCode);
        self::assertSame('url_not_allowed', $tool->read('https://example.com/', ['example.com'])->errorCode, 'A redirect leaving the hosts is refused before it is followed.');
    }

    public function testAPageIsReducedToItsText(): void
    {
        $html = '<html><head><title>T</title><style>p{}</style><script>alert(1)</script></head><body><h1>Titre</h1><!-- hidden --><p>Un  paragraphe &amp; un autre.</p><ul><li>Un</li><li>Deux</li></ul></body></html>';
        $tool = new ReadUrlTool(new MockHttpClient(new MockResponse($html, ['response_headers' => ['Content-Type' => 'text/html']])));

        $page = $tool->read('https://example.com/');

        self::assertTrue($page->wasRead());
        self::assertSame("Titre\nUn paragraphe & un autre.\nUn\nDeux", $page->text);
    }

    public function testAPageIsCutAtTheCeilingAndABodyOfAnotherTypeIsNotRead(): void
    {
        $long = new ReadUrlTool(new MockHttpClient(new MockResponse(str_repeat('mot ', 30_000), ['response_headers' => ['Content-Type' => 'text/plain; charset=utf-8']])));
        self::assertSame(ReadUrlTool::MAX_CHARACTERS, mb_strlen((string) $long->read('https://example.com/')->text));

        $image = new ReadUrlTool(new MockHttpClient(new MockResponse("\x89PNG", ['response_headers' => ['Content-Type' => 'image/png']])));
        self::assertSame('unsupported_content_type', $image->read('https://example.com/a.png')->errorCode);

        $missing = new ReadUrlTool(new MockHttpClient(new MockResponse('', ['http_code' => 404])));
        self::assertSame('url_not_accessible', $missing->read('https://example.com/none')->errorCode);
    }

    public function testRedirectsAreFollowedThreeTimesAtMost(): void
    {
        $count = 0;
        $tool = new ReadUrlTool(new MockHttpClient(static function () use (&$count): MockResponse {
            ++$count;

            return new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => '/next'.$count]]);
        }));

        self::assertSame('too_many_redirects', $tool->read('https://example.com/')->errorCode);
        self::assertSame(4, $count);
    }

    public function testTheToolOffersItsDefinitionToTheGateway(): void
    {
        $definition = (new ReadUrlTool())->definition();

        self::assertSame('read_url', $definition->name);
        self::assertSame(['url'], $definition->inputSchema['required']);
    }

    public function testAPdfIsReadAsItsText(): void
    {
        $page = $this->pdfTool(MinimalPdf::of('Fiche produit : garantie 5 ans'))->read('https://example.com/fiche.pdf');

        self::assertTrue($page->wasRead());
        self::assertSame('Fiche produit : garantie 5 ans', $page->text);
    }

    public function testTheTextOfAPdfIsCutAtTheSameCeilingAsAPage(): void
    {
        $page = $this->pdfTool(MinimalPdf::of(str_repeat('mot ', 12_000)))->read('https://example.com/long.pdf');

        self::assertTrue($page->wasRead());
        self::assertSame(ReadUrlTool::MAX_CHARACTERS, mb_strlen((string) $page->text));
    }

    public function testADamagedPdfIsAFailureWithACode(): void
    {
        foreach (['%PDF-1.7', '%PDF-1.4 not a pdf at all', substr(MinimalPdf::of('Texte'), 0, 120), random_bytes(64)] as $body) {
            $page = $this->pdfTool($body)->read('https://example.com/cassé.pdf');

            self::assertFalse($page->wasRead());
            self::assertSame('unreadable_pdf', $page->errorCode);
            self::assertNull($page->text);
        }
    }

    public function testAProtectedPdfIsAFailureWithACode(): void
    {
        $page = $this->pdfTool(MinimalPdf::of('Secret', '/Encrypt 5 0 R'))->read('https://example.com/protege.pdf');

        self::assertSame('unreadable_pdf', $page->errorCode);
    }

    public function testAPdfWithoutTextIsAnEmptyPage(): void
    {
        $page = $this->pdfTool(MinimalPdf::of(''))->read('https://example.com/scan.pdf');

        self::assertSame('empty_page', $page->errorCode);
    }

    public function testAPdfLongerThanTheCeilingIsNotParsed(): void
    {
        $page = $this->pdfTool(MinimalPdf::of('Texte').str_repeat(' ', ReadUrlTool::MAX_BYTES))->read('https://example.com/enorme.pdf');

        self::assertSame('unreadable_pdf', $page->errorCode, 'A body cut at the ceiling is not a whole file.');
    }

    private function pdfTool(string $body): ReadUrlTool
    {
        return new ReadUrlTool(new MockHttpClient(new MockResponse($body, ['response_headers' => ['Content-Type' => 'application/pdf']])));
    }
}
