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

namespace TheliaCMS\ImportExport\Step;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductQuery;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\DealerStores;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;

/**
 * The menus of a file, with their entries.
 */
final readonly class MenusStep
{
    /**
     * @param array<string, int> $pageIds
     */
    public function import(
        SiteDocument $document,
        ImportOptions $options,
        ImportReport $report,
        array $pageIds,
        ConnectionInterface $connection,
    ): void {
        $menus = $document->menus();
        // The shared menus first, whatever order the file is in: the menu of
        // a language is only taken in beside the shared menu of its code.
        usort($menus, static fn (array $a, array $b): int => (CmsMenuQuery::SHARED === self::menuLocale($b)) <=> (CmsMenuQuery::SHARED === self::menuLocale($a)));

        foreach ($menus as $menu) {
            $code = trim((string) ($menu['code'] ?? ''));

            if ('' === $code) {
                $report->skipped('menus');

                continue;
            }

            $menuLocale = self::menuLocale($menu);

            if (CmsMenuQuery::SHARED !== $menuLocale) {
                if (!LangQuery::create()->filterByLocale($menuLocale)->exists($connection)) {
                    $report->warn(\sprintf('The menu "%s" of the language %s was left out: this site has no such language.', $code, $menuLocale));
                    $report->skipped('menus');

                    continue;
                }

                // Nothing on this site would list it, and nothing would remove
                // it with the menu of its code.
                if (null === CmsMenuQuery::findShared($code, $connection)) {
                    $report->warn(\sprintf('The menu "%s" of the language %s was left out: neither the file nor this site has the shared menu of that code.', $code, $menuLocale));
                    $report->skipped('menus');

                    continue;
                }
            }

            $existing = CmsMenuQuery::findForLocale($code, $menuLocale, $connection);

            if (null !== $existing && !$options->replace) {
                $report->skipped('menus');

                continue;
            }

            $model = $existing ?? (new CmsMenu())->setCode($code)->setMenuLocale($menuLocale);
            // A file written before menus could be hidden has no key: visible.
            $model->setVisible(($menu['visible'] ?? true) ? 1 : 0);

            foreach ($menu['translations'] ?? [] as $locale => $translation) {
                $model->setLocale((string) $locale)->setTitle((string) ($translation['title'] ?? $code));
            }

            $model->save($connection);

            // The entries of a replaced menu are rebuilt rather than merged:
            // merging two trees by position produces an order nobody asked for.
            CmsMenuItemQuery::create()->filterByMenuId($model->getId())->delete($connection);

            $itemIds = [];

            foreach ($menu['items'] ?? [] as $item) {
                $targetType = (string) ($item['target_type'] ?? 'page');
                $targetId = $this->menuTarget($item, $targetType, $pageIds, $report, $connection);

                if ('page' === $targetType && null === $targetId) {
                    $report->skipped('menu entries');

                    continue;
                }

                $parentUid = null === ($item['parent'] ?? null) ? null : (string) $item['parent'];

                $entry = (new CmsMenuItem())
                    ->setMenuId($model->getId())
                    ->setParent(null !== $parentUid ? ($itemIds[$parentUid] ?? 0) : 0)
                    ->setPosition((int) ($item['position'] ?? 0))
                    ->setTargetType($targetType)
                    ->setTargetId($targetId)
                    ->setUrl($item['url'] ?? null)
                    ->setOpenNewTab(($item['open_new_tab'] ?? false) ? 1 : 0)
                    ->setVisible(($item['visible'] ?? true) ? 1 : 0);

                foreach ($item['translations'] ?? [] as $locale => $translation) {
                    $entry->setLocale((string) $locale)->setLabel($translation['label'] ?? null);
                }

                $entry->save($connection);
                $itemIds[(string) ($item['uid'] ?? '')] = (int) $entry->getId();
            }

            null !== $existing ? $report->replaced('menus') : $report->created('menus');

            if (null !== $existing && CmsMenuQuery::SHARED === $menuLocale) {
                $this->warnAboutKeptMenus($code, $menus, $report, $connection);
            }
        }
    }

    /**
     * A shared menu replaced by the file does not reach the languages that
     * have a menu of their own, unless the file carries that language too.
     *
     * @param list<array<mixed>> $menus
     */
    private function warnAboutKeptMenus(string $code, array $menus, ImportReport $report, ConnectionInterface $connection): void
    {
        $inFile = array_map(
            static fn (array $menu): string => self::menuLocale($menu),
            array_filter($menus, static fn (array $menu): bool => trim((string) ($menu['code'] ?? '')) === $code),
        );

        $kept = array_values(array_diff(array_map(
            'strval',
            CmsMenuQuery::create()
                ->filterByCode($code)
                ->filterByMenuLocale(CmsMenuQuery::SHARED, Criteria::NOT_EQUAL)
                ->orderByMenuLocale()
                ->select(['MenuLocale'])
                ->find($connection)
                ->toArray(),
        ), $inFile));

        if ([] !== $kept) {
            $report->warn(\sprintf('The shared menu "%s" was replaced, but the language(s) %s keep a menu of their own that the file does not carry.', $code, implode(', ', $kept)));
        }
    }

    /**
     * A file written before languages could have a menu of their own has no
     * key: its menus are the shared ones.
     *
     * @param array<mixed> $menu
     */
    private static function menuLocale(array $menu): string
    {
        $locale = $menu['locale'] ?? null;

        return \is_string($locale) ? trim($locale) : CmsMenuQuery::SHARED;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, int>   $pageIds
     */
    private function menuTarget(array $item, string $targetType, array $pageIds, ImportReport $report, ConnectionInterface $connection): ?int
    {
        if ('page' !== $targetType) {
            $rawId = $item['target_id'] ?? null;
            $catalogueType = CatalogueItemType::tryFrom($targetType);

            // The entry is kept either way: the menu screen lists it with the
            // reason it is not shown, and the item may be created afterwards.
            if (null !== $rawId && null !== $catalogueType && !self::catalogueItemExists($catalogueType, (int) $rawId, $connection)) {
                $report->warn(\sprintf(
                    'A menu entry points at a %s of the catalogue by its number (%d), which this site does not have: the entry was kept, and the menu screen lists it as having no target.',
                    $targetType,
                    (int) $rawId,
                ));
            } elseif (null !== $rawId) {
                $report->warn(\sprintf(
                    'A menu entry points at a %s of the catalogue by its number (%d): check that it is the right one on this site.',
                    $targetType,
                    (int) $rawId,
                ));
            }

            return null !== $rawId ? (int) $rawId : null;
        }

        $uid = $item['target'] ?? null;

        if (null === $uid) {
            return null;
        }

        $pageId = $pageIds[(string) $uid] ?? null;

        if (null === $pageId) {
            $report->warn(\sprintf('A menu entry points at page "%s", which is not in the file: the entry was left out.', (string) $uid));
        }

        return $pageId;
    }

    private static function catalogueItemExists(CatalogueItemType $type, int $id, ConnectionInterface $connection): bool
    {
        return match ($type) {
            CatalogueItemType::Product => null !== ProductQuery::create()->findPk($id, $connection),
            CatalogueItemType::Category => null !== CategoryQuery::create()->findPk($id, $connection),
            CatalogueItemType::Content => null !== ContentQuery::create()->findPk($id, $connection),
            CatalogueItemType::Folder => null !== FolderQuery::create()->findPk($id, $connection),
            CatalogueItemType::Dealer => null !== DealerStores::find($id, null, $connection),
        };
    }
}
