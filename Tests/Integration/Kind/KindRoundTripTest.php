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

namespace TheliaCMS\Tests\Integration\Kind;

use TheliaCMS\Content\Document;
use TheliaCMS\ImportExport\ExportFormat;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Zone\HeaderZone;

/**
 * A content of a kind travels in the export file under its kind and comes back
 * as one: hidden, with no address, no parent and no line of the search, and
 * published as it was. It replaces nothing; the header zone, still marked
 * `zone: true`, is replaced in place as before; a kind no active module
 * declares is not imported.
 */
final class KindRoundTripTest extends CmsIntegrationTestCase
{
    use DeclaresTestKinds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareTestKinds();
    }

    /**
     * Sabotage: drop the `kind` key of `SiteExporter::pageDocument()`: the
     * file names no kind and the first block of assertions is red. Drop
     * `->setContentKind(...)` from `PagesStep`: the imported content is no
     * longer found by `all()` and the second block is red.
     */
    public function testAContentComesBackHiddenAndPublishedUnderItsKind(): void
    {
        $this->createPage('Page de la boutique');
        $content = $this->kindContent('test_guide', 'Guide aller-retour', html: '<h2>Tailles</h2><p>Guide aller-retour des mesures.</p>');
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Bandeau de la zone.</p>'));
        $this->writer()->publish($zone, $this->locale());

        $document = $this->getService(SiteExporter::class)->export();

        self::assertSame(ExportFormat::VERSION, $document['version']);
        $guides = $this->pagesOfKind($document, 'test_guide');
        self::assertCount(1, $guides, 'The file names the kind of the content.');
        self::assertFalse($guides[0]['zone'], 'It is not the zone,');
        self::assertFalse($guides[0]['model'], 'nor the model of a type.');
        $zones = array_values(array_filter($document['pages'], static fn (array $page): bool => true === $page['zone']));
        self::assertCount(1, $zones, 'The zone keeps its flag,');
        self::assertSame('header', $zones[0]['kind'], 'and has its kind.');
        self::assertCount(\count($document['pages']) - 2, array_filter($document['pages'], static fn (array $page): bool => null === $page['kind']), 'The pages have none.');

        $this->emptyTheSite();
        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame([], $report->warnings());
        $imported = $this->getService(KindContents::class)->all('test_guide');
        self::assertCount(1, $imported);
        $this->assertHidden($imported[0]);
        CmsPageContentTableMap::clearInstancePool();
        self::assertStringContainsString('Guide aller-retour des mesures.', (string) $this->getService(KindContents::class)->publishedHtml('test_guide', (int) $imported[0]->getId(), $this->locale()), 'Published as it was.');
        $zone = $this->getService(HeaderZone::class)->find();
        self::assertNotNull($zone, 'The zone is found by its kind.');
        self::assertSame('header', $zone->getContentKind());
    }

    /**
     * Sabotage: let `PagesStep` look the content up through
     * `PageAddresses::existing()`: the slug of the file, which is that of an
     * ordinary page, makes the content replace that page. Or take `parent`
     * of the file: the content gets a parent.
     */
    public function testAFileCannotMakeAContentAPageNorReplaceAnotherContent(): void
    {
        $parentPage = $this->createPage('Page parente');
        $slug = (string) basename((string) $parentPage->getRewrittenUrl($this->locale()));
        self::assertNotSame('', $slug);
        $original = $this->kindContent('test_guide', 'Guide déjà là', html: '<h2>Tailles</h2><p>Premier texte.</p>');
        $document = $this->getService(SiteExporter::class)->export();

        $parentUid = array_values(array_filter($document['pages'], static fn (array $page): bool => null === $page['kind']))[0]['uid'];

        foreach ($document['pages'] as $index => $page) {
            if ('test_guide' === $page['kind']) {
                $document['pages'][$index]['visible'] = true;
                $document['pages'][$index]['parent'] = $parentUid;
                $document['pages'][$index]['position'] = 7;
                $document['pages'][$index]['translations'][$this->locale()]['slug'] = $slug;
            }
        }

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertSame([], $report->warnings());
        $contents = $this->getService(KindContents::class)->all('test_guide');
        self::assertCount(2, $contents, 'A content of a kind replaces nothing: the file brings a second one.');
        self::assertSame((int) $original->getId(), (int) $contents[0]->getId());
        self::assertStringContainsString('Premier texte.', (string) $this->getService(KindContents::class)->publishedHtml('test_guide', (int) $original->getId(), $this->locale()), 'The first is as it was.');
        $this->assertHidden($contents[1]);
        self::assertSame(0, (int) $contents[1]->getPosition(), 'Whatever position the file gives it.');
        CmsPageTableMap::clearInstancePool();
        self::assertSame(0, CmsPageQuery::create()->findPk($parentPage->getId())?->getModel(), 'The page that has the address stays a page: the content does not replace it.');
    }

    public function testAContentOfAKindNoActiveModuleDeclaresIsNotImported(): void
    {
        $this->kindContent('test_guide', 'Guide du module parti');
        $document = $this->getService(SiteExporter::class)->export();

        foreach ($document['pages'] as $index => $page) {
            if ('test_guide' === $page['kind']) {
                $document['pages'][$index]['kind'] = 'module_gone';
            }
        }

        $this->emptyTheSite();
        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('"module_gone"', $report->warnings()[0]);
        self::assertSame(0, CmsPageQuery::create()->filterByModel(2)->count(), 'Nothing hidden was written: no content, and the file holds no zone.');
    }

    /**
     * Sabotage: make `PagesStep::kindOf()` read `kind` only: the zone of a file
     * written before the kinds (no `kind` key, `zone: true`) is imported as an
     * ordinary page and both assertions are red.
     */
    public function testAFileWrittenBeforeTheKindsStillImportsTheZone(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Bandeau ancien.</p>'));
        $this->writer()->publish($zone, $this->locale());
        $document = $this->getService(SiteExporter::class)->export();
        $document['version'] = 4;

        foreach (array_keys($document['pages']) as $index) {
            unset($document['pages'][$index]['kind']);
        }

        $zoneId = (int) $zone->getId();
        $pages = CmsPageQuery::create()->filterByModel(0)->count();
        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));

        self::assertSame([], $report->warnings());
        CmsPageTableMap::clearInstancePool();
        self::assertSame(1, CmsPageQuery::create()->filterByModel(HeaderZone::MODEL)->count(), 'The zone is replaced in place, not made twice.');
        self::assertSame($pages, CmsPageQuery::create()->filterByModel(0)->count(), 'And not imported as one more page.');
        $stored = CmsPageQuery::create()->findPk($zoneId);
        self::assertNotNull($stored);
        self::assertSame('header', $stored->getContentKind());
        self::assertSame(HeaderZone::MODEL, $stored->getModel());
    }

    private function assertHidden(CmsPage $content): void
    {
        $id = (int) $content->getId();

        self::assertSame(2, $content->getModel());
        self::assertSame(0, $content->getVisible(), 'Hidden whatever the file says.');
        self::assertSame(0, $content->getParent(), 'At the root, under no page.');
        self::assertSame(0, (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $id))->fetchColumn(), 'No address, whatever slug the file gives.');
        self::assertSame(0, CmsPageSearchQuery::create()->filterByPageId($id)->count(), 'Not in the search.');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function pagesOfKind(array $document, string $kind): array
    {
        return array_values(array_filter($document['pages'], static fn (array $page): bool => $kind === $page['kind']));
    }

    private function emptyTheSite(): void
    {
        $connection = $this->getPropelConnection();

        foreach (['cms_page_search', 'cms_page_revision', 'cms_page_content', 'cms_page_i18n', 'cms_page'] as $table) {
            $connection->exec('DELETE FROM '.$table);
        }

        $connection->exec("DELETE FROM rewriting_url WHERE view = 'cmspage'");
        CmsPageTableMap::clearInstancePool();
        CmsPageContentTableMap::clearInstancePool();
    }
}
