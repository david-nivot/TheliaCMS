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
use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\Map\CategoryTableMap;
use Thelia\Model\Map\ContentTableMap;
use Thelia\Model\Map\FolderTableMap;
use Thelia\Model\Map\ProductTableMap;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Tests\Integration\CountsStatements;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The entry form of a menu searches its target rather than listing the whole
 * site: each kind of target is a number field the record picker fills from its
 * source, checked by the server when the entry is saved.
 */
final class CatalogueMenuEntryScreenTest extends AdminScreenTestCase
{
    use CountsStatements;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    public function testEachTargetIsAFieldSearchedInItsSourceAndNoListIsSent(): void
    {
        $product = $this->product('Vernis mat');
        $form = $this->entryForm($this->screen($this->menuAddress()));

        $sources = [
            'pageId' => '/admin/cms/partials/sources/pages?locale='.$this->locale(),
            'contentId' => '/admin/cms/partials/sources/contents?locale='.$this->locale(),
            'folderId' => '/admin/cms/partials/sources/folders/search?locale='.$this->locale(),
            'categoryId' => '/admin/cms/partials/sources/categories?offline=1&locale='.$this->locale(),
            'productId' => '/admin/cms/partials/sources/products?offline=1&locale='.$this->locale(),
        ];

        foreach ($sources as $field => $source) {
            $input = $form->filter(\sprintf('input[name$="[%s]"]', $field));
            self::assertSame(1, $input->count(), \sprintf('The form has no field %s.', $field));
            self::assertSame('number', $input->attr('type'), 'Without the script, the number is typed.');
            self::assertSame($source, $input->attr('data-cms-picker'));
            self::assertNotNull($input->attr('data-cms-picker-searchable'));
            self::assertSame(0, $form->filter(\sprintf('select[name$="[%s]"]', $field))->count(), \sprintf('%s is still a select.', $field));
        }

        self::assertStringNotContainsString('Vernis mat', (string) $form->html(), 'The products are no longer listed in the form.');
        $screen = $this->screen($this->menuAddress());
        self::assertSame(1, $screen->filter('script[src*="record-picker.js"]')->count(), 'The screen loads the record picker.');
        self::assertSame(1, $screen->filter('link[rel="stylesheet"][href*="record-picker.css"]')->count(), 'and its own look, not the one of the theme.');

        // `form_end()` prints a field the template forgot at the bottom of the
        // form, under the submit button: each target comes before the label.
        $markup = (string) $form->html();
        self::assertLessThan(strpos($markup, '[label]'), strpos($markup, '[categoryId]'));
        self::assertLessThan(strpos($markup, '[label]'), strpos($markup, '[productId]'));
        self::assertGreaterThan(0, (int) $product->getId());
    }

    public function testTheTargetsAreNamedInTheLanguageTheMenuIsEditedIn(): void
    {
        $other = LangQuery::create()->filterByActive(true)->filterByLocale($this->locale(), Criteria::NOT_EQUAL)->findOne();
        self::assertInstanceOf(Lang::class, $other, 'The shop has a second language.');

        $form = $this->entryForm($this->screen($this->menuAddress().'?edit_language_id='.$other->getId()));

        self::assertSame('/admin/cms/partials/sources/pages?locale='.$other->getLocale(), $form->filter('input[name$="[pageId]"]')->attr('data-cms-picker'));
    }

    public function testOnlyTheFieldOfTheChosenKindShowsFromTheFirstPaint(): void
    {
        $screen = $this->screen($this->menuAddress());
        $form = $this->entryForm($screen);

        self::assertSame(['page'], $this->shownKinds($form), 'A new entry points at a page.');
        self::assertSame(1, $screen->filter('noscript')->reduce(static fn (Crawler $fallback): bool => str_contains($fallback->html(), '[data-cms-menu-target-field][hidden]'))->count(), 'Without the script, every field shows again.');

        $refused = $this->submitEntry(MenuTargetType::Product, 'productId', '999999999');

        self::assertSame(['product'], $this->shownKinds($this->entryForm($refused)), 'A refused entry shows again the field of the kind it was given.');
    }

    /**
     * @return list<string>
     */
    private function shownKinds(Crawler $form): array
    {
        $kinds = [];

        foreach ($form->filter('[data-cms-menu-target-field]') as $row) {
            \assert($row instanceof \DOMElement);

            if (!$row->hasAttribute('hidden')) {
                $kinds[] = $row->getAttribute('data-cms-menu-target-field');
            }
        }

        return $kinds;
    }

    public function testAnEntryPointingAtAnOfflineProductIsSavedAndReopensWithIt(): void
    {
        $product = $this->product('Vernis mat caché', 0);
        $menu = $this->menu();
        $before = CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count();

        $this->submitEntry(MenuTargetType::Product, 'productId', (string) $product->getId());

        self::assertTrue($this->client->getResponse()->isRedirection(), 'The entry was refused: '.strip_tags((string) $this->client->getResponse()->getContent()));
        self::assertSame($before + 1, CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count());
        $saved = $this->lastEntry();
        self::assertSame('product', $saved->getTargetType());
        self::assertSame((int) $product->getId(), (int) $saved->getTargetId());

        $reopened = $this->entryForm($this->screen($this->menuAddress().'/entries/'.$saved->getId()), '/entries/'.$saved->getId());
        self::assertSame((string) $product->getId(), $reopened->filter('input[name$="[productId]"]')->attr('value'), 'The picker names it from the number held.');
    }

