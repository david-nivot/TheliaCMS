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
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\LangQuery;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Menu\MenuPlacement;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Menu\MenuTree;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Page\Admin\CmsActivityLog;
use TheliaCMS\Security\CmsResources;

/**
 * Every write to a menu goes through here, so no caller can forget the two
 * things that must travel with a change: the cached menus have to be dropped,
 * and the change has to end up in the activity log.
 */
final readonly class CmsMenuWriter
{
    public function __construct(
        private CmsMenuRepository $menus,
        private MenuTree $tree,
        private MenuCache $cache,
        // Menus are drawn on every page, so a change to one makes every
        // cached page stale.
        private CachePurger $httpCache,
        private CmsActivityLog $activityLog,
    ) {
    }

    /**
     * The code belongs to the shared menu: renaming it renames the menus of
     * the languages detached from it, in the same transaction, so a language
     * never ends up with a menu of its own under a code nobody reads.
     *
     * @throws \DomainException when the code is already taken by another menu, or when the code of a detached menu is changed on its own
     */
    public function saveMenu(CmsMenu $menu, string $locale, string $code, string $title): void
    {
        $previousCode = $menu->isNew() ? null : (string) $menu->getCode();
        $shared = CmsMenuQuery::SHARED === (string) $menu->getMenuLocale();

        if ($previousCode !== $code) {
            if (null !== $previousCode && !$shared) {
                throw new \DomainException('The code of a menu is changed on its shared version, for every language at once.');
            }

            if (CmsMenuQuery::create()->filterByCode($code)->exists()) {
                throw new \DomainException('Another menu already uses this code.');
            }
        }

        $wasNew = $menu->isNew();

        $this->transactional(static function (ConnectionInterface $connection) use ($menu, $locale, $code, $title, $previousCode, $shared): void {
            if (null !== $previousCode && $previousCode !== $code && $shared) {
                foreach (self::detachedRows($previousCode, $connection) as $detached) {
                    $detached->setCode($code)->save($connection);
                }
            }

            $menu->setCode($code);
            $menu->setLocale($locale)->setTitle($title);
            $menu->save($connection);
        });

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->log($wasNew ? 'CREATE' : 'UPDATE', $menu, \sprintf('CMS menu "%s" saved', $code));
    }

    /**
     * Deleting the shared menu deletes the menus of the languages detached
     * from it: they share its code, and would otherwise stay behind with no
     * screen listing them.
     */
    public function deleteMenu(CmsMenu $menu): void
    {
        $code = (string) $menu->getCode();
        $id = (int) $menu->getId();
        $shared = CmsMenuQuery::SHARED === (string) $menu->getMenuLocale();

        // Entries go with it through the database cascade: they have no meaning
        // outside their menu, and nothing else points at them.
        $this->transactional(static function (ConnectionInterface $connection) use ($menu, $code, $shared): void {
            if ($shared) {
                foreach (self::detachedRows($code, $connection) as $detached) {
                    $detached->delete($connection);
                }
            }

            $menu->delete($connection);
        });

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->activityLog->record('DELETE', $id, \sprintf('CMS menu "%s" deleted', $code), CmsResources::MENU);
    }

    /**
     * Gives a language a menu of its own, starting as a copy of the shared
     * one: its titles, its entries, their labels in every language.
     *
     * @throws \DomainException when the language is not an active one, already has its own menu, or the code has no shared menu
     */
    public function detach(string $code, string $locale): CmsMenu
    {
        if (CmsMenuQuery::SHARED === $locale) {
            throw new \DomainException('Pick the language to give a menu of its own.');
        }

        if (!LangQuery::create()->filterByLocale($locale)->filterByActive(1)->exists()) {
            throw new \DomainException('This language is not active on the shop.');
        }

        $shared = CmsMenuQuery::findShared($code) ?? throw new \DomainException('There is no menu with this code.');

        $detached = (new CmsMenu())->setCode($code)->setMenuLocale($locale);

        $this->transactional(function (ConnectionInterface $connection) use ($shared, $detached, $code, $locale): void {
            // Inside the transaction, and backed by the unique index: two
            // administrators detaching the same language at once get the
            // message, not an error page.
            if (null !== CmsMenuQuery::findForLocale($code, $locale, $connection)) {
                throw new \DomainException('This language already has a menu of its own.');
            }

            foreach ($shared->getCmsMenuI18ns(null, $connection) as $translation) {
                $detached->setLocale((string) $translation->getLocale())->setTitle($translation->getTitle());
            }

            try {
                $detached->save($connection);
            } catch (\Throwable $throwable) {
                throw UniqueViolation::caused($throwable) ? new \DomainException('This language already has a menu of its own.', 0, $throwable) : $throwable;
            }

            $this->copyEntries($shared, $detached, $connection);
        });

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->log('CREATE', $detached, \sprintf('CMS menu "%s": %s detached from the shared menu', $code, $locale));

        return $detached;
    }

    /**
     * Replaces the entries of a menu by a copy of the entries of another menu
     * of the same code: the shared one on a language, a language on another,
     * or a language on the shared one. Replaced, never merged: merging two
     * trees by position gives an order nobody asked for. The titles of the
     * target stay.
     *
     * @throws \DomainException when source and target are the same, or either does not exist
     */
    public function realign(string $code, string $targetLocale, string $sourceLocale): CmsMenu
    {
        if ($targetLocale === $sourceLocale) {
            throw new \DomainException('A menu cannot be realigned on itself.');
        }

        $target = CmsMenuQuery::findForLocale($code, $targetLocale) ?? throw new \DomainException(CmsMenuQuery::SHARED === $targetLocale ? 'There is no menu with this code.' : 'This language has no menu of its own: detach it first.');
        $source = CmsMenuQuery::findForLocale($code, $sourceLocale) ?? throw new \DomainException('The menu to copy from does not exist.');

        $this->transactional(function (ConnectionInterface $connection) use ($source, $target): void {
            CmsMenuItemQuery::create()->filterByMenuId($target->getId())->delete($connection);
            $this->copyEntries($source, $target, $connection);
        });

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->log('UPDATE', $target, \sprintf(
            'CMS menu "%s": %s realigned on %s',
            $code,
            '' === $targetLocale ? 'shared menu' : $targetLocale,
            '' === $sourceLocale ? 'shared menu' : $sourceLocale,
        ));

        return $target;
    }

    /**
     * Sends a language back to the shared menu: its own menu and its
     * entries are deleted.
     *
     * @throws \DomainException when the language has no menu of its own
     */
    public function reattach(string $code, string $locale): void
    {
        if (CmsMenuQuery::SHARED === $locale) {
            throw new \DomainException('The shared menu cannot be sent back to itself.');
        }

        $detached = CmsMenuQuery::findForLocale($code, $locale) ?? throw new \DomainException('This language already reads the shared menu.');
        $id = (int) $detached->getId();

        $this->transactional(static function (ConnectionInterface $connection) use ($detached): void {
            // The entries go through the cascade.
            $detached->delete($connection);
        });

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->activityLog->record('DELETE', $id, \sprintf('CMS menu "%s": %s back to the shared menu', $code, $locale), CmsResources::MENU);
    }

    /**
     * @throws \DomainException when the entry cannot sit where it is asked to
     */
    public function saveItem(CmsMenu $menu, ?CmsMenuItem $item, string $locale, MenuItemData $data): CmsMenuItem
    {
        $placements = $this->menus->placements((int) $menu->getId());
        $isNew = null === $item;
        $parent = $data->parent;

        if (MenuTree::ROOT !== $parent) {
            $fits = $isNew
                ? $this->tree->depthOf($placements, $parent) < MenuTree::MAX_DEPTH
                : $this->tree->canNest($placements, (int) $item->getId(), $parent);

            if (!$fits) {
                throw new \DomainException('A menu is three levels deep at most, and an entry cannot be filed under itself.');
            }
        }

        $item ??= (new CmsMenuItem())->setMenuId($menu->getId());

        $moved = (int) $item->getParent() !== $parent;

        $item->setParent($parent)
            ->setTargetType($data->type->value)
            ->setTargetId($data->type->needsTargetId() ? $data->targetId : null)
            ->setUrl(MenuTargetType::Url === $data->type ? $data->url : null)
            ->setOpenNewTab($data->openNewTab ? 1 : 0);

        if ($isNew || $moved) {
            $item->setPosition($this->nextPosition((int) $menu->getId(), $parent));
        }

        $item->setLocale($locale)->setLabel($data->label);
        $item->save();

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->log($isNew ? 'CREATE' : 'UPDATE', $menu, \sprintf('CMS menu "%s": entry #%d saved', $menu->getCode(), $item->getId()));

        return $item;
    }

    /**
     * Removes the entry and everything under it.
     *
     * The cascade is deliberate: menu entries have no bin, and leaving orphans
     * behind would make them reappear at the top level of the menu — visible on
     * the site, in a place nobody put them.
     */
    public function deleteItem(CmsMenu $menu, CmsMenuItem $item): void
    {
        $placements = $this->menus->placements((int) $menu->getId());
        $ids = [(int) $item->getId(), ...$this->tree->descendants($placements, (int) $item->getId())];

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            CmsMenuItemQuery::create()
                ->filterByMenuId($menu->getId())
                ->filterById($ids, Criteria::IN)
                ->delete($connection);

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->log('DELETE', $menu, \sprintf('CMS menu "%s": entries removed (%s)', $menu->getCode(), implode(', ', $ids)));
    }

    /**
     * Moves the entry one slot up or down among the entries it shares a parent
     * with.
     */
    public function move(CmsMenu $menu, CmsMenuItem $item, int $direction): void
    {
        $placements = $this->menus->placements((int) $menu->getId());
        $siblings = $this->tree->children($placements, (int) $item->getParent());
        $offset = array_search((int) $item->getId(), $siblings, true);

        if (!\is_int($offset)) {
            return;
        }

        $target = $offset + ($direction < 0 ? -1 : 1);

        if ($target < 0 || $target >= \count($siblings)) {
            return;
        }

        [$siblings[$offset], $siblings[$target]] = [$siblings[$target], $siblings[$offset]];

        $this->reorder((int) $menu->getId(), $siblings);

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->log('UPDATE', $menu, \sprintf('CMS menu "%s": entry #%d moved', $menu->getCode(), $item->getId()));
    }

    /**
     * Files the entry under `$parent` at `$position` — what dragging a row in
     * the editor amounts to.
     *
     * @throws \DomainException when the entry cannot sit there
     */
    public function place(CmsMenu $menu, CmsMenuItem $item, int $parent, int $position): void
    {
        $placements = $this->menus->placements((int) $menu->getId());
        $id = (int) $item->getId();

        if (!$this->tree->canNest($placements, $id, $parent)) {
            throw new \DomainException('A menu is three levels deep at most, and an entry cannot be filed under itself.');
        }

        $item->setParent($parent)->save();

        // Recomputed from the tree *after* the move, so the entry is inserted
        // among its new siblings rather than appended.
        $siblings = $this->tree->children($this->menus->placements((int) $menu->getId()), $parent);
        $siblings = array_values(array_filter($siblings, static fn (int $sibling): bool => $sibling !== $id));
        array_splice($siblings, max(0, min($position - 1, \count($siblings))), 0, [$id]);

        $this->reorder((int) $menu->getId(), $siblings);

        $this->cache->invalidate();
        $this->httpCache->purge([CacheTags::MENUS]);
        $this->log('UPDATE', $menu, \sprintf('CMS menu "%s": entry #%d moved', $menu->getCode(), $id));
    }

    /**
     * @param list<int> $ids
     */
    private function reorder(int $menuId, array $ids): void
    {
        $items = CmsMenuItemQuery::create()
            ->filterByMenuId($menuId)
            ->filterById($ids, Criteria::IN)
            ->find();

        foreach ($items as $item) {
            $position = array_search((int) $item->getId(), $ids, true);

            if (\is_int($position)) {
                $item->setPosition($position + 1)->save();
            }
        }
    }

    private function nextPosition(int $menuId, int $parent): int
    {
        $last = CmsMenuItemQuery::create()
            ->filterByMenuId($menuId)
            ->filterByParent($parent)
            ->orderByPosition(Criteria::DESC)
            ->findOne();

        return null === $last ? 1 : (int) $last->getPosition() + 1;
    }

    /**
     * Copies every entry of a menu into another, with its labels in every
     * language, then points the copies at each other: `parent` holds the id
     * of an entry, and the copies have new ones.
     */
    private function copyEntries(CmsMenu $source, CmsMenu $target, ConnectionInterface $connection): void
    {
        $placements = [];
        $copies = [];
        $newIds = [];

        foreach (CmsMenuItemQuery::create()->filterByMenuId($source->getId())->orderById()->find($connection) as $item) {
            $copy = (new CmsMenuItem())
                ->setMenuId($target->getId())
                ->setParent(MenuTree::ROOT)
                ->setPosition((int) $item->getPosition())
                ->setTargetType((string) $item->getTargetType())
                ->setTargetId($item->getTargetId())
                ->setUrl($item->getUrl())
                ->setOpenNewTab((int) $item->getOpenNewTab());

            foreach ($item->getCmsMenuItemI18ns(null, $connection) as $translation) {
                $copy->setLocale((string) $translation->getLocale())->setLabel($translation->getLabel());
            }

            $copy->save($connection);

            $placements[] = new MenuPlacement((int) $item->getId(), (int) $item->getParent(), (int) $item->getPosition());
            $newIds[(int) $item->getId()] = (int) $copy->getId();
            $copies[(int) $copy->getId()] = $copy;
        }

        foreach ($this->tree->remappedParents($placements, $newIds) as $id => $parent) {
            if (MenuTree::ROOT !== $parent) {
                $copies[$id]->setParent($parent)->save($connection);
            }
        }
    }

    /**
     * @return iterable<CmsMenu>
     */
    private static function detachedRows(string $code, ConnectionInterface $connection): iterable
    {
        return CmsMenuQuery::create()
            ->filterByCode($code)
            ->filterByMenuLocale(CmsMenuQuery::SHARED, Criteria::NOT_EQUAL)
            ->find($connection);
    }

    /**
     * @param callable(ConnectionInterface): void $work
     */
    private function transactional(callable $work): void
    {
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $work($connection);
            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }
    }

    private function log(string $action, CmsMenu $menu, string $message): void
    {
        $this->activityLog->record($action, (int) $menu->getId(), $message, CmsResources::MENU);
    }
}
