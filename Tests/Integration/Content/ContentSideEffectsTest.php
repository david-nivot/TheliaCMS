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

namespace TheliaCMS\Tests\Integration\Content;

use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\EmptyItemContentException;
use TheliaCMS\Catalogue\Admin\ItemContentWriter;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Http\CachePurgerInterface;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Model\CmsItemRevisionQuery;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\EmptyPageContentException;
use TheliaCMS\Page\Admin\PublishedRendering;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * What a write to the content of a page or of a catalogue item sets going
 * besides the rows: the purge of the shared cache and the search index. The
 * two families do not do the same, and the differences are on purpose until
 * someone decides to remove them: an item purges the key of the item on
 * every publication, rendering and unpublication, even when nothing was
 * online; a page purges what depends on it, and only when it was online; only
 * a page is indexed for the search.
 */
final class ContentSideEffectsTest extends CmsIntegrationTestCase
{
    /** @var CachePurgerInterface&object{purged: list<string>} */
    private CachePurgerInterface $purger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purger = new class implements CachePurgerInterface {
            /** @var list<string> */
            public array $purged = [];

            public function purge(array $tags): void
            {
                array_push($this->purged, ...$tags);
            }
        };
    }

    public function testPublishingAnItemPurgesTheKeyOfTheItem(): void
    {
        $id = $this->product();
        $writer = $this->itemWriter();
        $writer->saveContent(CatalogueItemType::Product, $id, $this->locale(), Document::richText('<h2>Questions</h2><p>Sur devis.</p>'));

        self::assertSame([], $this->purger->purged, 'Saving a draft purges nothing.');

        $writer->publish(CatalogueItemType::Product, $id, $this->locale());

        self::assertSame(['product-'.$id], $this->purger->purged);
    }

    public function testRenderingAnItemAgainPurgesItOnlyWhenItIsWritten(): void
    {
        $id = $this->product();
        $writer = $this->itemWriter();
        $writer->saveContent(CatalogueItemType::Product, $id, $this->locale(), Document::richText('<h2>Questions</h2><p>Sur devis.</p>'));
        $writer->publish(CatalogueItemType::Product, $id, $this->locale());
        $this->getService(ItemContentRepository::class)->find(CatalogueItemType::Product, $id, $this->locale())?->setPublishedHtml('<p>old</p>')->save();
        $before = \count($this->purger->purged);

        self::assertSame(PublishedRendering::Rendered, $writer->renderPublishedAgain(CatalogueItemType::Product, $id, $this->locale(), write: false));
        self::assertSame([], \array_slice($this->purger->purged, $before), 'A dry run purges nothing.');

        self::assertSame(PublishedRendering::Rendered, $writer->renderPublishedAgain(CatalogueItemType::Product, $id, $this->locale()));
        self::assertSame(['product-'.$id], \array_slice($this->purger->purged, $before));

        self::assertSame(PublishedRendering::Unchanged, $writer->renderPublishedAgain(CatalogueItemType::Product, $id, $this->locale()));
        self::assertSame(['product-'.$id], \array_slice($this->purger->purged, $before), 'Nothing changed, nothing to purge.');
    }

    public function testTakingAnItemOfflinePurgesItEvenWhenNothingWasOnline(): void
    {
        $id = $this->product();
        $writer = $this->itemWriter();

        $writer->unpublish(CatalogueItemType::Product, $id, $this->locale());

        self::assertSame(['product-'.$id], $this->purger->purged, 'An item without any document is purged all the same.');

        $writer->saveContent(CatalogueItemType::Product, $id, $this->locale(), Document::richText('<h2>Questions</h2><p>Sur devis.</p>'));
        $writer->publish(CatalogueItemType::Product, $id, $this->locale());
        $before = \count($this->purger->purged);

        $writer->unpublish(CatalogueItemType::Product, $id, $this->locale());

        self::assertSame(['product-'.$id], \array_slice($this->purger->purged, $before));
    }

    public function testAnItemRefusedForPublicationPurgesNothingAndKeepsNoRevision(): void
    {
        $id = $this->product();
        $writer = $this->itemWriter();

        try {
            $writer->publish(CatalogueItemType::Product, $id, $this->locale());
            self::fail('Nothing to publish.');
        } catch (EmptyItemContentException) {
        }

        $writer->saveContent(CatalogueItemType::Product, $id, $this->locale(), Document::richText('<h2>Titre</h2><p>Texte</p>'));
        $this->getService(ItemContentRepository::class)->find(CatalogueItemType::Product, $id, $this->locale())
            ?->setDraftDocument(Document::richText('<h2>Titre</h2><p>Texte</p>', 'Not an id')->toJson())
            ->save();

        try {
            $writer->publish(CatalogueItemType::Product, $id, $this->locale());
            self::fail('A draft the validator refuses is not published.');
        } catch (InvalidDocumentException) {
        }

        self::assertSame([], $this->purger->purged, 'A publication rolled back reaches no cache.');
        self::assertSame(0, CmsItemRevisionQuery::create()->filterByItemType('product')->filterByItemId($id)->count());
    }

    public function testAPageRefusedForPublicationPurgesNothingAndKeepsNoRevision(): void
    {
        $page = $this->createPage('Refused', published: false);
        $id = (int) $page->getId();
        CmsPageContentQuery::create()->filterByPageId($id)->delete();

        try {
            $this->pageWriter()->publish($page, $this->locale());
            self::fail('Nothing to publish.');
        } catch (EmptyPageContentException) {
        }

        self::assertSame([], $this->purger->purged);
        self::assertSame(0, CmsPageRevisionQuery::create()->filterByPageId($id)->count());
    }

    public function testTakingAPageOfflinePurgesOnlyWhenItWasOnline(): void
    {
        $offline = $this->createPage('Never online', published: false);

        $this->pageWriter()->unpublish($offline, $this->locale());

        self::assertSame([], $this->purger->purged, 'A page that was not online has nothing to drop from the caches.');

        $online = $this->createPage('Online', published: true);

        $this->pageWriter()->unpublish($online, $this->locale());

        self::assertContains(CacheTags::page((int) $online->getId()), $this->purger->purged);
    }

    public function testSavingTheDraftOfAPagePurgesNothing(): void
    {
        $page = $this->createPage('Saved', published: true);

        $this->pageWriter()->saveContent($page, $this->locale(), Document::richText('<h2>Saved</h2><p>Another draft.</p>'));

        self::assertSame([], $this->purger->purged, 'What is online does not move until the draft is published.');
    }

    public function testOnlyAPageIsIndexedForTheSearchWhenItIsPublished(): void
    {
        $page = $this->createPage('Indexed', published: false, html: '<h2>Indexed</h2><p>Un accompagnement sur mesure.</p>');
        $id = $this->product();
        $writer = $this->itemWriter();
        $writer->saveContent(CatalogueItemType::Product, $id, $this->locale(), Document::richText('<h2>Questions</h2><p>Un accompagnement sur mesure.</p>'));

        $before = CmsPageSearchQuery::create()->count();
        $writer->publish(CatalogueItemType::Product, $id, $this->locale());

        self::assertSame($before, CmsPageSearchQuery::create()->count(), 'The document of an item is not in the index of the pages.');
        self::assertSame(0, CmsPageSearchQuery::create()->filterByPageId($page->getId())->count());

        $this->pageWriter()->publish($page, $this->locale());

        self::assertSame($before + 1, CmsPageSearchQuery::create()->count());
        self::assertStringContainsString('accompagnement', (string) CmsPageSearchQuery::create()->filterByPageId($page->getId())->findOne()?->getContent());
    }

    private function product(): int
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return (int) $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['ref' => 'SIDE-EFFECTS-'.random_int(1000, 9999)])->getId();
    }

    private function itemWriter(): ItemContentWriter
    {
        return $this->purgingWith(ItemContentWriter::class, $this->purger);
    }

    private function pageWriter(): CmsPageWriter
    {
        return $this->writerPurgingWith($this->purger);
    }
}
