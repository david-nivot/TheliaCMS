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

namespace TheliaCMS\Seo;

use SEOne\Service\SeoDefaultModels\SeoElementInterface;
use SEOne\Service\SeoDefaultModels\SEOneMicroDataTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;
use TheliaCMS\Menu\SiteRoot;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\TheliaCMS;

/**
 * Teaches SEOne about the `cmspage` view.
 *
 * Registered from TheliaCMS::configureServices() only when SEOne is installed:
 * the class implements one of its interfaces, so autodiscovery would fatal on a
 * site running without it.
 */
readonly class CmsPageSeoElement implements SeoElementInterface
{
    use SEOneMicroDataTrait;

    public function __construct(
        LangService $langService,
        EventDispatcherInterface $eventDispatcher,
        private PublishedPageRepository $publishedPages,
    ) {
        $this->setDependencies(langService: $langService, dispatcher: $eventDispatcher);
    }

    public function supports(string $view): bool
    {
        return $view === $this->getView();
    }

    public function getIdentifier(): string
    {
        return TheliaCMS::PAGE_VIEW.'_id';
    }

    public function getView(): string
    {
        return TheliaCMS::PAGE_VIEW;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function getSeoPageTitle($id): string
    {
        $page = $this->findLocalizedPage($id);

        return $this->firstNonEmpty(
            $page?->getMetaTitle(),
            $page?->getTitle(),
            ConfigQuery::read('store_name'),
        );
    }

    public function getSeoPageDesc($id): string
    {
        $page = $this->findLocalizedPage($id);

        return $this->firstNonEmpty(
            $page?->getMetaDescription(),
            ConfigQuery::read('store_description'),
        );
    }

    public function getSeoPageH1($id, string $type): string
    {
        $page = $this->findLocalizedPage($id);

        return $this->firstNonEmpty($page?->getTitle(), ConfigQuery::read('store_name'));
    }

    public function getSeoMicroData($id, string $type, array $params = []): string
    {
        $page = $this->findLocalizedPage($id);

        // Nothing to describe, and nothing handed on: SEOne builds its event
        // with whatever it is given and its constructor refuses null, so an
        // unknown page id would turn the 404 page into a 500.
        if (!$page instanceof CmsPage) {
            return '';
        }

        return $this->getScriptsTag([
            '@context' => 'https://schema.org/',
            '@type' => 'WebPage',
            'url' => $this->pageUrl($page),
            'name' => $page->getTitle(),
            'description' => $page->getMetaDescription(),
        ], $type, $id);
    }

    /**
     * Ancestors first, the page itself last — the CMS tree *is* the breadcrumb.
     *
     * An ancestor a visitor cannot open is left out. A page under a draft
     * parent is a normal situation while a section is being written, and
     * linking to that parent would hand a 404 both to the reader and to the
     * BreadcrumbList a search engine reads.
     *
     * The home page is left out too: SEOne and the themes start every trail at
     * the root of the site, which is where the home page answers. Its own trail
     * is therefore empty.
     */
    public function getSeoBreadcrumb($id): array
    {
        $page = $this->findLocalizedPage($id);

        if (!$page instanceof CmsPage || $this->isHome($page)) {
            return [];
        }

        $breadcrumb = [$this->crumb($page)];
        $ancestor = $this->findLocalizedPage($page->getParent());
        // Stops on an ancestor already listed rather than after a fixed number
        // of them: a page nested deeper would lose the top of its own trail.
        $climbed = [(int) $page->getId() => true];

        while ($ancestor instanceof CmsPage && !isset($climbed[(int) $ancestor->getId()])) {
            $climbed[(int) $ancestor->getId()] = true;

            if (!$this->isHome($ancestor) && $this->publishedPages->isReachable($ancestor->getId(), $this->langService->getLocale())) {
                array_unshift($breadcrumb, $this->crumb($ancestor));
            }

            $ancestor = $this->findLocalizedPage($ancestor->getParent());
        }

        return $breadcrumb;
    }

    /** @return array{url: string, title: string} */
    private function crumb(CmsPage $page): array
    {
        return [
            'url' => $this->pageUrl($page),
            'title' => (string) $page->getTitle(),
        ];
    }

    private function findLocalizedPage(int|string|null $id): ?CmsPage
    {
        if (null === $id || '' === $id || 0 === (int) $id) {
            return null;
        }

        $page = CmsPageQuery::create()->findPk((int) $id);

        return $page?->setLocale($this->langService->getLocale());
    }

    private function pageUrl(CmsPage $page): string
    {
        $url = URL::getInstance()?->retrieve(TheliaCMS::PAGE_VIEW, $page->getId(), $this->langService->getLocale())->toString() ?? '';

        // The home page answers on the root only: its own address is a 301 to it.
        return '' !== $url && $this->isHome($page) ? SiteRoot::of($url) : $url;
    }

    private function isHome(CmsPage $page): bool
    {
        return (int) $page->getId() === (int) TheliaCMS::getConfigValue('home_page_id', '0');
    }

    private function firstNonEmpty(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (null !== $candidate && '' !== trim($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
