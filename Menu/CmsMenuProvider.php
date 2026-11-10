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

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;

/**
 * The menu a theme renders: a tree of labels and addresses, nothing else.
 *
 * Entries whose target is gone, offline or unpublished in this locale are left
 * out — a visitor must never be offered a link that answers 404. An entry that
 * cannot be linked but still has children and a label survives as a heading,
 * because dropping it would quietly reshape the menu around it.
 *
 * A menu that is not visible renders nothing, and an entry that is not visible
 * is left out with everything under it. That is decided here, where the menu
 * is read for the site, so no theme has to check it.
 */
final readonly class CmsMenuProvider
{
    public function __construct(
        private MenuCache $cache,
        private MenuTargetResolver $targets,
        private MenuTree $tree,
        private LangService $langService,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<array{id: int, label: string, url: string|null, blank: bool, source: string, source_id: int|null, children: list<array<string, mixed>>, active: bool, in_trail: bool}>
     */
    public function menu(string $code, ?string $locale = null): array
    {
        $locale ??= $this->currentLocale();
        $request = $this->requestStack->getMainRequest();

        $nodes = $this->cache->get(
            $code,
            $locale,
            $request?->getHost() ?? 'cli',
            fn (): array => $this->build($code, $locale),
        );

        // Left out of the cache on purpose: it depends on the page being served,
        // not on the menu.
        return $this->markCurrent($nodes, $request?->getPathInfo() ?? '', $request?->query->all() ?? [], $request?->getHost());
    }

    /**
     * Whether the menu read in this language stores at least one entry,
     * whether or not any of them resolves in that language.
     *
     * A menu somebody filled in is a merchant taking that part of the site
     * over, even on the day all of its entries are offline: falling back to
     * something else then would show links nobody chose. The menu asked about
     * is the one the language reads: its own when it was detached, even
     * emptied, the shared one otherwise.
     *
     * A menu hidden on purpose counts as filled, even with no entry: hiding it
     * is the merchant taking that part of the site over too.
     */
    public function hasEntries(string $code, ?string $locale = null): bool
    {
        $locale ??= $this->currentLocale();

        return $this->cache->storedEntryCount($code, $locale, static function () use ($code, $locale): int {
            $menu = CmsMenuQuery::findServed($code, $locale);

            if (null === $menu) {
                return 0;
            }

            $stored = CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count();

            return 1 === (int) $menu->getVisible() ? $stored : max(1, $stored);
        }) > 0;
    }

    /**
     * A language with a menu of its own reads it, even empty: emptying it is
     * what the merchant chose for that language, the shared menu does not
     * show through.
     *
     * @return list<array<string, mixed>>
     */
    private function build(string $code, string $locale): array
    {
        $menu = CmsMenuQuery::findServed($code, $locale);

        if (null === $menu || 1 !== (int) $menu->getVisible()) {
            return [];
        }

        $items = iterator_to_array(
            CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->orderByPosition()->find(),
            false
        );

        /** @var array<int, CmsMenuItem> $byId */
        $byId = [];
        $placements = [];

        foreach ($items as $item) {
            $byId[(int) $item->getId()] = $item;
            $placements[] = new MenuPlacement((int) $item->getId(), (int) $item->getParent(), (int) $item->getPosition());
        }

        return $this->branch($placements, $byId, MenuTree::ROOT, $locale);
    }

    /**
     * @param list<MenuPlacement>     $placements
     * @param array<int, CmsMenuItem> $byId
     *
     * @return list<array<string, mixed>>
     */
    private function branch(array $placements, array $byId, int $parent, string $locale): array
    {
        $nodes = [];

        foreach ($this->tree->children($placements, $parent) as $id) {
            $item = $byId[$id] ?? null;

            // Before the children are read: they go with it.
            if (null === $item || 1 !== (int) $item->getVisible()) {
                continue;
            }

            $children = $this->branch($placements, $byId, $id, $locale);
            $target = $this->targets->resolve($item, $locale);

            if (!$target->isUsable()) {
                // Not linkable, but it still holds up a branch: keep the label
                // as a heading rather than promote its children a level up.
                if ('' === $target->label || [] === $children) {
                    continue;
                }

                $nodes[] = $this->node($item, $target->label, null, false, $children);

                continue;
            }

            $nodes[] = $this->node($item, $target->label, $target->url, 1 === $item->getOpenNewTab(), $children);
        }

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function node(CmsMenuItem $item, string $label, ?string $url, bool $blank, array $children): array
    {
        $type = MenuTargetType::fromStorage($item->getTargetType());

        return [
            'id' => (int) $item->getId(),
            'label' => $label,
            'url' => $url,
            'blank' => $blank,
            // What the entry points at, for a reader that has to tell a folder
            // from a page: the content slots of the core say it that way.
            'source' => $type->slotSource(),
            'source_id' => match (true) {
                $type->needsTargetId() => (int) $item->getTargetId(),
                MenuTargetType::None === $type => (int) $item->getId(),
                default => null,
            },
            'children' => $children,
            'active' => false,
            'in_trail' => false,
        ];
    }

    /**
     * Flags the entry pointing at the page being served, and its ancestors, so a
     * theme can highlight the current section without comparing URLs itself.
     *
     * @param list<array<string, mixed>> $nodes
     * @param array<string, mixed>       $currentQuery
     *
     * @return list<array<string, mixed>>
     */
    private function markCurrent(array $nodes, string $currentPath, array $currentQuery = [], ?string $currentHost = null): array
    {
        foreach ($nodes as $index => $node) {
            /** @var list<array<string, mixed>> $children */
            $children = $node['children'];
            $children = $this->markCurrent($children, $currentPath, $currentQuery, $currentHost);

            $nodes[$index]['children'] = $children;
            $nodes[$index]['active'] = CurrentEntry::matches($node['url'], $currentPath, $currentQuery, $currentHost);
            $nodes[$index]['in_trail'] = $nodes[$index]['active'] || [] !== array_filter(
                $children,
                static fn (array $child): bool => (bool) $child['in_trail'],
            );
        }

        return $nodes;
    }

    private function currentLocale(): string
    {
        return $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
    }
}
