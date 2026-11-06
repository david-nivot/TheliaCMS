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

namespace TheliaCMS\Search\Front;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use Thelia\Tools\URL;
use TheliaCMS\Front\ThemeTemplateRenderer;
use TheliaCMS\Http\ContentSecurityPolicyListener;
use TheliaCMS\Menu\SiteRoot;
use TheliaCMS\Search\PageSearch;
use TheliaCMS\Search\SearchTerms;
use TheliaCMS\TheliaCMS;

/**
 * The search results page.
 *
 * One route with the paths of the languages the module ships, rather than a
 * route per locale with `_locale` on it: Thelia resolves the language of a
 * request its own way (session, `lang`, domain), and a route that forced one
 * would render a French page under an English address whenever the two
 * disagreed. The paths are on the reserved list, so no page can take one.
 */
final readonly class CmsSearchController
{
    private const string TEMPLATE = 'cms-search';
    private const string FALLBACK = '@TheliaCMSModule/front/cms-search.html.twig';
    private const int PER_PAGE = 10;

    public function __construct(
        private ThemeTemplateRenderer $renderer,
        private PageSearch $search,
        private LangService $langService,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{cmsSearchPath}',
        name: 'cms.search',
        requirements: ['cmsSearchPath' => 'recherche|search'],
        methods: ['GET'],
        // A shop keeps the product search of its theme on `/search`, where its
        // search bar sends visitors; only a showcase site, with no catalogue to
        // search, hands the path to its pages. Read when the path is matched,
        // so switching the mode needs no cache clear.
        condition: "service('theliacms_settings').isShowcase()",
        priority: -100,
    )]
    public function search(Request $request): Response
    {
        $locale = $this->locale();
        $terms = SearchTerms::fromInput($request->query->get('q'));
        $page = max(1, (int) $request->query->get('page', 1));

        $found = $this->search->find($terms, $locale, $page, self::PER_PAGE);
        $lastPage = (int) max(1, ceil($found['total'] / self::PER_PAGE));

        $body = $this->renderer->render(self::TEMPLATE, self::FALLBACK, [
            'query' => $terms->raw,
            'searchable' => $terms->isSearchable(),
            'min_word_length' => SearchTerms::MIN_WORD_LENGTH,
            'results' => array_map(
                fn ($result): array => [
                    'title' => $result->title,
                    'excerpt' => $result->excerpt,
                    'url' => $this->urlOf($result->pageId, $locale),
                ],
                $found['results'],
            ),
            'total' => $found['total'],
            'page' => min($page, $lastPage),
            'last_page' => $lastPage,
            'locale' => $locale,
            // Translated here rather than in the template, like every other
            // front-office text of the module: the Twig filter follows the
            // locale of the request, and the page follows the locale of the
            // content, which are not the same thing on a multilingual site.
            'labels' => $this->labels(),
        ]);

        $response = new Response($body);

        // A results page is a thin page that exists in as many versions as
        // there are queries, which is exactly what search engines ask sites not
        // to have indexed.
        $response->headers->set('X-Robots-Tag', 'noindex, follow');
        ContentSecurityPolicyListener::mark($request);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $keys = [
            'title' => 'Search',
            'prompt' => 'What are you looking for?',
            'submit' => 'Search',
            'too_short' => 'Use at least %count% letters.',
            'no_result' => 'Nothing matches "%query%".',
            'count' => '%count% page(s) found',
            'pages' => 'Result pages',
            'previous' => 'Previous',
            'next' => 'Next',
            'page_of' => 'Page %page% of %last%',
        ];

        return array_map(
            fn (string $message): string => $this->translator->trans($message, [], TheliaCMS::DOMAIN_NAME),
            $keys,
        );
    }

    private function locale(): string
    {
        return $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
    }

    private function urlOf(int $pageId, string $locale): string
    {
        $urls = URL::getInstance();

        if (null === $urls) {
            return '/';
        }

        try {
            $url = $urls->retrieve(TheliaCMS::PAGE_VIEW, $pageId, $locale)->toString();
        } catch (\Throwable) {
            return '/';
        }

        // The home page answers on the root only: its own address is a 301 to it.
        return $pageId === (int) TheliaCMS::getConfigValue('home_page_id', '0') ? SiteRoot::of($url) : $url;
    }
}
