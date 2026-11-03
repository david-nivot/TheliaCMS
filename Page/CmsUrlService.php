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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Locale\TranslatableText;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\TheliaCMS;

/**
 * Builds the hierarchical URL of a page and keeps `rewriting_url` in sync.
 *
 * The core generator is not reusable here: it collapses every non-word
 * character — slashes included — so `parent/child` would come out as
 * `parent-child`.
 */
final class CmsUrlService
{
    /**
     * A rewritten URL is matched before the Symfony routes (the rewriting
     * router runs at priority 1024), so a page slugged `admin/x` would shadow
     * the back office. First segments are checked against this list, both as
     * they were typed and as they come out of the slugifier — `robots.txt`
     * survives neither on its own, and a list nothing can ever match is worse
     * than no list.
     *
     * @var list<string>
     */
    private const RESERVED_PREFIXES = [
        'admin', 'api', 'assets', 'cache', 'media', 'sitemap',
        'robots.txt', 'robots-txt',
        // The icon of the site is served here, and the theme links to it from
        // every page.
        'site-icon',
        // The search results page answers on these, and the rewriting router
        // runs first: a page slugged "recherche" would shadow it.
        'recherche', 'search',
        '_profiler', 'profiler', '_wdt', 'wdt', '_fragment', 'fragment', '_error', 'error',
    ];

    public function __construct(
        private readonly PageSlugSource $slugSource = new PageSlugSource(),
    ) {
    }

    /**
     * Points the page URL for this locale at `$requestedSlug` (or at a slug
     * derived from the title), prefixed by its ancestors. The previous URL is
     * kept as a 301 by the core rewriting trait.
     */
    public function refresh(CmsPage $page, string $locale, ?string $requestedSlug = null): string
    {
        $page->setLocale($locale);

        $fixed = $this->fixedAddressOf($page, $locale);

        // A fixed address is the address, as it was typed: no ancestor prefix,
        // no slugifying. The slug is still kept, for the day it is cleared.
        if (null !== $fixed) {
            $this->assertFree($fixed, (int) $page->getId(), $locale);

            if (null !== $requestedSlug && '' !== ($segment = $this->normalizeSegment($requestedSlug))) {
                $page->setSlug($segment)->save();
            }

            $page->setRewrittenUrl($locale, $fixed);

            return $fixed;
        }

        $proposal = $this->propose((int) $page->getId(), (int) $page->getParent(), $locale, (string) $page->getTitle(), $requestedSlug);

        $page->setRewrittenUrl($locale, $proposal->address);
        $this->remember($page, $locale, $proposal->segment);

        return $proposal->address;
    }

    /**
     * The address a page would get in this locale, from a title and a slug
     * that may not be saved yet, and without writing anything: what `refresh()`
     * writes, and what the edit screen offers before the form is saved.
     *
     * `$pageId` is 0 for a page not created yet. The slug wins over the title
     * when it is given; a page whose slug and title both come out empty is
     * named after its id.
     *
     * @throws ReservedSlugException when the slug would shadow a reserved path
     */
    public function propose(int $pageId, int $parentId, string $locale, string $title, ?string $requestedSlug = null): AddressProposal
    {
        $segment = $this->normalizeSegment($requestedSlug ?? $title);

        if ('' === $segment) {
            $segment = 'page-'.$pageId;
        }

        $prefix = $this->ancestorPath($parentId, $locale);
        $base = $this->slugSource->truncate('' === $prefix ? $segment : $prefix.'/'.$segment);
        $address = $this->makeUnique($base, $pageId, $locale);

        // The segment the address really ends on, cut down with it when the
        // whole path was too long; the uniqueness suffix is left out of it.
        return new AddressProposal($this->lastSegment($base) ?? $segment, $address, $address !== $base);
    }

    /**
     * Puts the address of the page back without renaming it: the slug it
     * already answers on is reused, and only the ancestor prefix is computed
     * again.
     *
     * This is what a page needs when something around it moved — a
     * reactivation of the module, a restore from the bin, a re-parenting —
     * rather than `refresh()` with no slug, which derives the segment from the
     * title and therefore undoes every address anybody edited.
     */
    public function rebuild(CmsPage $page, string $locale): string
    {
        return $this->refresh($page, $locale, $this->slugOf($page, $locale));
    }

    /**
     * Puts back the address of a page and of every page under it, in each locale
     * they already answer in.
     *
     * This is what a move in the tree needs. The address of a page is made of
     * the addresses of its ancestors, so re-addressing the page that moved is
     * only the first of the addresses that changed: without this, a child keeps
     * announcing a path its parent no longer has, at every depth, with no
     * redirection from the old one and none towards the new one.
     *
     * The parent of a page is not a per-language column, so a move applies to
     * every language the page answers in and not only to the one being edited.
     * Only locales that already have an address take part: giving one to a
     * language nobody wrote the page in would publish an address for a
     * translation that does not exist. Which also means this is not the method
     * to call after a reactivation of the module: that path has just deleted
     * every address the module owned, so there is nothing left to read the
     * languages from, and it walks the content rows instead.
     *
     * Cost is one pass per page and per locale, parents first, each of them a
     * handful of queries. Moving a page that sits near the root of a large site
     * therefore rewrites every address below it, which is the point, and is
     * worth a transaction around the call.
     *
     * @return int the number of addresses rewritten
     */
    public function rebuildSubtree(CmsPage $page): int
    {
        $rewritten = 0;

        foreach ([$page, ...$this->descendantsOf($page)] as $node) {
            foreach ($this->addressedLocales($node) as $locale) {
                $this->rebuild($node, $locale);
                ++$rewritten;
            }
        }

        return $rewritten;
    }

