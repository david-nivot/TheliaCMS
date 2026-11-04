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

namespace TheliaCMS\Sitemap;

use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;
use TheliaCMS\Page\ScheduledChanges;
use TheliaCMS\TheliaCMS;

/**
 * Adds the CMS pages to the sitemap of the theme.
 *
 * A theme opens its sitemap by calling `theme_hook('sitemap.urls')`, and any
 * module answers there. That is what a section registry would have been, minus
 * the registry: the theme decides where the entries go and the module decides
 * what they are.
 *
 * `lastmod` is the publication date, never the update date of the row. A row is
 * touched by things a reader never sees, and a sitemap that claims every page
 * changed last night is a sitemap a crawler learns to distrust.
 *
 * The answer is kept (`SitemapCache`), and a miss reads the pages once, with
 * their addresses, whatever their number.
 */
final readonly class SitemapThemeHook implements ThemeHookInterface
{
    private const int ADDRESSES_PER_QUERY = 500;

    public function __construct(
        private SitemapCache $cache,
        private RequestStack $requestStack,
        private ScheduledChanges $scheduledChanges,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return 'sitemap.urls' === $hookName;
    }

    public function render(string $hookName, array $parameters): string
    {
        $context = (string) ($parameters['context'] ?? '');

        // The theme splits its sitemap into contexts so a big catalogue can be
        // generated in pieces. Pages belong to the content one.
        if ('' !== $context && 'content' !== $context) {
            return '';
        }

        $requested = (string) ($parameters['lang'] ?? '');

        // A language is a code or a locale. Anything else matches no language, and
        // is refused before it can reach the key of the cache.
        if (1 !== preg_match('/^(?:[a-z]{2}(?:_[A-Z]{2})?)?$/', $requested)) {
            return '';
        }

        $urls = $this->urls();

        // Outside a request (the hook is also played from a command) there is no
        // address to give, so there is no entry, and nothing worth keeping.
        if (null === $urls) {
            return '';
        }

        $now = new \DateTimeImmutable();

        return $this->cache->get(
            $requested,
            $this->requestStack->getMainRequest()?->getHost() ?? 'cli',
            fn (): ?string => $this->xml($urls, $requested, $now),
            // Pages not online yet are not in the sitemap, so the date they
            // arrive is not read from it.
            fn (): ?\DateTimeImmutable => $this->scheduledChanges->nextAfter($now),
        );
    }

    /**
     * Null when the language asked for is none of the active ones.
     */
    private function xml(URL $urls, string $requested, \DateTimeImmutable $now): ?string
    {
        $locales = $this->locales($requested);

        if ([] === $locales) {
            return null;
        }

        $published = $this->publishedPages($now);
        $this->preloadAddresses($urls, $published);

        $out = [];

        foreach ($published as $pageId => $publishedLocales) {
            $alternates = $this->alternates($urls, $pageId, array_keys($publishedLocales));

            foreach ($locales as $locale) {
                if (!isset($publishedLocales[$locale], $alternates[$locale])) {
                    continue;
                }

                $out[] = SitemapEntry::toXml($alternates[$locale], $publishedLocales[$locale], $alternates);
            }
        }

        return implode("\n", $out);
    }

    /**
     * The page in every active language it is published in, itself included.
     *
     * @param list<string> $publishedIn
     *
     * @return array<string, string>
     */
    private function alternates(URL $urls, int $pageId, array $publishedIn): array
    {
        $alternates = [];

        foreach ($publishedIn as $locale) {
            $url = $this->urlOf($urls, $pageId, $locale);

            if (null !== $url) {
                $alternates[$locale] = $url;
            }
        }

        return $alternates;
    }

    /**
     * Pages a visitor may reach, and when each language of them went live, in
     * one statement and without reading any content.
     *
     * The same conditions as `PublishedPageRepository::isReachable()`: online,
     * in the language, with an HTML to serve. The languages are the active ones
     * only, entries and alternates alike. A language marked noindex is left out
     * of both.
     *
     * @return array<int, array<string, \DateTimeImmutable>> page id, then locale
     */
    private function publishedPages(\DateTimeImmutable $now): array
    {
        $bound = $now->format('Y-m-d H:i:s');

        $statement = Propel::getConnection()->prepare(<<<'SQL'
            SELECT p.id AS page_id, c.locale, c.published_at
            FROM cms_page p
            INNER JOIN cms_page_content c ON c.page_id = p.id
            INNER JOIN lang l ON l.locale = c.locale AND l.active = 1
            LEFT JOIN cms_page_i18n i ON i.id = p.id AND i.locale = c.locale
            WHERE p.deleted_at IS NULL
              AND p.visible = 1
              AND p.model = 0
              AND (p.publish_at IS NULL OR p.publish_at <= :now)
              AND (p.unpublish_at IS NULL OR p.unpublish_at > :now_removal)
              AND c.published_at IS NOT NULL
              AND c.published_html IS NOT NULL
              AND COALESCE(i.noindex, 0) = 0
            ORDER BY p.id, c.locale
            SQL);
        $statement->execute(['now' => $bound, 'now_removal' => $bound]);

        $published = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $published[(int) $row['page_id']][(string) $row['locale']] = new \DateTimeImmutable((string) $row['published_at']);
        }

        return $published;
    }

    /**
     * Resolves the addresses of every page in one statement for each language,
     * so that asking for them one by one finds them already known.
     *
     * @param array<int, array<string, \DateTimeImmutable>> $published
     */
    private function preloadAddresses(URL $urls, array $published): void
    {
        $idsByLocale = [];

        foreach ($published as $pageId => $publishedLocales) {
            foreach (array_keys($publishedLocales) as $locale) {
                $idsByLocale[$locale][] = $pageId;
            }
        }

        foreach ($idsByLocale as $locale => $ids) {
            foreach (array_chunk($ids, self::ADDRESSES_PER_QUERY) as $batch) {
                $urls->preloadRewrittenUrls(TheliaCMS::PAGE_VIEW, (string) $locale, $batch);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function locales(string $requested): array
    {
        $active = [];

        foreach (LangQuery::create()->filterByActive(1)->find() as $lang) {
            $active[] = (string) $lang->getLocale();
        }

        if ('' === $requested) {
            return $active;
        }

        // The theme passes either a locale or a two-letter code, depending on
        // how the sitemap was asked for.
        return array_values(array_filter(
            $active,
            static fn (string $locale): bool => $locale === $requested || str_starts_with($locale, $requested.'_'),
        ));
    }

    /**
     * Null outside a request: the core resolves addresses against it and throws
     * when there is none, though its signature promises an instance.
     */
    private function urls(): ?URL
    {
        try {
            return URL::getInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    private function urlOf(URL $urls, int $pageId, string $locale): ?string
    {
        try {
            return $urls->retrieve(TheliaCMS::PAGE_VIEW, $pageId, $locale)->toString();
        } catch (\Throwable) {
            return null;
        }
    }
}
