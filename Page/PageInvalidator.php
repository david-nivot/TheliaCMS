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

namespace TheliaCMS\Page;

use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Menu\Admin\CmsMenuRepository;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Page\Admin\CmsPageAdminRepository;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Partial\PartialCache;
use TheliaCMS\Sitemap\SitemapCache;

/**
 * Drops what shows a page once it changed: the page itself in a shared cache,
 * the lists of pages, and what other pages show of it.
 *
 * Every write to a page lands here, so how far a change reaches is decided in
 * one place. Too wide is as wrong as too narrow: the menus are drawn on every
 * page of the site, so dropping them for a page no menu links to empties the
 * whole shared cache, the shop included, on every publication.
 */
final readonly class PageInvalidator
{
    public function __construct(
        private MenuCache $menuCache,
        private PartialCache $partials,
        private CachePurger $httpCache,
        private CmsMenuRepository $menus,
        private CmsPageAdminRepository $tree,
        private SitemapCache $sitemap,
        private ScheduledChanges $scheduledChanges,
    ) {
    }

    /**
     * Something only the pages themselves and the lists of pages show changed:
     * their content, their fields, their settings.
     */
    public function contentChanged(int ...$pageIds): void
    {
        $this->listsChanged();
        $this->httpCache->purge(array_map(CacheTags::page(...), array_values(array_unique($pageIds))));
    }

    /**
     * What a link to the pages shows changed: their title, their address,
     * whether they are online. The pages under them name them in their
     * breadcrumb, and the menus follow when one of them links to them.
     */
    public function presenceChanged(int ...$pageIds): void
    {
        $pageIds = array_values(array_unique($pageIds));
        $shown = $pageIds;

        foreach ($pageIds as $pageId) {
            array_push($shown, ...array_values($this->tree->descendantIds($pageId)));
        }

        $tags = array_map(CacheTags::page(...), array_values(array_unique($shown)));

        if ($this->menus->linksTo(MenuTargetType::Page, $pageIds)) {
            $this->menuCache->invalidate();
            $tags[] = CacheTags::MENUS;
        }

        $this->listsChanged();
        $this->httpCache->purge($tags);
    }

    /**
     * Only the order or the content of the lists changed, such as the position
     * of a page among its siblings. The sitemap is a list of pages too. The
     * dates a page goes online or offline are read again: a write may have
     * moved them.
     */
    public function listsChanged(): void
    {
        $this->partials->invalidate(PageListPartial::NAME);
        $this->sitemap->invalidate();
        $this->scheduledChanges->forget();
    }
}
