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

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Folder\FolderDeleteEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\Admin\EmptyItemContentException;
use TheliaCMS\Catalogue\Admin\ItemContentStatus;
use TheliaCMS\Catalogue\Admin\ItemHeadingStructureException;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Section;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsItemRevisionQuery;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The document of a catalogue item goes through the pipeline of a page: a
 * filtered draft, a publication refused when it would show nothing, a
 * snapshot and its revision written together.
 */
final class ContentWriterTest extends CmsIntegrationTestCase
{
    use ClockSensitiveTrait;

    private const int PRODUCT_ID = 987654;

    public function testSavingWritesTheDraftAndLeavesTheItemOffline(): void
    {
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Questions</h2><p>Une réponse.</p>'));

        $content = $this->contents()->find(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale());

        self::assertNotNull($content);
        self::assertStringContainsString('<p>Une réponse.</p>', (string) $content->getDraftHtml());
        self::assertNull($content->getPublishedHtml(), 'Saving never changes what visitors see.');
        self::assertSame(ItemContentStatus::Draft, ItemContentStatus::resolve($content));
        self::assertNull($this->contents()->publishedHtml(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
    }

    public function testTheDraftIsFilteredLikeTheDraftOfAPage(): void
    {
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Titre</h2><p>Texte</p><script>alert(1)</script>'));

        self::assertStringNotContainsString('<script', (string) $this->contents()->find(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale())?->getDraftHtml());
    }

    public function testPublishingWritesTheSnapshotAndARevision(): void
    {
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Category, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Gamme</h2><p>Toute la gamme.</p>'));
        $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Category, self::PRODUCT_ID, $this->locale()));

        $content = $this->contents()->find(CatalogueItemType::Category, self::PRODUCT_ID, $this->locale());

        self::assertNotNull($content?->getPublishedAt());
        self::assertSame($content->getDraftDocument(), $content->getPublishedDocument());
        self::assertStringContainsString('Toute la gamme.', (string) $this->contents()->publishedHtml(CatalogueItemType::Category, self::PRODUCT_ID, $this->locale()));
        self::assertSame(1, CmsItemRevisionQuery::create()->filterByItemType('category')->filterByItemId(self::PRODUCT_ID)->count());
        self::assertNull(
            $this->contents()->publishedHtml(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()),
            'The same id of another type is another item.',
        );
    }

    public function testAnItemJustPublishedIsNotModifiedSincePublication(): void
    {
        // Frozen far from the real time: the edit date has to come from the
        // same instant as the publication date, not from a second reading of
        // the clock when the row is saved.
        self::mockTime('2020-01-15 10:00:00');
        $target = $this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale());
        $this->itemWriter()->saveContent($target, Document::richText('<h2>Titre</h2><p>Texte</p>'));
        $this->itemWriter()->publish($target);

        $content = $this->contents()->find(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale());

        self::assertEquals($content?->getPublishedAt(), $content?->getUpdatedAt());
        self::assertSame(ItemContentStatus::Published, ItemContentStatus::resolve($content));
    }

    public function testUnpublishingDropsTheSnapshotAndKeepsTheDraft(): void
    {
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Titre</h2><p>Texte</p>'));
        $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
        $this->itemWriter()->unpublish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));

        $content = $this->contents()->find(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale());

        self::assertNull($content?->getPublishedAt());
        self::assertNull($content?->getPublishedHtml());
        self::assertNotNull($content?->getDraftDocument());
    }

    public function testADocumentNamingAComponentTheCatalogueLacksIsRefused(): void
    {
        $this->expectException(InvalidDocumentException::class);

        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), new Document([new Section('a', 'Nope:Missing', [])]));
    }

    /**
     * A draft written by a script or an import never went through the editor,
     * and the editor is where the document is validated. The id below is one
     * the validator refuses and the renderer does not notice.
     */
    public function testADraftWrittenOutsideTheEditorThatTheValidatorRefusesIsNotPublished(): void
    {
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h2>Titre</h2><p>Texte</p>'));
        $this->contents()->find(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale())
            ?->setDraftDocument(Document::richText('<h2>Titre</h2><p>Texte</p>', 'Not an id')->toJson())
            ->save();

        try {
            $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
            self::fail('A draft the validator refuses is not published.');
        } catch (InvalidDocumentException) {
        }

        self::assertNull($this->contents()->find(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale())?->getPublishedAt());
        self::assertSame(0, CmsItemRevisionQuery::create()->filterByItemType('product')->filterByItemId(self::PRODUCT_ID)->count());
    }

    public function testAnItemWithNothingOnItIsNotPublishedAndLeavesNoRevision(): void
    {
        try {
            $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
            self::fail('Nothing to publish.');
        } catch (EmptyItemContentException) {
        }

        self::assertSame(0, CmsItemContentQuery::create()->filterByItemType('product')->filterByItemId(self::PRODUCT_ID)->filterByPublishedAt(null, Criteria::ISNOTNULL)->count());
        self::assertSame(0, CmsItemRevisionQuery::create()->filterByItemType('product')->filterByItemId(self::PRODUCT_ID)->count());
    }

    public function testABrokenHeadingStructureKeepsTheDocumentOfflineWhenTheSiteAsksForIt(): void
    {
        $previous = TheliaCMS::getConfigValue(CmsSettings::HEADING_CHECK_MODE, 'warn');
        TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, 'block');

        try {
            $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()), Document::richText('<h1>Un</h1><h4>Saut</h4>'));

            $this->expectException(ItemHeadingStructureException::class);

            $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Product, self::PRODUCT_ID, $this->locale()));
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::HEADING_CHECK_MODE, (string) $previous);
        }
    }

    public function testADeletedItemTakesItsDocumentsWithIt(): void
    {
        $folder = (new FixtureFactory($this->getPropelConnection()))->folder();
        $id = (int) $folder->getId();

        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Folder, $id, $this->locale()), Document::richText('<h2>Dossier</h2><p>Texte</p>'));
        $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Folder, $id, $this->locale()));
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, $id, $this->locale()), Document::richText('<h2>Produit</h2><p>Texte</p>'));

        $this->getService(EventDispatcherInterface::class)->dispatch(new FolderDeleteEvent($id), TheliaEvents::FOLDER_DELETE);

        self::assertSame(0, CmsItemContentQuery::create()->filterByItemType('folder')->filterByItemId($id)->count());
        self::assertSame(0, CmsItemRevisionQuery::create()->filterByItemType('folder')->filterByItemId($id)->count());
        self::assertSame(1, CmsItemContentQuery::create()->filterByItemType('product')->filterByItemId($id)->count(), 'Another item holding the same id keeps its document.');
    }

    private function itemWriter(): ContentWriter
    {
        return $this->getService(ContentWriter::class);
    }

    private function contents(): ItemContentRepository
    {
        return $this->getService(ItemContentRepository::class);
    }
}
