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

namespace TheliaCMS\Tests\Integration\Catalogue;

use Dealer\Event\DealerEvent;
use Dealer\Model\Dealer;
use Dealer\Model\DealerMetaSeo;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\CountryQuery;
use TheliaCMS\Catalogue\CatalogueItemKeys;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\DealerStores;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A store of the Dealer module carries a document like the items of the core,
 * while the module is active.
 */
final class DealerStoreContentTest extends CmsIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!DealerStores::available()) {
            self::markTestSkipped('The Dealer module is not active on this shop.');
        }
    }

    public function testAStoreIsReadLikeAnItemOfTheCatalogue(): void
    {
        $store = $this->store('Magasin de Pessac', '<p>Ouvert le dimanche.</p>');

        $item = $this->getService(CatalogueItems::class)->find(CatalogueItemType::Dealer, (int) $store->getId(), $this->locale());

        self::assertNotNull($item);
        self::assertSame('Magasin de Pessac', $item->title);
        self::assertSame('Ouvert le dimanche.', $item->summary);
        self::assertNull($item->url, 'The core builds no address for a store.');
        self::assertTrue($item->online);
        self::assertNull($this->getService(CatalogueItems::class)->find(CatalogueItemType::Dealer, 987654, $this->locale()));
    }

    public function testTheDocumentOfAStoreIsPublished(): void
    {
        $id = (int) $this->store('Magasin de Mérignac')->getId();

        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Dealer, $id, $this->locale()), Document::richText('<h2>Le marché</h2><p>Tous les samedis.</p>'));
        $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Dealer, $id, $this->locale()));

        self::assertStringContainsString('Tous les samedis.', (string) $this->getService(ItemContentRepository::class)->publishedHtml(CatalogueItemType::Dealer, $id, $this->locale()));
    }

    public function testADeletedStoreTakesItsDocumentsWithIt(): void
    {
        $store = $this->store('Magasin de Talence');
        $id = (int) $store->getId();

        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Dealer, $id, $this->locale()), Document::richText('<h2>Magasin</h2><p>Texte</p>'));
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, $id, $this->locale()), Document::richText('<h2>Produit</h2><p>Texte</p>'));

        $event = new DealerEvent();
        $event->setDealer($store);
        $this->getService(EventDispatcherInterface::class)->dispatch($event, DealerStores::DELETED_EVENT);

        self::assertSame(0, CmsItemContentQuery::create()->filterByItemType('dealer')->filterByItemId($id)->count());
        self::assertSame(1, CmsItemContentQuery::create()->filterByItemType('product')->filterByItemId($id)->count(), 'Another item holding the same id keeps its document.');
    }

    public function testAnExportNamesAStoreByTheSlugOfItsSeoRecord(): void
    {
        $id = (int) $this->store('Magasin de Bègles')->getId();
        (new DealerMetaSeo())->setDealerId($id)->setSlug('so-bio-begles')->save($this->getPropelConnection());

        $keys = $this->getService(CatalogueItemKeys::class);
        $key = $keys->keyOf(CatalogueItemType::Dealer, $id);

        self::assertSame('so-bio-begles', $key['slug'] ?? null);
        self::assertSame($id, $keys->find(CatalogueItemType::Dealer, $key));
        self::assertNull($keys->find(CatalogueItemType::Dealer, ['slug' => 'nowhere', 'urls' => []]));
    }

    private function store(string $title, ?string $description = null): Dealer
    {
        $store = (new Dealer())
            ->setLocale($this->locale())
            ->setTitle($title)
            ->setDescription($description)
            ->setVisible(1)
            ->setAddress1('1 rue du Marché')
            ->setZipcode('33600')
            ->setCity('Pessac')
            ->setCountryId((int) CountryQuery::create()->findOne()?->getId());
        $store->save($this->getPropelConnection());

        return $store;
    }

    private function itemWriter(): ContentWriter
    {
        return $this->getService(ContentWriter::class);
    }
}
