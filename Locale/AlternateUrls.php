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

namespace TheliaCMS\Locale;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\TheliaCMS;

/**
 * The address of the page being served, in each of the other languages.
 *
 * This is what a language switcher needs and what hreflang needs, and they must
 * agree: sending a visitor to the home page because they asked for English —
 * which is what a switcher built on `?lang=` alone does — loses the page they
 * were reading, and advertising an alternate the crawler cannot reach loses the
 * translation altogether.
 */
final readonly class AlternateUrls
{
    public function __construct(
        private RequestStack $requestStack,
        private PublishedPageRepository $pages,
    ) {
    }

    /**
     * Every active language the current page exists in, the current one
     * included, in shop order.
     *
     * @return list<array{locale: string, code: string, title: string, url: string, current: bool}>
     */
    public function all(): array
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return [];
        }

        $currentLocale = $this->currentLocale($request);
        $alternates = [];

        foreach ($this->activeLanguages() as $lang) {
            $locale = (string) $lang->getLocale();
            $url = $this->forLocale($locale);

            if (null === $url) {
                continue;
            }

            $alternates[] = [
                'locale' => $locale,
                'code' => (string) $lang->getCode(),
                'title' => (string) $lang->getTitle(),
                'url' => $url,
                'current' => $locale === $currentLocale,
            ];
        }

        return $alternates;
    }

    /**
     * @return string|null the address of the current page in `$locale`, or null when it has none there
     */
    public function forLocale(string $locale): ?string
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            return null;
        }

        $view = (string) $request->attributes->get('_view');

        if (TheliaCMS::PAGE_VIEW === $view) {
            return $this->cmsPage($request, $locale);
        }

        // Any other rewritten view — a product, a category, a content: the core
        // knows its address in each locale.
        $viewId = '' === $view ? 0 : $this->viewId($request, $view);

        if ($viewId > 0) {
            $path = $this->path($view, $viewId, $locale);

            return null === $path ? null : $this->absolute($path, $locale, $request);
        }

        return $this->sameUrlAnotherLanguage($request, $locale);
    }

    /**
     * A path of the site as visitors reach it in a language: on the domain of
     * that language when each language has its own, on the shop otherwise.
     */
    public function onSiteOf(string $path, string $locale): ?string
    {
        $request = $this->requestStack->getMainRequest();

        return null === $request ? null : $this->absolute($path, $locale, $request);
    }

    private function cmsPage(Request $request, string $locale): ?string
    {
        $pageId = $request->query->getInt(TheliaCMS::PAGE_VIEW.'_id');

        if ($pageId <= 0) {
            return null;
        }

        // A page not published in that language has no address there: serving
        // the French text under an English URL is worse than having no
        // alternate at all (decision D3, spec §3.6).
        if (!$this->pages->isReachable($pageId, $locale)) {
            return null;
        }

        if ($pageId === (int) TheliaCMS::getConfigValue('home_page_id', 0)) {
            return rtrim($this->baseUrl($locale, $request), '/').'/';
        }

        $path = $this->path(TheliaCMS::PAGE_VIEW, $pageId, $locale);

        return null === $path ? null : $this->absolute($path, $locale, $request);
    }

    /**
     * The current URL, asking for another language.
     *
     * Used for the pages that are not a rewritten object — the home page, a
     * checkout step, a module route. With one domain per language the path is
     * simply carried over to the other domain; otherwise the core reads the
     * `lang` parameter.
     */
    private function sameUrlAnotherLanguage(Request $request, string $locale): string
    {
        $path = $request->getPathInfo();
        $parameters = $request->query->all();
        unset($parameters['lang']);

        if (!ConfigQuery::isMultiDomainActivated()) {
            $parameters['lang'] = $locale;
        }

        $query = http_build_query($parameters);

        return rtrim($this->baseUrl($locale, $request), '/').$path.('' === $query ? '' : '?'.$query);
    }

    /**
     * The rewritten path of a view, without its host.
     *
     * `URL::getInstance()` is nullable outside an HTTP request: a menu or a
     * sitemap built from a command must not bring the command down.
     */
    private function path(string $view, int $viewId, string $locale): ?string
    {
        $urls = URL::getInstance();

        if (null === $urls) {
            return null;
        }

        try {
            $retrieved = $urls->retrieve($view, $viewId, $locale);
        } catch (\Throwable) {
            return null;
        }

        $address = $retrieved->rewrittenUrl ?: $retrieved->url;

        if (null === $address || '' === $address) {
            return null;
        }

        $path = (string) parse_url($address, \PHP_URL_PATH);
        $query = (string) parse_url($address, \PHP_URL_QUERY);

        return '/'.ltrim($path, '/').('' === $query ? '' : '?'.$query);
    }

    private function absolute(string $path, string $locale, Request $request): string
    {
        return rtrim($this->baseUrl($locale, $request), '/').'/'.ltrim($path, '/');
    }

    /**
     * `$request->get()` is never used: the identifier of a rewritten view is
     * written to the query string by the rewriting router, and to the request
     * attributes by a route, and the two must be read for what they are.
     */
    private function viewId(Request $request, string $view): int
    {
        $parameter = $view.'_id';

        if ($request->query->has($parameter)) {
            return $request->query->getInt($parameter);
        }

        return (int) $request->attributes->get($parameter, 0);
    }

    private function baseUrl(string $locale, Request $request): string
    {
        if (ConfigQuery::isMultiDomainActivated()) {
            foreach ($this->activeLanguages() as $lang) {
                if ($locale === $lang->getLocale() && '' !== (string) $lang->getUrl()) {
                    return (string) $lang->getUrl();
                }
            }
        }

        $configured = (string) ConfigQuery::getConfiguredShopUrl();

        return '' === $configured ? $request->getSchemeAndHttpHost() : $configured;
    }

    private function currentLocale(Request $request): string
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof \Thelia\Core\HttpFoundation\Session\Session) {
            $lang = $session->getLang();

            if ($lang instanceof Lang) {
                return (string) $lang->getLocale();
            }
        }

        return Lang::getDefaultLanguage()->getLocale();
    }

    /**
     * @return list<Lang>
     */
    private function activeLanguages(): array
    {
        return array_values(iterator_to_array(
            LangQuery::create()->filterByActive(1)->orderByPosition(Criteria::ASC)->find(),
            false
        ));
    }
}
