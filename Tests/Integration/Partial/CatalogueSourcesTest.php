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

namespace TheliaCMS\Tests\Integration\Partial;

use Thelia\Core\Security\AccessManager;
use Thelia\Model\Category;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Partial\Catalogue\PickablePages;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The searched sources of products, categories, contents, folders and pages:
 * what they offer an editor picking one for a component of the theme or for
 * the target of a menu entry.
 */
final class CatalogueSourcesTest extends AdminScreenTestCase
{
    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new FixtureFactory($this->getPropelConnection());
    }

    public function testTheProductSourceFindsByReferenceNameAndBarcodeAndOffersOnlyProductsOnline(): void
    {
        $online = $this->product('CMSBLK-ONLINE', 'Zucchini catalogue block', 1);
        $online->getDefaultSaleElements()->setEanCode('3999999999990')->save();
        $offline = $this->product('CMSBLK-OFFLINE', 'Zucchini catalogue block hidden', 0);

        self::assertSame([(int) $online->getId()], $this->ids('products', ['q' => 'CMSBLK-']), 'By the start of the reference, online only.');
        self::assertSame([(int) $online->getId()], $this->ids('products', ['q' => 'catalogue block']), 'By a part of the name, online only.');
        self::assertSame([(int) $online->getId()], $this->ids('products', ['q' => '3999999999990']), 'By the barcode of a sale element.');
        self::assertSame([(int) $online->getId()], $this->ids('products', ['q' => 'CMSBLK-O']), 'One more letter narrows.');
        self::assertSame([], $this->ids('products', ['q' => '%%']), 'A wildcard is searched for, not obeyed.');

        $chosen = $this->answer('products', ['id' => (int) $offline->getId()]);
        self::assertCount(1, $chosen, 'The product already chosen is read back, offline or not.');
        self::assertStringContainsString('Zucchini catalogue block hidden (CMSBLK-OFFLINE)', $chosen[0]['name']);
        self::assertNotSame('Zucchini catalogue block hidden (CMSBLK-OFFLINE)', $chosen[0]['name'], 'An offline product says so.');
        self::assertSame('Zucchini catalogue block (CMSBLK-ONLINE)', $this->answer('products', ['id' => (int) $online->getId()])[0]['name'], 'An online one is named alone.');
        self::assertSame([], $this->answer('products', ['id' => 999999999]));
    }

    public function testTheCategorySourceNamesEachCategoryWithItsParentAndOffersOnlyCategoriesOnline(): void
    {
        $parent = $this->category('Catalogue block aisle', 0, 1);
        $child = $this->category('Catalogue block shelf', (int) $parent->getId(), 1);
        $this->category('Catalogue block shelf hidden', (int) $parent->getId(), 0);

        $found = $this->answer('categories', ['q' => 'Catalogue block shelf']);

        self::assertSame([(int) $child->getId()], array_column($found, 'id'), 'Online only.');
        self::assertSame('Catalogue block shelf (Catalogue block aisle)', $found[0]['name'], 'Named with its parent.');
    }

    public function testAMenuAsksTheOfflineProductsAndCategoriesTooNamedAsSuch(): void
    {
        $online = $this->product('CMSMNU-ONLINE', 'Menu target turnip', 1);
        $offline = $this->product('CMSMNU-OFFLINE', 'Menu target turnip hidden', 0);
        $category = $this->category('Menu target aisle hidden', 0, 0);

        $products = $this->answer('products', ['q' => 'Menu target turnip', 'offline' => 1]);

        self::assertSame([(int) $online->getId(), (int) $offline->getId()], array_column($products, 'id'), 'Online and offline.');
        self::assertSame('Menu target turnip (CMSMNU-ONLINE)', $products[0]['name']);
        self::assertSame('Offline, not shown on the page: Menu target turnip hidden (CMSMNU-OFFLINE)', $products[1]['name'], 'The offline one says so,');
        self::assertSame('Menu target turnip hidden (CMSMNU-OFFLINE) (offline)', $products[1]['label'], 'after its title in a list, which reads in the order of the titles.');
        self::assertSame([(int) $category->getId()], $this->ids('categories', ['q' => 'Menu target aisle', 'offline' => 1]));
        self::assertSame([], $this->ids('categories', ['q' => 'Menu target aisle']), 'Without the parameter, a block still gets the online ones only.');
    }

    public function testTheContentSourceNamesEachContentWithItsFolderOnlineOrNot(): void
    {
        $news = $this->factory->folder(0, ['title' => 'Menu target newsroom', 'locale' => $this->locale()]);
        $press = $this->factory->folder(0, ['title' => 'Menu target pressroom', 'locale' => $this->locale()]);
        $first = $this->factory->content($news, ['title' => 'Menu target notice', 'locale' => $this->locale()]);
        $second = $this->factory->content($press, ['title' => 'Menu target notice', 'locale' => $this->locale(), 'visible' => 0]);

        $found = $this->answer('contents', ['q' => 'target notice']);

        self::assertSame([(int) $first->getId(), (int) $second->getId()], array_column($found, 'id'));
        self::assertSame('Menu target notice (Menu target newsroom)', $found[0]['name'], 'Two contents of the same title are told apart by their folder.');
        self::assertSame('Offline, not shown on the page: Menu target notice (Menu target pressroom)', $found[1]['name']);
        self::assertSame((int) $second->getId(), $this->answer('contents', ['id' => (int) $second->getId()])[0]['id'], 'The content already chosen is read back.');
        self::assertSame([], $this->answer('contents', ['id' => 999999999]));
    }

    public function testTheSearchedFolderSourceNamesEachFolderWithItsParentOnlineOrNot(): void
    {
        $section = $this->factory->folder(0, ['title' => 'Menu target section', 'locale' => $this->locale()]);
        $child = $this->factory->folder((int) $section->getId(), ['title' => 'Menu target archive', 'locale' => $this->locale(), 'visible' => 0]);

        $found = $this->answer('folders/search', ['q' => 'Menu target archive']);

        self::assertSame([(int) $child->getId()], array_column($found, 'id'));
        self::assertSame('Offline, not shown on the page: Menu target archive (Menu target section)', $found[0]['name']);
        self::assertSame('Menu target section', $this->answer('folders/search', ['id' => (int) $section->getId()])[0]['name']);
        self::assertNotContains((int) $child->getId(), array_column($this->answer('folders', []), 'id'), 'The whole list a block reads keeps the online folders only.');
    }

    public function testThePageSourceLeavesOutAPageAndThePagesUnderItWhenAsked(): void
    {
        $top = $this->createPage('Menu target branch top');
        $middle = $this->createPage('Menu target branch middle');
        $middle->setParent((int) $top->getId())->save();
        $bottom = $this->createPage('Menu target branch bottom');
        $bottom->setParent((int) $middle->getId())->save();
        $aside = $this->createPage('Menu target branch aside');

        self::assertSame(
            [(int) $aside->getId(), (int) $top->getId()],
            $this->ids('pages', ['q' => 'Menu target branch', 'except' => (int) $middle->getId()]),
            'Neither the page nor any page under it can become its parent.',
        );
        self::assertCount(4, $this->ids('pages', ['q' => 'Menu target branch']), 'Without the parameter, every page.');
    }

    public function testAPageInTheBinIsReadBackSayingSoButNeverOffered(): void
    {
        $page = $this->createPage('Menu target binned page');
        $page->setDeletedAt(new \DateTimeImmutable())->save();

        self::assertSame([], $this->ids('pages', ['q' => 'Menu target binned']));
        self::assertSame('In the bin: Menu target binned page', $this->answer('pages', ['id' => (int) $page->getId()])[0]['name']);
    }

    public function testWithoutATextASourceAnswersTheFirstItemsAndSaysHowManyMatch(): void
    {
        for ($n = 1; $n <= PickablePages::LIMIT + 2; ++$n) {
            $this->createPage(\sprintf('Numerous page %02d', $n), false);
        }

        $found = $this->answer('pages', ['q' => 'Numerous page']);

        self::assertCount(PickablePages::LIMIT, $found, 'The first ones only.');
        self::assertSame('Numerous page 01', $found[0]['name']);
        self::assertSame((string) (PickablePages::LIMIT + 2), $this->client->getResponse()->headers->get('X-Total-Count'), 'The total tells there are more.');

        self::assertSame('1', $this->totalOf('pages', ['q' => 'Numerous page 07']));
        self::assertCount(PickablePages::LIMIT, $this->answer('pages', []), 'The list an editor opens before typing.');
        self::assertGreaterThanOrEqual(PickablePages::LIMIT + 2, (int) $this->client->getResponse()->headers->get('X-Total-Count'));
    }

    public function testEachSearchSendsItsTotal(): void
    {
        $offline = $this->product('CMSTOT-OFFLINE', 'Total check turnip', 0);
        $this->product('CMSTOT-ONLINE', 'Total check turnip', 1);
        $folder = $this->factory->folder(0, ['title' => 'Total check folder', 'locale' => $this->locale()]);
        $this->factory->content($folder, ['title' => 'Total check content', 'locale' => $this->locale()]);
        $this->category('Total check aisle', 0, 0);

        self::assertSame('1', $this->totalOf('products', ['q' => 'Total check turnip']), 'Online only, as the list.');
        self::assertSame('2', $this->totalOf('products', ['q' => 'Total check turnip', 'offline' => 1]));
        self::assertSame('0', $this->totalOf('categories', ['q' => 'Total check aisle']));
        self::assertSame('1', $this->totalOf('categories', ['q' => 'Total check aisle', 'offline' => 1]));
        self::assertSame('1', $this->totalOf('contents', ['q' => 'Total check content']));
        self::assertSame('1', $this->totalOf('folders/search', ['q' => 'Total check folder']));
        self::assertGreaterThan(0, (int) $offline->getId());
    }

    public function testARecordWithoutATitleInTheLanguageIsFoundByItsTitleInAnother(): void
    {
        $other = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $folder = $this->factory->folder(0, ['title' => 'Untranslated check folder', 'locale' => $this->locale()]);
        $content = $this->factory->content($folder, ['title' => 'Seulement ailleurs', 'locale' => $other, 'visible' => 0]);
        $category = $this->factory->category(['visible' => 1]);
        $category->setLocale($other)->setTitle('Rayon seulement ailleurs')->save();
        $tag = substr($other, 0, 2);

        $found = $this->answer('contents', ['q' => 'Seulement ailleurs']);

        self::assertSame([(int) $content->getId()], array_column($found, 'id'), 'Searched by its title in the other language.');
        self::assertSame(\sprintf('Offline, not shown on the page: Seulement ailleurs [%s] (Untranslated check folder)', $tag), $found[0]['name'], 'Named by it, with its language.');
        self::assertSame($found[0]['name'], $this->answer('contents', ['id' => (int) $content->getId()])[0]['name'], 'The record held reads the same.');
        self::assertSame([\sprintf('Rayon seulement ailleurs [%s]', $tag)], array_column($this->answer('categories', ['q' => 'Rayon seulement']), 'name'));
    }

    public function testASourceNamesTheRecordsInTheLanguageTheScreenEdits(): void
    {
        $other = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $folder = $this->factory->folder(0, ['title' => 'Edited language folder', 'locale' => $this->locale()]);
        $content = $this->factory->content($folder, ['title' => 'Edited language here', 'locale' => $this->locale(), 'visible' => 0]);
        $content->setLocale($other)->setTitle('Langue éditée ailleurs')->save();

        self::assertSame([\sprintf('Langue éditée ailleurs (Edited language folder [%s])', substr($this->locale(), 0, 2))], array_map(
            static fn (array $row): string => (string) preg_replace('/^.*: /', '', $row['name']),
            $this->answer('contents', ['q' => 'Langue éditée', 'locale' => $other]),
        ), 'Searched and named in the language asked, its folder by its title in another language, as in its path.');
        self::assertSame(['Edited language here (Edited language folder)'], array_map(
            static fn (array $row): string => (string) preg_replace('/^.*: /', '', $row['name']),
            $this->answer('contents', ['q' => 'Edited language here', 'locale' => 'xx_XX']),
        ), 'A language the shop does not have falls back on the one of the back office, named as such.');
    }

    public function testEachItemSaysWhereItSitsSoTwoOfTheSameNameStayApart(): void
    {
        $top = $this->category('Path top aisle', 0, 1);
        $middle = $this->category('Path middle shelf', (int) $top->getId(), 1);
        $leaf = $this->category('Path leaf box', (int) $middle->getId(), 1);
        $product = $this->factory->product($leaf, $this->factory->taxRule(), $this->factory->currency(), ['ref' => 'CMSPATH-1', 'title' => 'Path shelved jar', 'locale' => $this->locale()]);

        self::assertSame(
            ['id' => (int) $leaf->getId(), 'name' => 'Path leaf box (Path top aisle › Path middle shelf)', 'label' => 'Path leaf box', 'path' => 'Path top aisle › Path middle shelf'],
            $this->answer('categories', ['q' => 'Path leaf box'])[0],
            'The field keeps the name with the whole path above it; the list shows the title and the path under it.',
        );
        self::assertSame('Path top aisle › Path middle shelf › Path leaf box', $this->answer('products', ['q' => 'CMSPATH-1'])[0]['path'] ?? null, 'A product sits in its default category.');
        self::assertArrayNotHasKey('path', $this->answer('categories', ['q' => 'Path top aisle'])[0], 'A category at the top sits nowhere.');

        $section = $this->factory->folder(0, ['title' => 'Path section folder', 'locale' => $this->locale()]);
        $sub = $this->factory->folder((int) $section->getId(), ['title' => 'Path sub folder', 'locale' => $this->locale()]);
        $this->factory->content($sub, ['title' => 'Path filed note', 'locale' => $this->locale()]);

        self::assertSame('Path section folder › Path sub folder', $this->answer('contents', ['q' => 'Path filed note'])[0]['path'] ?? null, 'A content sits in its default folder, the folder included.');

        $paths = [];

        foreach (['Path rubric one', 'Path rubric two'] as $rubricTitle) {
            $rubric = $this->createPage($rubricTitle, false);
            $news = $this->createPage('Path news', false);
            $news->setParent((int) $rubric->getId())->save();
            $year = $this->createPage('Path year 2026', false);
            $year->setParent((int) $news->getId())->save();
            $paths[] = $rubricTitle.' › Path news';
        }

        $years = $this->answer('pages', ['q' => 'Path year 2026']);

        self::assertEqualsCanonicalizing($paths, array_column($years, 'path'), 'Same title, same parent title: the paths tell them apart,');
        self::assertEqualsCanonicalizing(
            array_map(static fn (string $path): string => 'Path year 2026 ('.$path.')', $paths),
            array_column($years, 'name'),
            'and so do the names a field keeps once one is chosen.',
        );
    }

    public function testTheListReadsAsATreeAndTheNameOfAParentBringsWhatIsUnderIt(): void
    {
        $aisle = $this->category('Tree check aisle', 0, 1);
        $second = $this->category('Tree check second aisle', 0, 1);
        $shelf = $this->category('Tree check shelf', (int) $aisle->getId(), 1);
        $box = $this->category('Tree check box', (int) $shelf->getId(), 1);
        $other = $this->category('Tree check other', (int) $second->getId(), 1);

        foreach ([[$aisle, 900001], [$second, 900002], [$shelf, 1], [$box, 1], [$other, 1]] as [$category, $position]) {
            $category->setPosition($position)->save();
        }

        self::assertSame(
            [(int) $aisle->getId(), (int) $shelf->getId(), (int) $box->getId(), (int) $second->getId(), (int) $other->getId()],
            $this->ids('categories', ['q' => 'Tree check']),
            'Each category under its parent, in the order of the back office, not by title.',
        );
        self::assertSame(
            [(int) $shelf->getId(), (int) $box->getId()],
            $this->ids('categories', ['q' => 'Tree check shelf']),
            'The name of a category brings the categories under it.',
        );

        $jar = $this->factory->product($box, $this->factory->taxRule(), $this->factory->currency(), ['ref' => 'CMSTREE-1', 'title' => 'Unrelated jar', 'locale' => $this->locale()]);

        self::assertSame([(int) $jar->getId()], $this->ids('products', ['q' => 'Tree check box']), 'A product is found by the name of its category.');

        $section = $this->createPage('Tree check section', false);
        $rubric = $this->createPage('Tree check rubric', false);
        $rubric->setParent((int) $section->getId())->save();
        $article = $this->createPage('Unrelated article', false);
        $article->setParent((int) $rubric->getId())->save();

        self::assertSame(
            [(int) $section->getId(), (int) $rubric->getId(), (int) $article->getId()],
            $this->ids('pages', ['q' => 'Tree check section']),
            'A section brings its pages, each under its parent.',
        );
    }

    public function testARecordWithNoTitleAtAllIsInTheOpenListByItsNumber(): void
    {
        $folder = $this->factory->folder(0, ['title' => 'Untitled check folder', 'locale' => $this->locale()]);
        $before = (int) $this->totalOf('contents', []);
        $content = $this->factory->content($folder, ['title' => 'Soon untitled', 'locale' => $this->locale(), 'visible' => 0]);
        $this->getPropelConnection()->exec('DELETE FROM content_i18n WHERE id = '.(int) $content->getId());

        self::assertSame((string) ($before + 1), $this->totalOf('contents', []), 'The list opened without a text counts it,');
        self::assertSame([], $this->ids('contents', ['q' => 'Soon untitled']), 'no text finds it,');
        self::assertStringContainsString(\sprintf('#%d', $content->getId()), $this->answer('contents', ['id' => (int) $content->getId()])[0]['name'], 'and it is named by its number.');
    }

    public function testADefaultCategoryOrFolderTwiceNeverListsAnItemTwice(): void
    {
        $product = $this->product('CMSDUP-1', 'Duplicate shelf jar', 1);
        $other = $this->category('Duplicate other shelf', 0, 1);
        $this->getPropelConnection()->exec(\sprintf('INSERT INTO product_category (product_id, category_id, default_category, position) VALUES (%d, %d, 1, 99)', $product->getId(), $other->getId()));
        $folder = $this->factory->folder(0, ['title' => 'Duplicate folder one', 'locale' => $this->locale()]);
        $second = $this->factory->folder(0, ['title' => 'Duplicate folder two', 'locale' => $this->locale()]);
        $content = $this->factory->content($folder, ['title' => 'Duplicate filed note', 'locale' => $this->locale(), 'visible' => 0]);
        $this->getPropelConnection()->exec(\sprintf('INSERT INTO content_folder (content_id, folder_id, default_folder, position) VALUES (%d, %d, 1, 99)', $content->getId(), $second->getId()));

        self::assertSame([(int) $product->getId()], $this->ids('products', ['q' => 'CMSDUP-1']));
        self::assertSame('1', $this->totalOf('products', ['q' => 'CMSDUP-1']));
        self::assertSame([(int) $content->getId()], $this->ids('contents', ['q' => 'Duplicate filed note']));
        self::assertSame('1', $this->totalOf('contents', ['q' => 'Duplicate filed note']));
    }

    public function testTheSourcesNeedTheRightToSeeThePages(): void
    {
        $this->logInAs($this->factory->restrictedAdmin([]));

        self::assertSame(403, $this->send('GET', '/admin/cms/partials/sources/products?q=ab', [], self::XHR));
        self::assertSame(403, $this->send('GET', '/admin/cms/partials/sources/categories?q=ab', [], self::XHR));
        self::assertSame(403, $this->send('GET', '/admin/cms/partials/sources/contents?q=ab', [], self::XHR));
        self::assertSame(403, $this->send('GET', '/admin/cms/partials/sources/folders/search?q=ab', [], self::XHR));
        self::assertSame(403, $this->send('GET', '/admin/cms/partials/sources/pages?q=ab', [], self::XHR));
    }

    public function testWhoeverMayOpenAScreenThatPicksMayReadTheSources(): void
    {
        foreach ([CmsResources::MENU, CmsResources::FORM, CmsResources::SETTINGS, CmsResources::PAGE] as $resource) {
            $this->logInAs($this->factory->restrictedAdmin([$resource => [AccessManager::VIEW]]));

            foreach (['products?offline=1&q=ab', 'categories?q=ab', 'contents?q=ab', 'folders/search?q=ab', 'pages?q=ab'] as $source) {
                self::assertSame(200, $this->send('GET', '/admin/cms/partials/sources/'.$source, [], self::XHR), $resource.' reads '.$source);
            }
        }

        $this->logInAs($this->factory->restrictedAdmin([CmsResources::MENU => [AccessManager::VIEW]]));

        self::assertSame(403, $this->send('GET', '/admin/cms/pages', [], self::XHR), 'Reading the sources opens nothing else of the pages.');
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return list<array{id: int, name: string, label?: string, path?: string}>
     */
    private function answer(string $source, array $query): array
    {
        $this->client->request('GET', '/admin/cms/partials/sources/'.$source, $query, [], self::XHR);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string|int> $query
     */
    private function totalOf(string $source, array $query): ?string
    {
        $this->answer($source, $query);

        return $this->client->getResponse()->headers->get('X-Total-Count');
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return list<int>
     */
    private function ids(string $source, array $query): array
    {
        return array_column($this->answer($source, $query), 'id');
    }

    private function product(string $ref, string $title, int $visible): Product
    {
        return $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['ref' => $ref, 'title' => $title, 'locale' => $this->locale(), 'visible' => $visible],
        );
    }

    private function category(string $title, int $parent, int $visible): Category
    {
        $category = $this->factory->category(['parent' => $parent, 'visible' => $visible]);
        $category->setLocale($this->locale())->setTitle($title)->save();

        return $category;
    }
}
