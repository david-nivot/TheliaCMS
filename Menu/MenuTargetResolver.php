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

namespace TheliaCMS\Menu;

use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\ProductQuery;
use Thelia\Tools\URL;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\TheliaCMS;

/**
 * Turns a stored menu entry into the label and the address it has in one
 * locale, or says why it has none.
 *
 * The same answer drives both sides: the front office drops entries that are
 * not usable, the menu screen shows them with the reason. A menu that silently
 * loses a link when a page is unpublished is a menu nobody trusts.
 */
final readonly class MenuTargetResolver
{
    public function __construct(
        private MenuAddress $addresses,
        private PublishedPageRepository $pages,
    ) {
    }

    public function resolve(CmsMenuItem $item, string $locale): ResolvedTarget
    {
        $item->setLocale($locale);
        $label = trim((string) $item->getLabel());
        $type = MenuTargetType::fromStorage($item->getTargetType());
        $targetId = (int) $item->getTargetId();

        return match ($type) {
            MenuTargetType::Page => $this->page($targetId, $locale, $label),
            MenuTargetType::Content => $this->content($targetId, $locale, $label),
            MenuTargetType::Folder => $this->folder($targetId, $locale, $label),
            MenuTargetType::Category => $this->category($targetId, $locale, $label),
            MenuTargetType::Product => $this->product($targetId, $locale, $label),
            MenuTargetType::Url => $this->address($item, $label),
            MenuTargetType::None => $this->heading($label),
        };
    }

    private function page(int $pageId, string $locale, string $label): ResolvedTarget
    {
        $page = CmsPageQuery::create()->findPk($pageId);

        if (!$page instanceof CmsPage || null !== $page->getDeletedAt()) {
            return new ResolvedTarget($label, null, MenuTargetIssue::TargetMissing);
        }

        $page->setLocale($locale);
        $title = trim((string) $page->getTitle());

        // Same check as the front-office renderer, so a menu never links to a
        // page that answers 404: visible, inside its publication window, and
        // published in this very locale.
        if (!$this->pages->isReachable($pageId, $locale)) {
            return new ResolvedTarget($label ?: $title, null, MenuTargetIssue::TargetOffline, $title);
        }

        $url = $this->rewrittenUrl(TheliaCMS::PAGE_VIEW, $pageId, $locale);

        // The home page is served on the root of the site and its own address
        // redirects there: linking to the root spares the redirect and lets the
        // entry be current on the home page.
        if (null !== $url && $pageId === (int) TheliaCMS::getConfigValue('home_page_id', 0)) {
            $url = SiteRoot::of($url);
        }

        return $this->linked($label, $title, $url);
    }

    private function content(int $contentId, string $locale, string $label): ResolvedTarget
    {
        $content = ContentQuery::create()->findPk($contentId);

        if (null === $content) {
            return new ResolvedTarget($label, null, MenuTargetIssue::TargetMissing);
        }

        $content->setLocale($locale);
        $title = trim((string) $content->getTitle());

        if (1 !== $content->getVisible()) {
            return new ResolvedTarget($label ?: $title, null, MenuTargetIssue::TargetOffline, $title);
        }

        return $this->linked($label, $title, $this->rewrittenUrl('content', $contentId, $locale));
    }

    private function folder(int $folderId, string $locale, string $label): ResolvedTarget
    {
        $folder = FolderQuery::create()->findPk($folderId);

        if (null === $folder) {
            return new ResolvedTarget($label, null, MenuTargetIssue::TargetMissing);
        }

        $folder->setLocale($locale);
        $title = trim((string) $folder->getTitle());

        if (1 !== $folder->getVisible()) {
            return new ResolvedTarget($label ?: $title, null, MenuTargetIssue::TargetOffline, $title);
        }

        return $this->linked($label, $title, $this->rewrittenUrl('folder', $folderId, $locale));
    }

    private function category(int $categoryId, string $locale, string $label): ResolvedTarget
    {
        $category = CategoryQuery::create()->findPk($categoryId);

        if (null === $category) {
            return new ResolvedTarget($label, null, MenuTargetIssue::TargetMissing);
        }

        $category->setLocale($locale);
        $title = trim((string) $category->getTitle());

        if (1 !== $category->getVisible()) {
            return new ResolvedTarget($label ?: $title, null, MenuTargetIssue::TargetOffline, $title);
        }

        return $this->linked($label, $title, $this->rewrittenUrl('category', $categoryId, $locale));
    }

    private function product(int $productId, string $locale, string $label): ResolvedTarget
    {
        $product = ProductQuery::create()->findPk($productId);

        if (null === $product) {
            return new ResolvedTarget($label, null, MenuTargetIssue::TargetMissing);
        }

        $product->setLocale($locale);
        $title = trim((string) $product->getTitle());

        if (1 !== $product->getVisible()) {
            return new ResolvedTarget($label ?: $title, null, MenuTargetIssue::TargetOffline, $title);
        }

        return $this->linked($label, $title, $this->rewrittenUrl('product', $productId, $locale));
    }

    private function address(CmsMenuItem $item, string $label): ResolvedTarget
    {
        $raw = trim((string) $item->getUrl());

        if ('' === $raw) {
            return new ResolvedTarget($label, null, MenuTargetIssue::AddressMissing);
        }

        $url = $this->addresses->normalize($raw);

        if (null === $url) {
            return new ResolvedTarget($label, null, MenuTargetIssue::AddressNotAllowed);
        }

        if ('' === $label) {
            return new ResolvedTarget($raw, $url, MenuTargetIssue::LabelMissing);
        }

        return new ResolvedTarget($label, $url);
    }

    private function heading(string $label): ResolvedTarget
    {
        if ('' === $label) {
            return new ResolvedTarget('', null, MenuTargetIssue::LabelMissing);
        }

        return new ResolvedTarget($label, null);
    }

    /**
     * An entry pointing at a live row: its own label wins, the target title
     * fills in, and a target with no address yet is reported rather than
     * rendered as a dead link.
     */
    private function linked(string $label, string $title, ?string $url): ResolvedTarget
    {
        $text = '' !== $label ? $label : $title;

        if (null === $url || '' === $url) {
            return new ResolvedTarget($text, null, MenuTargetIssue::TargetHasNoUrl, $title);
        }

        if ('' === $text) {
            return new ResolvedTarget('', $url, MenuTargetIssue::LabelMissing, $title);
        }

        return new ResolvedTarget($text, $url, null, $title);
    }

    /**
     * `Model::getUrl()` cannot be used here: it dereferences the URL singleton,
     * which does not exist outside an HTTP request, so a menu rendered from a
     * command would take the whole command down.
     */
    private function rewrittenUrl(string $view, int $id, string $locale): ?string
    {
        $urls = URL::getInstance();

        if (null === $urls) {
            return null;
        }

        try {
            return $urls->retrieve($view, $id, $locale)->toString();
        } catch (\Throwable) {
            return null;
        }
    }
}
