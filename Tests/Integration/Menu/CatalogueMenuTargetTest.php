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

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Thelia\Core\Event\Category\CategoryToggleVisibilityEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Category;
use Thelia\Model\Product;
use Thelia\Model\RewritingUrl;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Menu\Admin\CmsMenuItemType;
use TheliaCMS\Menu\CmsMenuProvider;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Menu\MenuTargetIssue;
use TheliaCMS\Menu\MenuTargetResolver;
use TheliaCMS\Menu\NativeContentMenuCacheListener;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A menu entry pointing at a category or a product of the catalogue follows
 * the item: its address and its title in the language being read, and it
 * leaves the menu when the item is hidden or deleted.
 */
final class CatalogueMenuTargetTest extends CmsIntegrationTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->getService(MenuCache::class)->invalidate();
    }

    protected function tearDown(): void
    {
        $this->getService(MenuCache::class)->invalidate();

        parent::tearDown();
    }

    public function testACategoryEntryTakesTheTitleAndTheAddressOfEachLanguage(): void
    {
        $category = $this->category(['fr_FR' => 'Résines de sol', 'en_US' => 'Soil resins']);
        $this->address('category', (int) $category->getId(), ['fr_FR' => 'resine-de-sol', 'en_US' => 'soil-resins']);
        $entry = $this->entry('category', (int) $category->getId());
        $resolver = $this->getService(MenuTargetResolver::class);

        $french = $resolver->resolve($entry, 'fr_FR');
        $english = $resolver->resolve($entry, 'en_US');

        self::assertNull($french->issue);
        self::assertSame('Résines de sol', $french->label);
        self::assertStringEndsWith('/resine-de-sol', (string) $french->url);
        self::assertNull($english->issue);
        self::assertSame('Soil resins', $english->label);
        self::assertStringEndsWith('/soil-resins', (string) $english->url);
    }

    public function testAProductEntryKeepsItsOwnLabelAndTakesTheAddressOfTheProduct(): void
    {
        $product = $this->product(['fr_FR' => 'Primaire époxy', 'en_US' => 'Epoxy primer']);
        $this->address('product', (int) $product->getId(), ['fr_FR' => 'primaire-epoxy', 'en_US' => 'epoxy-primer']);
        $entry = $this->entry('product', (int) $product->getId(), ['fr_FR' => 'Notre primaire']);
        $resolver = $this->getService(MenuTargetResolver::class);

        $french = $resolver->resolve($entry, 'fr_FR');
        $english = $resolver->resolve($entry, 'en_US');

        self::assertSame('Notre primaire', $french->label);
        self::assertStringEndsWith('/primaire-epoxy', (string) $french->url);
        self::assertSame('Epoxy primer', $english->label, 'With no label in this language, the title of the product.');
        self::assertStringEndsWith('/epoxy-primer', (string) $english->url);
    }

    public function testAHiddenOrDeletedItemIsLeftOutAndTheReasonIsGiven(): void
    {
        $category = $this->category(['fr_FR' => 'Catégorie masquée'], visible: 0);
        $product = $this->product(['fr_FR' => 'Produit masqué'], visible: 0);
        $resolver = $this->getService(MenuTargetResolver::class);

        $hiddenCategory = $resolver->resolve($this->entry('category', (int) $category->getId()), 'fr_FR');
        $hiddenProduct = $resolver->resolve($this->entry('product', (int) $product->getId()), 'fr_FR');
        $deleted = $resolver->resolve($this->entry('category', 999999999), 'fr_FR');

        self::assertSame(MenuTargetIssue::TargetOffline, $hiddenCategory->issue);
        self::assertSame('Catégorie masquée', $hiddenCategory->label, 'The screen names what is offline.');
        self::assertSame(MenuTargetIssue::TargetOffline, $hiddenProduct->issue);
        self::assertSame(MenuTargetIssue::TargetMissing, $deleted->issue);
    }

    public function testAnItemWithNoTitleInTheLanguageIsReported(): void
    {
        $category = $this->category(['en_US' => 'Only in English']);
        $this->address('category', (int) $category->getId(), ['fr_FR' => 'sans-titre']);

        $resolved = $this->getService(MenuTargetResolver::class)->resolve($this->entry('category', (int) $category->getId()), 'fr_FR');

        self::assertSame(MenuTargetIssue::LabelMissing, $resolved->issue);
        self::assertStringEndsWith('/sans-titre', (string) $resolved->url, 'The address is found; the title is what is missing.');
    }

    public function testTheMenuOfTheThemeNamesTheSourceAndDropsAHiddenCategory(): void
    {
        $category = $this->category(['fr_FR' => 'Résines de sol']);
        $this->address('category', (int) $category->getId(), ['fr_FR' => 'resine-de-sol']);
        $this->onlyEntryOfMain($this->entry('category', (int) $category->getId()));
        $menus = $this->getService(CmsMenuProvider::class);

        $menu = $menus->menu('main', 'fr_FR');

        self::assertSame(['Résines de sol'], array_column($menu, 'label'));
        self::assertStringEndsWith('/resine-de-sol', (string) $menu[0]['url']);
        self::assertSame('category', $menu[0]['source']);
        self::assertSame((int) $category->getId(), $menu[0]['source_id']);

        // Hidden from the screen of the shop: the cache of the menus has to go.
        $this->getService(EventDispatcherInterface::class)->dispatch(
            new CategoryToggleVisibilityEvent($category),
            TheliaEvents::CATEGORY_TOGGLE_VISIBILITY,
        );

        self::assertSame([], $menus->menu('main', 'fr_FR'), 'A category hidden from its own screen is still offered by the menu.');
    }

    /**
     * What a menu shows of a category or a product: its title, its address,
     * whether it is online, whether it exists.
     */
    public function testTheWritesOfTheCatalogueAMenuShowsDropTheMenus(): void
    {
        $dispatcher = $this->getService(EventDispatcherInterface::class);

        foreach (['CATEGORY', 'PRODUCT'] as $item) {
            foreach (['CREATE', 'UPDATE', 'DELETE', 'TOGGLE_VISIBILITY', 'UPDATE_SEO'] as $write) {
                $name = $item.'_'.$write;
                $listening = array_filter(
                    $dispatcher->getListeners((string) \constant(TheliaEvents::class.'::'.$name)),
                    static fn (mixed $listener): bool => \is_array($listener) && $listener[0] instanceof NativeContentMenuCacheListener,
                );

                self::assertNotEmpty($listening, \sprintf('TheliaEvents::%s leaves the menus cached.', $name));
            }
        }
    }

    public function testTheFormTakesTheNumberOfARecordThatExistsAndRefusesAnyOther(): void
    {
        $category = $this->category(['fr_FR' => 'Résines']);
        $product = $this->product(['fr_FR' => 'Primaire époxy'], category: $category);
        $forms = $this->getService(FormFactoryInterface::class);

        $form = $forms->create(CmsMenuItemType::class, null, ['csrf_protection' => false]);
        $form->submit(['targetType' => 'product', 'productId' => (string) $product->getId(), 'label' => '', 'parent' => '0']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame((int) $product->getId(), $form->getData()['productId']);

        $empty = $forms->create(CmsMenuItemType::class, null, ['csrf_protection' => false]);
        $empty->submit(['targetType' => 'category', 'categoryId' => '', 'label' => '', 'parent' => '0']);

        self::assertFalse($empty->isValid());
        self::assertCount(1, $empty->get('categoryId')->getErrors());

        $unknown = $forms->create(CmsMenuItemType::class, null, ['csrf_protection' => false]);
        $unknown->submit(['targetType' => 'category', 'categoryId' => '999999999', 'label' => '', 'parent' => '0']);

        self::assertFalse($unknown->isValid(), 'A number nothing has is refused, as the select refused an option it did not list.');
        self::assertCount(1, $unknown->get('categoryId')->getErrors());

        $typed = $forms->create(CmsMenuItemType::class, null, ['csrf_protection' => false]);
        $typed->submit(['targetType' => 'product', 'productId' => 'Primaire', 'label' => '', 'parent' => '0']);

        self::assertCount(1, $typed->get('productId')->getErrors(), 'Without the script, a word typed in the number field is refused once: '.$typed->get('productId')->getErrors());

        $other = $forms->create(CmsMenuItemType::class, null, ['csrf_protection' => false]);
        $other->submit(['targetType' => 'url', 'url' => '/contact', 'categoryId' => '999999999', 'label' => '', 'parent' => '0']);

        self::assertTrue($other->isValid(), 'Only the field of the chosen kind is checked: '.$other->getErrors(true));
    }

    public function testTheExportKeepsTheIdOfTheItemAndTheImportReportsIt(): void
    {
        $category = $this->category(['fr_FR' => 'Résines de sol']);
        $this->onlyEntryOfMain($this->entry('category', (int) $category->getId()));

        $document = $this->getService(SiteExporter::class)->export();
        $main = array_values(array_filter($document['menus'], static fn (array $menu): bool => 'main' === $menu['code']))[0];

        self::assertSame('category', $main['items'][0]['target_type']);
        self::assertSame((int) $category->getId(), $main['items'][0]['target_id']);

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        $menu = CmsMenuQuery::findShared('main');
        self::assertInstanceOf(CmsMenu::class, $menu);
        $entries = CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->find();
        self::assertCount(1, $entries);
        self::assertSame('category', $entries[0]->getTargetType());
        self::assertSame((int) $category->getId(), (int) $entries[0]->getTargetId());
        self::assertContains(
            \sprintf('A menu entry points at a category of the catalogue by its number (%d): check that it is the right one on this site.', $category->getId()),
            $report->warnings(),
        );
    }

    public function testAnImportedEntryWhoseItemThisSiteDoesNotHaveIsKeptAndReported(): void
    {
        $product = $this->product(['fr_FR' => 'Produit exporté']);
        $this->onlyEntryOfMain($this->entry('product', (int) $product->getId()));
        $document = $this->getService(SiteExporter::class)->export();
        $missing = (int) $product->getId();
        $product->delete();

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        $menu = CmsMenuQuery::findShared('main');
        self::assertInstanceOf(CmsMenu::class, $menu);
        self::assertSame(1, CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->filterByTargetId($missing)->count(), 'The entry is kept.');
        self::assertContains(
            \sprintf('A menu entry points at a product of the catalogue by its number (%d), which this site does not have: the entry was kept, and the menu screen lists it as having no target.', $missing),
            $report->warnings(),
        );
    }

    /**
     * @param array<string, string> $titles by locale
     */
    private function category(array $titles, int $visible = 1, int $parent = 0): Category
    {
        $category = $this->factory->category(['visible' => $visible, 'parent' => $parent]);

        foreach ($titles as $locale => $title) {
            $category->setLocale($locale)->setTitle($title);
        }

        $category->save();

        return $category;
    }

    /**
     * @param array<string, string> $titles by locale
     */
    private function product(array $titles, int $visible = 1, ?Category $category = null): Product
    {
        $product = $this->factory->product(
            $category ?? $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['visible' => $visible],
        );

        foreach ($titles as $locale => $title) {
            $product->setLocale($locale)->setTitle($title);
        }

        $product->save();

        return $product;
    }

    /**
     * Replaces whatever address the save gave the item with the given ones.
     *
     * @param array<string, string> $urls by locale
     */
    private function address(string $view, int $id, array $urls): void
    {
        RewritingUrlQuery::create()->filterByView($view)->filterByViewId((string) $id)->delete();

        foreach ($urls as $locale => $url) {
            (new RewritingUrl())
                ->setUrl($url)
                ->setView($view)
                ->setViewId((string) $id)
                ->setViewLocale($locale)
                ->save();
        }
    }

    /**
     * @param array<string, string> $labels by locale
     */
    private function entry(string $targetType, int $targetId, array $labels = []): CmsMenuItem
    {
        $menu = CmsMenuQuery::findShared('footer');
        self::assertInstanceOf(CmsMenu::class, $menu);

        $entry = (new CmsMenuItem())
            ->setMenuId($menu->getId())
            ->setParent(0)
            ->setPosition(1)
            ->setTargetType($targetType)
            ->setTargetId($targetId);

        foreach ($labels as $locale => $label) {
            $entry->setLocale($locale)->setLabel($label);
        }

        $entry->save();

        return $entry;
    }

    private function onlyEntryOfMain(CmsMenuItem $entry): void
    {
        $menu = CmsMenuQuery::findShared('main');
        self::assertInstanceOf(CmsMenu::class, $menu);
        CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->find()->delete();
        $entry->setMenuId($menu->getId())->save();
        $this->getService(MenuCache::class)->invalidate();
    }
}