    /**
     * Puts the pages under this one back under its address in one locale, the
     * ones that already answer in it.
     *
     * What a page given its first address in a locale needs: a child translated
     * before it had no parent address to sit under in that language, and answers
     * at the root until this runs. The other locales are not touched, nothing in
     * them moved.
     *
     * @return int the number of addresses rewritten
     */
    public function rebuildDescendants(CmsPage $page, string $locale): int
    {
        $rewritten = 0;

        foreach ($this->descendantsOf($page) as $node) {
            if (\in_array($locale, $this->addressedLocales($node), true)) {
                $this->rebuild($node, $locale);
                ++$rewritten;
            }
        }

        return $rewritten;
    }

    /**
     * The pages below this one, closest first.
     *
     * Read level by level rather than page by page: a descendant is addressed
     * from the address of its parent, so the parent has to have been re-addressed
     * before its children are.
     *
     * A page that has ended up under one of its own descendants is walked once
     * and not followed again. The back office cannot build such a tree, since the
     * parent choices of a page leave out its own descendants, but an import or a
     * hand-written row can, and following parents on trust never comes back.
     *
     * @return list<CmsPage>
     */
    private function descendantsOf(CmsPage $page): array
    {
        $descendants = [];
        $walked = [(int) $page->getId() => true];
        $level = [(int) $page->getId()];

        while ([] !== $level) {
            $children = CmsPageQuery::create()
                ->filterByParent($level)
                ->filterByDeletedAt(null, Criteria::ISNULL)
                ->orderById()
                ->find();

            $level = [];

            foreach ($children as $child) {
                $id = (int) $child->getId();

                if (isset($walked[$id])) {
                    continue;
                }

                $walked[$id] = true;
                $descendants[] = $child;
                $level[] = $id;
            }
        }

        return $descendants;
    }

    /**
     * The locales this page currently answers in.
     *
     * Read from the addresses themselves rather than from the languages it has
     * content in: a page can hold a draft in a language it has never been given
     * an address in, and a rebuild must put addresses back, never invent one.
     *
     * @return list<string>
     */
    private function addressedLocales(CmsPage $page): array
    {
        $locales = RewritingUrlQuery::create()
            ->filterByView($page->getRewrittenUrlViewName())
            ->filterByViewId((string) $page->getId())
            ->filterByRedirected(null, Criteria::ISNULL)
            ->select(['ViewLocale'])
            ->find()
            ->toArray();

        return array_values(array_unique(array_map(strval(...), $locales)));
    }

    /**
     * The segment of its own address the page carries in this locale, which is
     * what the edit screen shows and what a rebuild reuses.
     *
     * Falls back to reading the address for a page written before the segment
     * was stored, so a site that has not replayed the migration still edits and
     * rebuilds the addresses it has.
     */
    public function slugOf(CmsPage $page, string $locale): ?string
    {
        $stored = trim((string) $page->setLocale($locale)->getSlug());

        if ('' !== $stored) {
            return $stored;
        }

        return $this->lastSegment((string) $page->getRewrittenUrl($locale));
    }

    /**
     * Stores the segment of the page alongside its address.
     *
     * The two are written together on purpose: `rewriting_url` is a core table
     * the module clears when it is deactivated, so it cannot be the only place
     * an edited address exists. The suffix that keeps the address unique is not
     * part of the segment: the same title in two languages answers on `cookies`
     * and `cookies-2`, and a slug that kept the `-2` would bring it back on every
     * rebuild, after the address it dodged is gone.
     */
    private function remember(CmsPage $page, string $locale, string $slug): void
    {
        if ('' === $slug || $slug === $page->setLocale($locale)->getSlug()) {
            return;
        }

        $page->setLocale($locale)->setSlug($slug)->save();
    }

    private function lastSegment(string $url): ?string
    {
        $url = trim($url, '/');

        if ('' === $url) {
            return null;
        }

        $segments = explode('/', $url);

        return end($segments) ?: null;
    }

    /**
     * The address the page keeps in this locale whatever its place in the tree,
     * or null when its address is built from its ancestors and its slug.
     */
    public function fixedAddressOf(CmsPage $page, string $locale): ?string
    {
        $fixed = trim((string) $page->setLocale($locale)->getFixedUrl(), '/');

        return '' === $fixed ? null : $fixed;
    }

