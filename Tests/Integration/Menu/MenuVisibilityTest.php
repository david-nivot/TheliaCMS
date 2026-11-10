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

namespace TheliaCMS\Tests\Integration\Menu;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Core\Content\Slot\ContentSlots;
use Thelia\Model\LangQuery;
use TheliaCMS\ImportExport\ExportFormat;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Menu\Admin\CmsMenuRepository;
use TheliaCMS\Menu\Admin\CmsMenuWriter;
use TheliaCMS\Menu\Admin\MenuItemData;
use TheliaCMS\Menu\CmsContentSlotResolver;
use TheliaCMS\Menu\CmsMenuProvider;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A menu and each of its entries can be hidden from the site without being
 * deleted: the provider a theme reads leaves them out.
 */
final class MenuVisibilityTest extends CmsIntegrationTestCase
{
    private const string CODE = 'visibility-menu-test';

    public function testEverythingIsVisibleByDefault(): void
    {
        $menu = $this->menu();
        $item = $this->entry($menu, 'Annonce', 1);

        self::assertSame(1, (int) CmsMenuQuery::create()->findPk($menu->getId())?->getVisible());
        self::assertSame(1, (int) CmsMenuItemQuery::create()->findPk($item->getId())?->getVisible());
        self::assertSame(['Annonce'], $this->labels());
    }

    public function testAHiddenMenuRendersNothing(): void
    {
        $menu = $this->menu();
        $this->entry($menu, 'Annonce', 1);
        $menu->setVisible(0)->save();
        $this->getService(MenuCache::class)->invalidate();

        self::assertSame([], $this->labels());
        self::assertTrue($this->provider()->hasEntries(self::CODE), 'A menu hidden on purpose is a menu somebody took over.');
    }

    public function testAHiddenEmptyMenuIsStillTakenOver(): void
    {
        $menu = $this->menu();
        $menu->setVisible(0)->save();
        $this->getService(MenuCache::class)->invalidate();

        self::assertTrue($this->provider()->hasEntries(self::CODE));
        self::assertFalse($this->provider()->hasEntries('visibility-menu-unknown'));
    }

    public function testAHiddenMainMenuLeavesNothingToTheHeaderSlot(): void
    {
        $main = CmsMenuQuery::findShared('main');
        self::assertInstanceOf(CmsMenu::class, $main, 'The seeder creates the main menu; without it this test measures nothing.');
        $this->entry($main, 'Accueil', 99);
        $main->setVisible(0)->save();
        $this->getService(MenuCache::class)->invalidate();

        self::assertSame([], $this->getService(CmsContentSlotResolver::class)->resolve(ContentSlots::HEADER, $this->locale()));
    }

    public function testAHiddenEntryIsLeftOutWithItsChildrenAndItsSiblingsStay(): void
    {
        $menu = $this->menu();
        $parent = $this->entry($menu, 'Parent', 1);
        $this->entry($menu, 'Enfant', 1, $parent);
        $this->entry($menu, 'Frère', 2);
        self::assertSame(['Parent', 'Frère'], $this->labels());
        self::assertSame(['Enfant'], array_map(static fn (array $node): string => (string) $node['label'], $this->provider()->menu(self::CODE)[0]['children']));

        $parent->setVisible(0)->save();
        $this->getService(MenuCache::class)->invalidate();

        self::assertSame(['Frère'], $this->labels());
    }

    public function testAHiddenChildLeavesItsParent(): void
    {
        $menu = $this->menu();
        $parent = $this->entry($menu, 'Parent', 1);
        $child = $this->entry($menu, 'Enfant', 1, $parent);
        $child->setVisible(0)->save();
        $this->getService(MenuCache::class)->invalidate();

        $nodes = $this->provider()->menu(self::CODE);

        self::assertSame(['Parent'], array_map(static fn (array $node): string => (string) $node['label'], $nodes));
        self::assertSame([], $nodes[0]['children']);
    }

    public function testTheWriterHidesAnEntryAndBringsItBackThroughTheCache(): void
    {
        $menu = $this->menu();
        $item = $this->entry($menu, 'Annonce', 1);
        self::assertSame(['Annonce'], $this->labels(), 'Read once, so the answer is cached.');

        $this->menuWriter()->saveItem($menu, $item, $this->locale(), $this->data('Annonce', false));
        self::assertSame([], $this->labels());
        self::assertSame(1, CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count(), 'Hidden, not deleted.');

        $this->menuWriter()->saveItem($menu, $item, $this->locale(), $this->data('Annonce', true));
        self::assertSame(['Annonce'], $this->labels());
    }

    public function testTheWriterHidesAMenuAndBringsItBackThroughTheCache(): void
    {
        $menu = $this->menu();
        $this->entry($menu, 'Annonce', 1);
        self::assertSame(['Annonce'], $this->labels(), 'Read once, so the answer is cached.');

        $this->menuWriter()->saveMenu($menu, $this->locale(), self::CODE, 'Menu de test', false);
        self::assertSame([], $this->labels());

        $this->menuWriter()->saveMenu($menu, $this->locale(), self::CODE, 'Menu de test', true);
        self::assertSame(['Annonce'], $this->labels());
    }

