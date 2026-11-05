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

namespace TheliaCMS\Menu\Admin;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Menu\MenuPlacement;
use TheliaCMS\Menu\MenuTargetResolver;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Menu\MenuTree;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;

/**
 * Reads menus for the back office.
 *
 * Unlike the front-office provider, this one keeps the entries whose target is
 * broken and says what is wrong with them: they are invisible to visitors, so
 * the menu screen is the only place they can be noticed and fixed.
 */
final readonly class CmsMenuRepository
{
    public function __construct(
        private MenuTargetResolver $targets,
        private MenuTree $tree,
    ) {
    }

    /**
     * The shared menus, each once, with the languages that have a menu of
     * their own under the same code.
     *
     * @return list<array{menu: CmsMenu, entries: int, issues: int, locales: list<string>}>
     */
    public function menus(string $locale): array
    {
        $menus = CmsMenuQuery::create()
            ->filterByShared()
            ->joinWithI18n($locale, Criteria::LEFT_JOIN)
            ->orderByCode()
            ->find();

        $detached = [];

        foreach (CmsMenuQuery::create()->filterByMenuLocale(CmsMenuQuery::SHARED, Criteria::NOT_EQUAL)->orderByMenuLocale()->find() as $row) {
            $detached[(string) $row->getCode()][] = (string) $row->getMenuLocale();
        }

        $summaries = [];

        foreach ($menus as $menu) {
            // What the language being edited reads: its own menu if it has one.
            $rows = $this->rows(CmsMenuQuery::findForLocale((string) $menu->getCode(), $locale) ?? $menu, $locale);

            $summaries[] = [
                'menu' => $menu,
                'entries' => \count($rows),
                'issues' => \count(array_filter($rows, static fn (MenuItemRow $row): bool => !$row->target->isUsable())),
                'locales' => $detached[(string) $menu->getCode()] ?? [],
            ];
        }

        return $summaries;
    }

    /**
     * The shared menus, one per code, by code.
     *
     * @return list<CmsMenu>
     */
    public function sharedMenus(): array
    {
        return iterator_to_array(CmsMenuQuery::create()->filterByShared()->orderByCode()->find(), false);
    }

    public function find(int $id): ?CmsMenu
    {
        return CmsMenuQuery::create()->findPk($id);
    }

    /**
     * The shared menu of the code.
     */
    public function findByCode(string $code): ?CmsMenu
    {
        return CmsMenuQuery::findShared($code);
    }

    /**
     * The row stored for exactly this locale ('' for the shared menu),
     * without falling back to the shared one.
     */
    public function findForLocale(string $code, string $locale): ?CmsMenu
    {
        return CmsMenuQuery::findForLocale($code, $locale);
    }

    /**
     * The languages that have a menu of their own under this code.
     *
     * @return list<string>
     */
    public function detachedLocales(string $code): array
    {
        return array_values(array_map(
            'strval',
            CmsMenuQuery::create()
                ->filterByCode($code)
                ->filterByMenuLocale(CmsMenuQuery::SHARED, Criteria::NOT_EQUAL)
                ->orderByMenuLocale()
                ->select(['MenuLocale'])
                ->find()
                ->toArray(),
        ));
    }

    /**
     * The active languages by locale, to name them and show their flag.
     *
     * @return array<string, Lang>
     */
    public function languages(): array
    {
        $languages = [];

        foreach (LangQuery::create()->filterByActive(1)->orderByPosition()->find() as $lang) {
            $languages[(string) $lang->getLocale()] = $lang;
        }

        return $languages;
    }

    /**
     * The menus of a code a menu can be realigned on: every one but itself, the
     * shared menu first ('' as locale).
     *
     * @return list<array{locale: string, entries: int}>
     */
    public function realignSources(string $code, string $targetLocale): array
    {
        $sources = [];

        foreach ([CmsMenuQuery::SHARED, ...$this->detachedLocales($code)] as $locale) {
            $menu = CmsMenuQuery::findForLocale($code, $locale);

            if ($locale !== $targetLocale && null !== $menu) {
                $sources[] = ['locale' => $locale, 'entries' => $this->entryCount($menu)];
            }
        }

        return $sources;
    }

    public function entryCount(CmsMenu $menu): int
    {
        return CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count();
    }

    /**
     * What realigning a menu on another one would do, for the confirmation:
     * the entries of the target that go, and the entries of the source that
     * take their place.
     *
     * @return array{target: int, source: int}
     *
     * @throws \DomainException when either menu does not exist
     */
    public function realignCounts(string $code, string $targetLocale, string $sourceLocale): array
    {
        $target = CmsMenuQuery::findForLocale($code, $targetLocale);
        $source = CmsMenuQuery::findForLocale($code, $sourceLocale);

        if (null === $target || null === $source) {
            throw new \DomainException('There is no such menu to realign.');
        }

        return ['target' => $this->entryCount($target), 'source' => $this->entryCount($source)];
    }

    /**
     * The whole menu, depth-first, ready to be indented.
     *
     * @return list<MenuItemRow>
     */
    public function rows(CmsMenu $menu, string $locale): array
    {
        $items = $this->items((int) $menu->getId());
        $placements = $this->placementsOf($items);
        $rows = [];

        foreach ($this->tree->flatten($placements) as $flat) {
            $item = $items[$flat['id']] ?? null;

            if (null === $item) {
                continue;
            }

            $siblings = $this->tree->children($placements, $this->shownParent($placements, $flat['id']));
            $offset = array_search($flat['id'], $siblings, true);

            $rows[] = new MenuItemRow(
                item: $item,
                depth: $flat['depth'],
                type: MenuTargetType::fromStorage($item->getTargetType()),
                target: $this->targets->resolve($item, $locale),
                canMoveUp: \is_int($offset) && $offset > 0,
                canMoveDown: \is_int($offset) && $offset < \count($siblings) - 1,
            );
        }

        return $rows;
    }

    public function findItem(CmsMenu $menu, int $itemId): ?CmsMenuItem
    {
        return CmsMenuItemQuery::create()
            ->filterByMenuId($menu->getId())
            ->filterById($itemId)
            ->findOne();
    }

    /**
     * Entries this one may be filed under: not itself, not its own descendants,
     * and nothing that would push its subtree past the third level.
     *
     * @return array<string, int>
     */
    public function parentChoices(CmsMenu $menu, string $locale, ?int $itemId = null): array
    {
        $items = $this->items((int) $menu->getId());
        $placements = $this->placementsOf($items);
        $choices = [];

        foreach ($this->tree->flatten($placements) as $flat) {
            $item = $items[$flat['id']] ?? null;

            if (null === $item) {
                continue;
            }

            $allowed = null === $itemId
                ? $flat['depth'] < MenuTree::MAX_DEPTH
                : $this->tree->canNest($placements, $itemId, $flat['id']);

            if (!$allowed) {
                continue;
            }

            $label = $this->targets->resolve($item, $locale)->label;
            $choices[str_repeat('— ', $flat['depth'] - 1).('' === $label ? '#'.$flat['id'] : $label)] = $flat['id'];
        }

        return $choices;
    }

    /**
     * @return list<MenuPlacement>
     */
    public function placements(int $menuId): array
    {
        return $this->placementsOf($this->items($menuId));
    }

    /**
     * Whether an entry of any menu points at one of these rows, usable or
     * not: an entry hidden because its target is offline shows again once the
     * target is back. Null asks about any row of that kind.
     *
     * @param list<int>|null $ids
     */
    public function linksTo(MenuTargetType $type, ?array $ids = null): bool
    {
        if ([] === $ids) {
            return false;
        }

        $query = CmsMenuItemQuery::create()->filterByTargetType($type->value);

        if (null !== $ids) {
            $query->filterByTargetId($ids, Criteria::IN);
        }

        return $query->exists();
    }

    /**
     * @return array<int, CmsMenuItem>
     */
    private function items(int $menuId): array
    {
        $items = [];

        foreach (CmsMenuItemQuery::create()->filterByMenuId($menuId)->orderByPosition()->find() as $item) {
            $items[(int) $item->getId()] = $item;
        }

        return $items;
    }

    /**
     * @param array<int, CmsMenuItem> $items
     *
     * @return list<MenuPlacement>
     */
    private function placementsOf(array $items): array
    {
        return array_values(array_map(
            static fn (CmsMenuItem $item): MenuPlacement => new MenuPlacement(
                (int) $item->getId(),
                (int) $item->getParent(),
                (int) $item->getPosition(),
            ),
            $items
        ));
    }

    /**
     * @param list<MenuPlacement> $placements
     */
    private function shownParent(array $placements, int $id): int
    {
        foreach ($placements as $placement) {
            if ($placement->id !== $id) {
                continue;
            }

            foreach ($placements as $candidate) {
                if ($candidate->id === $placement->parent) {
                    return $placement->parent;
                }
            }

            return MenuTree::ROOT;
        }

        return MenuTree::ROOT;
    }
}
