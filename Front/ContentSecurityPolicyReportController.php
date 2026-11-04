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

namespace TheliaCMS\Front;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Where a browser reports what the policy of a page would have blocked, while
 * the policy runs in report-only.
 *
 * Anybody can post here, so nothing posted is trusted: the body is read up to
 * a few kilobytes, and what is logged is the directive, the origin of what was
 * blocked and the path of the page, nothing a visitor typed (no query string,
 * no full address). Each report is logged once an hour, and no more than
 * `SLOTS` of them an hour: a flood of distinct reports cannot fill the log.
 */
final readonly class ContentSecurityPolicyReportController
{
    private const int MAX_BODY = 16_384;

    /** How many distinct reports are logged an hour, at most. */
    private const int SLOTS = 100;

    public function __construct(
        private TagAwareCacheInterface $cache,
        private ?LoggerInterface $logger = null,
    ) {
    }

    #[Route('/cms/csp-report', name: 'cms.csp_report', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        foreach ($this->reports($request) as $report) {
            $this->log($report);
        }

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * The reports of the body, as `report-uri` sends them (`csp-report`) or
     * as the Reporting API does (a list of `csp-violation`).
     *
     * @return list<array{directive: string, blocked: string, page: string}>
     */
    private function reports(Request $request): array
    {
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, self::MAX_BODY + 1);

        if (false === $body || '' === $body || \strlen($body) > self::MAX_BODY) {
            return [];
        }

        $decoded = json_decode($body, true);

        if (!\is_array($decoded)) {
            return [];
        }

        if (\is_array($decoded['csp-report'] ?? null)) {
            $report = $decoded['csp-report'];

            return [self::report($report['effective-directive'] ?? $report['violated-directive'] ?? null, $report['blocked-uri'] ?? null, $report['document-uri'] ?? null)];
        }

        $reports = [];

        foreach (\array_slice(array_is_list($decoded) ? $decoded : [], 0, 10) as $entry) {
            if (\is_array($entry) && 'csp-violation' === ($entry['type'] ?? null) && \is_array($entry['body'] ?? null)) {
                $reports[] = self::report($entry['body']['effectiveDirective'] ?? null, $entry['body']['blockedURL'] ?? null, $entry['body']['documentURL'] ?? null);
            }
        }

        return $reports;
    }

    /**
     * @return array{directive: string, blocked: string, page: string}
     */
    private static function report(mixed $directive, mixed $blocked, mixed $page): array
    {
        // The legacy format names the directive with its sources: `img-src 'self'`.
        $directive = \is_string($directive) ? strtolower((preg_split('/\s+/', trim($directive)) ?: [''])[0]) : '';

        return [
            'directive' => 1 === preg_match('/^[a-z-]{1,40}$/', $directive) ? $directive : 'unknown',
            'blocked' => self::origin($blocked),
            'page' => self::path($page),
        ];
    }

    /**
     * The origin of what was blocked, or the keyword a browser writes for
     * what has none (`inline`, `data`, `eval`).
     */
    private static function origin(mixed $uri): string
    {
        if (!\is_string($uri) || '' === $uri) {
            return 'unknown';
        }

        if (1 === preg_match('/^[a-z-]{1,20}$/i', $uri)) {
            return strtolower($uri);
        }

        $parts = parse_url($uri);

        if (!\is_array($parts) || !isset($parts['scheme'])) {
            return 'unknown';
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset($parts['host'])) {
            return 1 === preg_match('/^[a-z][a-z0-9+.-]{0,19}$/', $scheme) ? $scheme : 'unknown';
        }

        $origin = $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');

        return 1 === preg_match('#^[a-z][a-z0-9+.-]*://[a-z0-9.-]{1,253}(?::\d{1,5})?$#', $origin) ? $origin : 'unknown';
    }

    private static function path(mixed $uri): string
    {
        $path = \is_string($uri) ? parse_url($uri, \PHP_URL_PATH) : null;

        return \is_string($path) && 1 === preg_match('#^/[a-zA-Z0-9._~!$&()*+,;=:@%/-]{0,199}$#', $path) ? $path : 'unknown';
    }

    /**
     * @param array{directive: string, blocked: string, page: string} $report
     */
    private function log(array $report): void
    {
        $logger = $this->logger;

        if (null === $logger) {
            return;
        }

        $key = implode('|', $report);
        $slot = 'theliacms_csp_report_'.date('YmdH').'_'.(crc32($key) % self::SLOTS);

        $this->cache->get($slot, static function (ItemInterface $item) use ($logger, $report): bool {
            $item->expiresAfter(3600);
            $logger->warning('TheliaCMS: the Content-Security-Policy of {page} would block {blocked} ({directive}). Add its host to the security headers of the settings before enforcing the policy.', $report);

            return true;
        });
    }
}