    public function testTheBackOfficeListsAHiddenEntryAndTheEntriesUnderItAsHidden(): void
    {
        $menu = $this->menu();
        $parent = $this->entry($menu, 'Parent', 1);
        $this->entry($menu, 'Enfant', 1, $parent);
        $this->entry($menu, 'Frère', 2);
        $parent->setVisible(0)->save();

        $rows = [];

        foreach ($this->getService(CmsMenuRepository::class)->rows($menu, $this->locale()) as $row) {
            $rows[$row->target->label] = [$row->hidden, $row->hiddenByParent];
        }

        self::assertSame(['Parent' => [true, false], 'Enfant' => [true, true], 'Frère' => [false, false]], $rows);
    }

    public function testADetachedMenuStartsWithTheVisibilityOfTheSharedOne(): void
    {
        $other = LangQuery::create()->filterByActive(true)->filterByLocale($this->locale(), Criteria::NOT_EQUAL)->findOne();

        if (null === $other) {
            self::markTestSkipped('A second active language is needed.');
        }

        $menu = $this->menu();
        $this->entry($menu, 'Cachée', 1)->setVisible(0)->save();
        $this->entry($menu, 'Visible', 2);

        $detached = $this->menuWriter()->detach(self::CODE, (string) $other->getLocale());

        $copies = [];

        foreach (CmsMenuItemQuery::create()->filterByMenuId($detached->getId())->find() as $copy) {
            $copies[$copy->setLocale($this->locale())->getLabel()] = (int) $copy->getVisible();
        }

        self::assertSame(['Cachée' => 0, 'Visible' => 1], $copies);
    }

    public function testVisibilityTravelsThroughAnExportAndItsImport(): void
    {
        $menu = $this->menu();
        $this->entry($menu, 'Annonce', 1)->setVisible(0)->save();
        $this->entry($menu, 'Contact', 2);
        $menu->setVisible(0)->save();

        $file = $this->getService(SiteExporter::class)->export();
        $exported = array_values(array_filter($file['menus'], static fn (array $entry): bool => self::CODE === $entry['code']))[0];

        self::assertFalse($exported['visible']);
        self::assertSame([false, true], array_map(static fn (array $item): bool => $item['visible'], $exported['items']));

        // Back to visible on the site, then replaced by the file.
        $menu->setVisible(1)->save();

        foreach (CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->find() as $item) {
            $item->setVisible(1)->save();
        }

        $file['menus'] = [$exported];
        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($file), new ImportOptions(replace: true));
        $this->getService(MenuCache::class)->invalidate();

        $imported = CmsMenuQuery::findShared(self::CODE);
        self::assertNotNull($imported);
        self::assertSame(0, (int) $imported->getVisible());

        $visible = [];

        foreach (CmsMenuItemQuery::create()->filterByMenuId($imported->getId())->orderByPosition()->find() as $item) {
            $visible[] = (int) $item->getVisible();
        }

        self::assertSame([0, 1], $visible);
    }

    public function testAnExportWrittenBeforeTheKeyImportsEverythingVisible(): void
    {
        $menu = $this->menu();
        $this->entry($menu, 'Annonce', 1)->setVisible(0)->save();
        $menu->setVisible(0)->save();

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray([
            'format' => ExportFormat::NAME,
            'version' => ExportFormat::VERSION,
            'module_version' => '2.23.0',
            'locales' => [$this->locale()],
            'menus' => [[
                'code' => self::CODE,
                'translations' => [$this->locale() => ['title' => 'Menu de test']],
                'items' => [[
                    'uid' => 'item-1',
                    'parent' => null,
                    'position' => 1,
                    'target_type' => 'url',
                    'url' => '/annonce',
                    'translations' => [$this->locale() => ['label' => 'Annonce']],
                ]],
            ]],
        ]), new ImportOptions(replace: true));
        $this->getService(MenuCache::class)->invalidate();

        $imported = CmsMenuQuery::findShared(self::CODE);
        self::assertNotNull($imported);
        self::assertSame(1, (int) $imported->getVisible());
        self::assertSame(1, (int) CmsMenuItemQuery::create()->filterByMenuId($imported->getId())->findOne()?->getVisible());
        self::assertSame(['Annonce'], $this->labels());
    }

    private function menu(): CmsMenu
    {
        $menu = (new CmsMenu())->setCode(self::CODE);
        $menu->setLocale($this->locale())->setTitle('Menu de test');
        $menu->save();

        return $menu;
    }

    private function entry(CmsMenu $menu, string $label, int $position, ?CmsMenuItem $parent = null): CmsMenuItem
    {
        $item = (new CmsMenuItem())
            ->setMenuId($menu->getId())
            ->setParent(null === $parent ? 0 : (int) $parent->getId())
            ->setPosition($position)
            ->setTargetType('url')
            ->setUrl('https://example.com/'.rawurlencode($label));
        $item->setLocale($this->locale())->setLabel($label);
        $item->save();

        $this->getService(MenuCache::class)->invalidate();

        return $item;
    }

    private function data(string $label, bool $visible): MenuItemData
    {
        return new MenuItemData(
            type: MenuTargetType::Url,
            targetId: null,
            url: 'https://example.com/annonce',
            label: $label,
            parent: 0,
            openNewTab: false,
            visible: $visible,
        );
    }

    /**
     * @return list<string>
     */
    private function labels(): array
    {
        return array_map(static fn (array $node): string => (string) $node['label'], $this->provider()->menu(self::CODE));
    }

    private function provider(): CmsMenuProvider
    {
        return $this->getService(CmsMenuProvider::class);
    }

    private function menuWriter(): CmsMenuWriter
    {
        return $this->getService(CmsMenuWriter::class);
    }
}
