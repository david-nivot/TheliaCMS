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

namespace TheliaCMS\Tests\Unit\Front;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Front\ContentSecurityPolicyReportController;

/**
 * Anybody can post a report: what is logged is the directive, the origin of
 * what was blocked and the path of the page, once an hour each.
 */
final class ContentSecurityPolicyReportControllerTest extends TestCase
{
    /** @var AbstractLogger&object{logged: list<array{message: string, context: array<string, mixed>}>} */
    private AbstractLogger $logger;

    private ContentSecurityPolicyReportController $controller;

    protected function setUp(): void
    {
        $this->logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $logged = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $this->controller = new ContentSecurityPolicyReportController(new TagAwareAdapter(new ArrayAdapter()), $this->logger);
    }

    public function testAReportIsLoggedWithoutWhatAVisitorTyped(): void
    {
        $response = ($this->controller)($this->post(['csp-report' => [
            'document-uri' => 'https://shop.example.com/contact?email=jane@example.com#form',
            'violated-directive' => 'img-src',
            'effective-directive' => 'img-src',
            'blocked-uri' => 'https://pixel.example.net/p.gif?uid=42&email=jane@example.com',
        ]]));

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, $this->logger->logged);
        self::assertSame(['directive' => 'img-src', 'blocked' => 'https://pixel.example.net', 'page' => '/contact'], $this->logger->logged[0]['context']);
        self::assertStringNotContainsString('jane', json_encode($this->logger->logged, \JSON_THROW_ON_ERROR));
    }

    public function testTheReportingApiIsReadToo(): void
    {
        ($this->controller)($this->post([[
            'type' => 'csp-violation',
            'body' => ['documentURL' => 'https://shop.example.com/a-propos', 'effectiveDirective' => 'font-src', 'blockedURL' => 'https://fonts.example.org/f.woff2'],
        ]]));

        self::assertSame(['directive' => 'font-src', 'blocked' => 'https://fonts.example.org', 'page' => '/a-propos'], $this->logger->logged[0]['context'] ?? null);
    }

    public function testTheSameReportIsLoggedOnceAnHour(): void
    {
        $report = ['csp-report' => ['document-uri' => 'https://shop.example.com/', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://pixel.example.net/p.gif']];

        ($this->controller)($this->post($report));
        ($this->controller)($this->post($report));

        self::assertCount(1, $this->logger->logged);
    }

    public function testAFloodIsCappedAnHour(): void
    {
        for ($host = 0; $host < 500; ++$host) {
            ($this->controller)($this->post(['csp-report' => ['document-uri' => 'https://shop.example.com/', 'effective-directive' => 'img-src', 'blocked-uri' => 'https://h'.$host.'.example.net/p.gif']]));
        }

        self::assertLessThanOrEqual(100, \count($this->logger->logged));
    }

    public function testWhatIsNotAReportIsIgnored(): void
    {
        foreach (['', 'not json', str_repeat('a', 20_000), json_encode(['csp-report' => ['blocked-uri' => str_repeat('x', 20_000)]])] as $body) {
            $response = ($this->controller)(Request::create('/cms/csp-report', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], (string) $body));

            self::assertSame(204, $response->getStatusCode());
        }

        self::assertSame([], $this->logger->logged);
    }

    public function testAnOddValueIsLoggedAsUnknown(): void
    {
        ($this->controller)($this->post(['csp-report' => ['document-uri' => 'javascript:alert(1)', 'effective-directive' => "img-src\ninjected", 'blocked-uri' => "https://evil.example.net\r\nX: y"]]));

        self::assertSame(['directive' => 'img-src', 'blocked' => 'unknown', 'page' => 'unknown'], $this->logger->logged[0]['context'] ?? null);
    }

    /**
     * @param array<mixed> $report
     */
    private function post(array $report): Request
    {
        return Request::create('/cms/csp-report', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode($report, \JSON_THROW_ON_ERROR));
    }
}