    public function testEachKindOfTargetIsSavedFromItsNumber(): void
    {
        $folder = $this->factory->folder(0, ['title' => 'Dossier de menu', 'locale' => $this->locale()]);
        $targets = [
            [MenuTargetType::Page, 'pageId', (int) $this->createPage('Page de menu')->getId()],
            [MenuTargetType::Content, 'contentId', (int) $this->factory->content($folder, ['locale' => $this->locale()])->getId()],
            [MenuTargetType::Folder, 'folderId', (int) $folder->getId()],
            [MenuTargetType::Category, 'categoryId', (int) $this->factory->category()->getId()],
        ];

        foreach ($targets as [$type, $field, $id]) {
            $this->submitEntry($type, $field, (string) $id);

            self::assertTrue($this->client->getResponse()->isRedirection(), \sprintf('The %s entry was refused: %s', $type->value, strip_tags((string) $this->client->getResponse()->getContent())));
            self::assertSame([$type->value, $id], [$this->lastEntry()->getTargetType(), (int) $this->lastEntry()->getTargetId()]);
        }
    }

    public function testANumberNothingHasIsRefusedWithTheReason(): void
    {
        $menu = $this->menu();
        $before = CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count();

        $screen = $this->submitEntry(MenuTargetType::Product, 'productId', '999999999');

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The form is shown again.');
        self::assertStringContainsString('Nothing has this number any more', $screen->text());
        self::assertSame($before, CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count());
    }

    public function testAPageInTheBinIsRefusedAndSaysSo(): void
    {
        $page = $this->createPage('Page partie à la corbeille');
        $page->setDeletedAt(new \DateTimeImmutable())->save();

        $screen = $this->submitEntry(MenuTargetType::Page, 'pageId', (string) $page->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('This page is in the bin', $screen->text());
    }

    public function testTheScreenCostsTheSameAndWeighsTheSameOnABiggerSite(): void
    {
        $address = $this->menuAddress();
        $this->screen($address);

        $small = $this->measure($address);
        $category = $this->factory->category();

        for ($n = 1; $n <= 30; ++$n) {
            $this->product('Produit en nombre '.$n, 1, $category);
            $this->createPage('Page en nombre '.$n, false);
        }

        $big = $this->measure($address);

        self::assertGreaterThan(0, $small['statements'], 'nothing was measured');
        self::assertSame($small, $big, 'the menu screen reads or sends more on a bigger site, so the targets are listed again');
    }

    /**
     * @return array{statements: int, bytes: int}
     */
    private function measure(string $address): array
    {
        $this->forgetWhatWasRead([ProductTableMap::class, CategoryTableMap::class, ContentTableMap::class, FolderTableMap::class, CmsPageTableMap::class]);
        $bytes = 0;
        $statements = $this->statementsFor(function () use ($address, &$bytes): void {
            $bytes = \strlen((string) $this->screen($address)->filter('form[action*="/entries/new"]')->html());
        });

        return ['statements' => $statements, 'bytes' => $bytes];
    }

    private function submitEntry(MenuTargetType $type, string $field, string $id): Crawler
    {
        $form = $this->entryForm($this->screen($this->menuAddress()));
        $fields = $form->form();
        $name = (string) preg_replace('/\[targetType\]$/', '', (string) $form->filter('select[name$="[targetType]"]')->attr('name'));
        $fields[$name.'[targetType]']->select($type->value);
        $fields[$name.'['.$field.']']->setValue($id);

        return $this->client->submit($fields, [], self::SAME_SITE);
    }

    private function entryForm(Crawler $screen, string $entry = '/entries/new'): Crawler
    {
        $action = $this->menuAddress().$entry;
        $form = $screen->filter('form')->reduce(
            static fn ($node): bool => parse_url((string) $node->attr('action'), \PHP_URL_PATH) === $action,
        );
        self::assertSame(1, $form->count(), 'The menu screen has no form for '.$entry.'.');

        return $form;
    }

    private function menu(): CmsMenu
    {
        $menu = CmsMenuQuery::findShared('footer');
        self::assertInstanceOf(CmsMenu::class, $menu);

        return $menu;
    }

    private function menuAddress(): string
    {
        return '/admin/cms/menus/'.$this->menu()->getId();
    }

    private function lastEntry(): CmsMenuItem
    {
        $saved = CmsMenuItemQuery::create()->filterByMenuId($this->menu()->getId())->orderById('desc')->findOne();
        self::assertInstanceOf(CmsMenuItem::class, $saved);

        return $saved;
    }

    private function product(string $title, int $visible = 1, ?\Thelia\Model\Category $category = null): Product
    {
        return $this->factory->product(
            $category ?? $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['title' => $title, 'locale' => $this->locale(), 'visible' => $visible],
        );
    }
}
