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

namespace TheliaCMS\Tests\Integration\ImportExport;

use TheliaCMS\Content\Document;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Zone\HeaderZone;
use TheliaCMS\Zone\HeaderZoneThemeHook;

/**
 * The header zone travels in the export file and comes back as the zone: hidden,
 * published as it was, with no address, found by its kind and not by an address.
 */
final class HeaderZoneRoundTripTest extends CmsIntegrationTestCase
{
    public function testTheZoneComesBackHiddenAndPublishedWithNoAddress(): void
    {
        $this->createPage('Page de la boutique');
        $this->publishedZone('<h2>Soldes</h2><p>Bandeau aller-retour.</p>');

        $document = $this->getService(SiteExporter::class)->export();

        $zones = array_values(array_filter($document['pages'], static fn (array $page): bool => true === ($page['zone'] ?? false)));
        self::assertCount(1, $zones, 'The file marks the zone.');
        self::assertFalse($zones[0]['model'], 'The zone is not the model of a type.');
        self::assertCount(\count($document['pages']) - 1, array_filter($document['pages'], static fn (array $page): bool => false === ($page['zone'] ?? true)), 'The other pages are not marked.');
        $ordinary = CmsPageQuery::create()->filterByModel(0)->filterByVisible(1)->count();

        $this->emptyTheSite();

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame([], $report->warnings());
        $zone = $this->getService(HeaderZone::class)->find();
        self::assertNotNull($zone, 'The zone is found by its kind.');
        self::assertSame(HeaderZone::MODEL, $zone->getModel());
        self::assertSame(0, $zone->getVisible(), 'The zone is hidden.');
        self::assertSame([$this->locale()], $this->getService(HeaderZone::class)->publishedLocales($zone), 'Its published version is kept.');
        self::assertStringContainsString('Bandeau aller-retour.', $this->getService(HeaderZoneThemeHook::class)->render('layout.header.bottom', []));
        self::assertSame(0, (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $zone->getId()))->fetchColumn(), 'No address written for the zone.');
        self::assertSame(0, CmsPageSearchQuery::create()->filterByPageId($zone->getId())->count(), 'The zone is not in the search.');
        self::assertSame($ordinary, CmsPageQuery::create()->filterByModel(0)->filterByVisible(1)->count(), 'The pages of the site are as many as before, the zone is not one.');
    }

    public function testAFileCannotPutTheZoneOnlineAsAPage(): void
    {
        $this->createPage('Page parente');
        $this->publishedZone('<h2>Soldes</h2><p>Bandeau visible.</p>');
        $document = $this->getService(SiteExporter::class)->export();
        $this->emptyTheSite();

        $parent = array_values(array_filter($document['pages'], static fn (array $page): bool => false === $page['zone']))[0]['uid'];

        foreach ($document['pages'] as $index => $page) {
            if (true === $page['zone']) {
                $document['pages'][$index]['visible'] = true;
                $document['pages'][$index]['parent'] = $parent;
                $document['pages'][$index]['position'] = 7;
                $document['pages'][$index]['translations'][$this->locale()]['slug'] = 'une-adresse';
            }
        }

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        $zone = $this->getService(HeaderZone::class)->find();
        self::assertNotNull($zone);
        self::assertSame(0, $zone->getVisible(), 'Hidden whatever the file says.');
        self::assertSame(0, $zone->getParent(), 'At the root, under no page.');
        self::assertSame(0, $zone->getPosition());
        self::assertSame(0, (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $zone->getId()))->fetchColumn(), 'A slug in the file gives the zone no address.');
    }

    public function testTheZoneOfTheSiteIsMatchedByItsKindAndReplacedOnlyWhenAsked(): void
    {
        $zone = $this->publishedZone('<h2>Soldes</h2><p>Premier texte.</p>');
        $document = $this->getService(SiteExporter::class)->export();

        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Texte du site.</p>'));
        $this->writer()->publish($zone, $this->locale());

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());
        self::assertSame(1, CmsPageQuery::create()->filterByModel(HeaderZone::MODEL)->count(), 'No second zone.');
        self::assertStringContainsString('Texte du site.', $this->publishedHtml($zone), 'Without the option to replace, the zone of the site is kept.');

        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions(replace: true));
        self::assertSame(1, CmsPageQuery::create()->filterByModel(HeaderZone::MODEL)->count(), 'Replaced in place, not duplicated.');
        self::assertStringContainsString('Premier texte.', $this->publishedHtml($zone), 'The zone of the file replaces the one of the site.');
    }

    public function testASecondZoneInTheFileIsAnOrdinaryDraftPageWithAWarning(): void
    {
        $this->publishedZone('<h2>Soldes</h2><p>Zone du fichier.</p>');
        $document = $this->getService(SiteExporter::class)->export();
        $this->emptyTheSite();

        $zones = array_values(array_filter($document['pages'], static fn (array $page): bool => true === ($page['zone'] ?? false)));
        $second = $zones[0];
        $second['uid'] = 'page-second-zone';
        $document['pages'][] = $second;
        $ordinaryBefore = \count($document['pages']) - 2;

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertCount(1, $report->warnings());
        self::assertStringContainsString('another one', $report->warnings()[0]);
        self::assertSame(1, CmsPageQuery::create()->filterByModel(HeaderZone::MODEL)->count(), 'The site has one zone.');

        self::assertSame($ordinaryBefore + 1, CmsPageQuery::create()->filterByModel(0)->count(), 'The second zone is one more ordinary page.');

        $left = CmsPageQuery::create()->filterByModel(0)->filterByVisible(0)->useCmsPageI18nQuery()->filterByTitle('Header zone')->endUse()->findOne();
        self::assertNotNull($left);
        CmsPageContentTableMap::clearInstancePool();
        self::assertNull(CmsPageContentQuery::create()->filterByPageId($left->getId())->findOne()?->getPublishedAt(), 'The page left over is a draft.');
    }

    private function publishedZone(string $html): CmsPage
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText($html));
        $this->writer()->publish($zone, $this->locale());

        return $zone;
    }

    private function publishedHtml(CmsPage $zone): string
    {
        CmsPageContentTableMap::clearInstancePool();

        return (string) CmsPageContentQuery::create()->filterByPageId($zone->getId())->filterByLocale($this->locale())->findOne()?->getPublishedHtml();
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
