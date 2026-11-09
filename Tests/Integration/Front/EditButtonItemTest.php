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

namespace TheliaCMS\Tests\Integration\Front;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Admin;
use Thelia\Model\Lang;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentThemeHook;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Front\EditButtonThemeHook;
use TheliaCMS\Http\PageCacheListener;
use TheliaCMS\Preview\ItemPreviewLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The button on the page of a catalogue item: drawn when the page printed the
 * published document of its own item, the way Flexy prints it (the hooks of
 * ItemContentThemeHook), and asked at the bottom of the body afterwards.
 */
final class EditButtonItemTest extends CmsIntegrationTestCase
{
    private const int ITEM_ID = 876545;

    /** @var list<Request>|null the requests of the test case, set aside while a page is served */
    private ?array $setAside = null;

    protected function setUp(): void
    {
        parent::setUp();

        ModuleConfigQuery::resetConfigCache();
    }

    protected function tearDown(): void
    {
        if (null !== $this->setAside) {
            $stack = $this->getService(RequestStack::class);
            $stack->pop();

            foreach ($this->setAside as $request) {
                $stack->push($request);
            }

            $this->setAside = null;
        }

        parent::tearDown();

        ModuleConfigQuery::resetConfigCache();
    }

    public function testThePageOfAProductLeadsToTheScreenOfItsDocument(): void
    {
        $this->publish(CatalogueItemType::Product);
        $request = $this->servePageOf(CatalogueItemType::Product, $this->admin());

        $this->printOnThePage('product.content', ['product' => ['id' => self::ITEM_ID]]);
        $button = $this->bottomOfThePage();

        self::assertStringContainsString(
            \sprintf('href="/admin/cms/catalogue/product/%d/content?edit_language_id=%d"', self::ITEM_ID, Lang::getDefaultLanguage()->getId()),
            $button,
        );
        self::assertTrue($request->attributes->get(PageCacheListener::PERSONAL_ATTRIBUTE), 'The page is nobody else\'s.');
    }

    public function testThePageOfACategoryLeadsToTheScreenOfItsDocument(): void
    {
        $this->publish(CatalogueItemType::Category);
        $this->servePageOf(CatalogueItemType::Category, $this->admin());

        $this->printOnThePage('category.bottom', ['category' => ['id' => self::ITEM_ID]]);

        self::assertStringContainsString(\sprintf('/admin/cms/catalogue/category/%d/content', self::ITEM_ID), $this->bottomOfThePage());
    }

    public function testAProductWithoutADocumentHasNoButton(): void
    {
        $request = $this->servePageOf(CatalogueItemType::Product, $this->admin());

        $this->printOnThePage('product.content', ['product' => ['id' => self::ITEM_ID]]);

        self::assertSame('', $this->bottomOfThePage());
        self::assertNull($request->attributes->get(PageCacheListener::PERSONAL_ATTRIBUTE));
    }

    public function testTheDocumentOfAnotherItemPrintedOnThePageGetsNoButton(): void
    {
        $this->publish(CatalogueItemType::Product);
        // A category page that prints the document of one of its products.
        $this->servePageOf(CatalogueItemType::Category, $this->admin());

        $this->printOnThePage('product.content', ['product' => ['id' => self::ITEM_ID]]);

        self::assertSame('', $this->bottomOfThePage());
    }

    public function testThePreviewOfTheDraftOnThePageOfTheProductHasNoButton(): void
    {
        $this->publish(CatalogueItemType::Product);
        $request = $this->servePageOf(CatalogueItemType::Product, $this->admin());

        $link = (string) $this->getService(ItemPreviewLink::class)->pageUrlFor(
            new CatalogueItem(CatalogueItemType::Product, self::ITEM_ID, $this->locale(), 'Produit', null, null, null, 'https://shop.test/produit.html'),
        );
        parse_str((string) parse_url($link, \PHP_URL_QUERY), $query);
        $request->query->add($query);

        $this->printOnThePage('product.content', ['product' => ['id' => self::ITEM_ID]]);

        self::assertSame('', $this->bottomOfThePage());
    }

    public function testAVisitorSeesNoButton(): void
    {
        $this->publish(CatalogueItemType::Product);
        $this->servePageOf(CatalogueItemType::Product, null);

        $this->printOnThePage('product.content', ['product' => ['id' => self::ITEM_ID]]);

        self::assertSame('', $this->bottomOfThePage());
    }

    public function testAnAdministratorWhoMayOnlyReadThePagesSeesNoButton(): void
    {
        $this->publish(CatalogueItemType::Product);
        $reader = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
        ]);
        $this->servePageOf(CatalogueItemType::Product, $reader);

        $this->printOnThePage('product.content', ['product' => ['id' => self::ITEM_ID]]);

        self::assertSame('', $this->bottomOfThePage());
    }

    private function admin(): Admin
    {
        return (new FixtureFactory($this->getPropelConnection()))->admin();
    }

    private function publish(CatalogueItemType $type): void
    {
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($this->itemTarget($type, self::ITEM_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>Sur la fiche.</p>'));
        $writer->publish($this->itemTarget($type, self::ITEM_ID, $this->locale()));
    }

    /**
     * The page of the item as the rewriting router hands it over, with the
     * session of whoever reads it.
     */
    private function servePageOf(CatalogueItemType $type, ?Admin $admin): Request
    {
        $request = Request::create('/fiche-de-test');
        $request->attributes->set('_view', $type->value);
        $request->query->set($type->value.'_id', (string) self::ITEM_ID);

        $session = new Session(new MockArraySessionStorage());

        if (null !== $admin) {
            $admin->eraseCredentials();
            $session->setAdminUser($admin);
        }

        $request->setSession($session);

        // The main request is the first of the stack: the ones of the test
        // case are set aside while this one is served.
        $stack = $this->getService(RequestStack::class);
        $setAside = [];

        while (null !== ($popped = $stack->pop())) {
            array_unshift($setAside, $popped);
        }

        $this->setAside = $setAside;
        $stack->push($request);

        return $request;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function printOnThePage(string $hookName, array $parameters): void
    {
        $this->getService(ItemContentThemeHook::class)->render($hookName, $parameters);
    }

    private function bottomOfThePage(): string
    {
        return $this->getService(EditButtonThemeHook::class)->render(EditButtonThemeHook::HOOK, []);
    }
}