    /**
     * A whole address as an author typed it, kept as it is but for the slashes
     * around it: a site taken over keeps the addresses search engines know,
     * `.html` suffix and accented letters included.
     *
     * @throws FixedAddressException when the address cannot be one
     */
    public function normalizeFixedAddress(?string $raw): ?string
    {
        $address = trim(trim((string) $raw), '/');

        if ('' === $address) {
            return null;
        }

        if (1 === preg_match('#[\s?\#\\\\]|//#u', $address)) {
            throw FixedAddressException::because('"%s" is not an address: no space, "?", "#", backslash or empty segment.', $address);
        }

        if (\strlen($address) > PageSlugSource::MAX_URL_BYTES) {
            throw FixedAddressException::because('"%s" is longer than %d bytes.', $address, PageSlugSource::MAX_URL_BYTES);
        }

        $first = strtolower(explode('/', $address)[0]);

        if (\in_array($first, self::RESERVED_PREFIXES, true)) {
            throw FixedAddressException::because('"%s" is a reserved path and cannot start the address of a page.', $first);
        }

        return $address;
    }

    /**
     * A fixed address as the edit screen checks it before the form is saved:
     * normalized as on save, and refused when another object answers on it.
     * Nothing is written.
     *
     * @throws FixedAddressException when the address cannot be the page's
     */
    public function checkFixedAddress(?string $raw, int $pageId, string $locale): ?string
    {
        $address = $this->normalizeFixedAddress($raw);

        if (null !== $address) {
            $this->assertFree($address, $pageId, $locale);
        }

        return $address;
    }

    /**
     * An address another object answers on is refused; a redirection or the
     * obsolete address of a deleted item is not an owner, and goes to the page.
     *
     * @throws FixedAddressException
     */
    private function assertFree(string $address, int $pageId, string $locale): void
    {
        $existing = RewritingUrlQuery::create()->findOneByUrl($address);

        if (null === $existing || null !== $existing->getRedirected() || 'obsolete-rewritten-url' === $existing->getView()) {
            return;
        }

        if (TheliaCMS::PAGE_VIEW === $existing->getView() && (string) $pageId === (string) $existing->getViewId() && $locale === $existing->getViewLocale()) {
            return;
        }

        throw FixedAddressException::because('"%s" is already the address of %s #%s.', $address, (string) $existing->getView(), (string) $existing->getViewId());
    }

    /**
     * @throws ReservedSlugException when the slug would shadow a reserved path
     */
    public function normalizeSegment(string $raw): string
    {
        $segment = $this->slugSource->slugify($raw);

        foreach ([strtolower(trim($raw)), $segment] as $candidate) {
            if (\in_array($candidate, self::RESERVED_PREFIXES, true)) {
                throw new ReservedSlugException(TranslatableText::of('"%s" is a reserved path and cannot be used as a page slug.', $candidate));
            }
        }

        return $segment;
    }

    /**
     * The path the closest ancestor already answers on.
     *
     * Slugifying its title instead would ignore a slug edited by hand: a parent
     * answering on `/groupe` would give its children `/le-groupe/...`, so a
     * child would not live under its own parent. An ancestor with no address
     * yet in this locale — every one of them, while the addresses of the site
     * are being rebuilt — contributes its stored slug, and only a page that has
     * neither is named after its title.
     *
     * The climb stops on an ancestor it has already passed, not after a fixed
     * number of them: a site nested deeper than the limit would silently lose
     * the top of its own paths.
     */
    private function ancestorPath(int $parentId, string $locale): string
    {
        $segments = [];
        $climbed = [];

        // 0 is the root, mirroring `category.parent` / `folder.parent`.
        while ($parentId > 0 && !isset($climbed[$parentId])) {
            $climbed[$parentId] = true;
            $parent = CmsPageQuery::create()->findPk($parentId);

            if (!$parent instanceof CmsPage) {
                break;
            }

            // A fixed address is the parent's alone (`about-us.html`): its children
            // are addressed from the path it would have, its slug under its ancestors.
            $url = null === $this->fixedAddressOf($parent, $locale) ? trim((string) $parent->getRewrittenUrl($locale), '/') : '';

            if ('' !== $url) {
                // That address already carries its own ancestors.
                array_unshift($segments, $url);

                break;
            }

            $parent->setLocale($locale);
            $ownSegment = trim((string) $parent->getSlug());
            array_unshift($segments, $this->slugSource->slugify('' !== $ownSegment ? $ownSegment : (string) $parent->getTitle()));
            $parentId = (int) $parent->getParent();
        }

        return implode('/', array_filter($segments));
    }

    private function makeUnique(string $url, int $pageId, string $locale): string
    {
        $candidate = $url;
        $suffix = 1;

        while ($this->isTakenByAnotherPage($candidate, $pageId, $locale)) {
            ++$suffix;
            $candidate = $this->slugSource->truncate($url).'-'.$suffix;
        }

        return $candidate;
    }

    private function isTakenByAnotherPage(string $url, int $pageId, string $locale): bool
    {
        $existing = RewritingUrlQuery::create()->findOneByUrl($url);

        if (null === $existing) {
            return false;
        }

        return TheliaCMS::PAGE_VIEW !== $existing->getView()
            || (string) $pageId !== (string) $existing->getViewId()
            || $locale !== $existing->getViewLocale();
    }
}
