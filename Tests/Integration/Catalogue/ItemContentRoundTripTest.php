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

use Thelia\Model\Product;
use Thelia\Model\RewritingUrl;
use Thelia\Model\RewritingUrlQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The documents of the catalogue items travel in the export file and come
 * back on the items the importing site has: a product found by its
 * reference, whatever its id there.
 */
final class ItemContentRoundTripTest extends CmsIntegrationTestCase
{
    private int $productContentsBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->productContentsBefore = CmsItemContentQuery::create()->filterByItemType('product')->count();
    }

    public function testTheDocumentOfAProductComesBackOnTheProductOfTheSameReference(): void
    {
        $product = $this->product('ROUND-TRIP-REF');
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, (int) $product->getId(), $this->locale()), Document::richText('<h2>Questions</h2><p>Une réponse reconnaissable.</p>'));
        $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Product, (int) $product->getId(), $this->locale()));
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, (int) $product->getId(), $this->locale()), Document::richText('<h2>Questions</h2><p>Une réponse du modèle.</p>'), FakeAiGateway::provenance());
        $this->itemWriter()->publish($this->itemTarget(CatalogueItemType::Product, (int) $product->getId(), $this->locale()));
        $before = $this->contents()->find(CatalogueItemType::Product, (int) $product->getId(), $this->locale());
        self::assertNotNull($before?->getPublishedProvenance());

        $document = $this->getService(SiteExporter::class)->export();

        CmsItemContentQuery::create()->deleteAll();

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        $after = $this->contents()->find(CatalogueItemType::Product, (int) $product->getId(), $this->locale());

        self::assertSame([], $report->warnings());
        self::assertNotNull($after);
        self::assertSame($before?->getPublishedHtml(), $after->getPublishedHtml());
        self::assertSame($before?->getDraftDocument(), $after->getDraftDocument());
        self::assertSame($before?->getDraftProvenance(), $after->getDraftProvenance(), 'The mark of a generated document travels with it.');
        self::assertSame($before?->getPublishedProvenance(), $after->getPublishedProvenance());
        self::assertEquals($before?->getPublishedAt(), $after->getPublishedAt());
        self::assertEquals($before?->getUpdatedAt(), $after->getUpdatedAt(), 'Imported, the document would otherwise read "modified since publication".');
    }

    public function testAFolderIsFoundByTheAddressItAnswersAt(): void
    {
        $exported = (new FixtureFactory($this->getPropelConnection()))->folder();
        $this->address($exported->getId(), 'dossier-aller-retour.html');
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Folder, (int) $exported->getId(), $this->locale()), Document::richText('<h2>Dossier</h2><p>Reconnaissable.</p>'));

        $document = $this->getService(SiteExporter::class)->export();

        // Another site: the same address, another folder.
        CmsItemContentQuery::create()->deleteAll();
        RewritingUrlQuery::create()->filterByView('folder')->filterByViewId((string) $exported->getId())->delete();
        $imported = (new FixtureFactory($this->getPropelConnection()))->folder();
        $this->address($imported->getId(), 'dossier-aller-retour.html');

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame([], $report->warnings());
        self::assertStringContainsString('Reconnaissable.', (string) $this->contents()->find(CatalogueItemType::Folder, (int) $imported->getId(), $this->locale())?->getDraftHtml());
    }

    public function testAnItemTheSiteDoesNotHaveIsSaidAndLeftOut(): void
    {
        $document = $this->fileWith([
            'type' => 'product',
            'key' => ['reference' => 'NOWHERE-REF', 'urls' => []],
            'contents' => [$this->locale() => ['draft_document' => Document::richText('<p>x</p>')->toJson()]],
        ]);

        $report = $this->getService(SiteImporter::class)->import($document, new ImportOptions());

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('"NOWHERE-REF"', $report->warnings()[0]);
        self::assertSame($this->productContentsBefore, CmsItemContentQuery::create()->filterByItemType('product')->count(), 'Nothing was written.');
    }

    public function testAnItemHoldingADocumentKeepsItUnlessTheImportReplaces(): void
    {
        $product = $this->product('KEEP-REF');
        $this->itemWriter()->saveContent($this->itemTarget(CatalogueItemType::Product, (int) $product->getId(), $this->locale()), Document::richText('<h2>Ici</h2>'));

        $document = $this->fileWith([
            'type' => 'product',
            'key' => ['reference' => 'KEEP-REF', 'urls' => []],
            'contents' => [$this->locale() => ['draft_document' => Document::richText('<h2>Ailleurs</h2>')->toJson(), 'draft_html' => '<h2>Ailleurs</h2>']],
        ]);

        $this->getService(SiteImporter::class)->import($document, new ImportOptions());
        self::assertStringContainsString('Ici', (string) $this->contents()->find(CatalogueItemType::Product, (int) $product->getId(), $this->locale())?->getDraftDocument());

        $this->getService(SiteImporter::class)->import($document, new ImportOptions(replace: true));
        self::assertStringContainsString('Ailleurs', (string) $this->contents()->find(CatalogueItemType::Product, (int) $product->getId(), $this->locale())?->getDraftDocument());
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function fileWith(array $entry): SiteDocument
    {
        $document = $this->getService(SiteExporter::class)->export();
        $document['catalogue_contents'] = [$entry];

        return SiteDocument::fromArray($document);
    }

    private function address(int $folderId, string $url): void
    {
        (new RewritingUrl())
            ->setUrl($url)
            ->setView('folder')
            ->setViewId((string) $folderId)
            ->setViewLocale($this->locale())
            ->save();
    }

    private function product(string $reference): Product
    {
        $factory = new FixtureFactory($this->getPropelConnection());

        return $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['ref' => $reference]);
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
