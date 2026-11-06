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

namespace TheliaCMS\Tests\Integration\Zone;

use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Content\Document;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\ModelPageSettingException;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\RecordingCachePurger;
use TheliaCMS\Zone\HeaderZone;
use TheliaCMS\Zone\HeaderZoneReader;
use TheliaCMS\Zone\HeaderZoneThemeHook;

/**
 * The header zone: one hidden page, written on the content screen of a page,
 * drawn in the header of the theme, and no page of the site.
 */
final class HeaderZoneTest extends CmsIntegrationTestCase
{
    private RecordingCachePurger $purger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purger = new RecordingCachePurger();
    }

    public function testTheZoneIsCreatedOnceAndReused(): void
    {
        $zones = $this->getService(HeaderZone::class);

        self::assertNull($zones->find(), 'No zone before the first visit of its screen.');

        $first = $zones->ensure();
        $second = $zones->ensure();

        self::assertSame((int) $first->getId(), (int) $second->getId());
        self::assertSame(1, CmsPageQuery::create()->filterByModel(2)->count(), 'One zone, however many times it is asked for.');
        self::assertSame(2, $first->getModel());
        self::assertSame(0, $first->getVisible(), 'The zone is hidden.');
        self::assertSame((int) $first->getId(), (int) CmsPageQuery::create()->filterByModel(2)->findOne()?->getId());
    }

    public function testTheZoneIsNamedInTheWordsOfEachLanguage(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();

        self::assertSame('Zone d’en-tête', $zone->getTranslation('fr_FR')->getTitle(), 'The content screen in French is titled in French.');
        self::assertSame('Header zone', $zone->getTranslation('en_US')->getTitle());
    }

    public function testPublishingTheZoneDropsTheSiteAndWritesNoAddress(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Trente pourcents sur les tirages cyanotypes.</p>'));
        $this->purger->purged = [];

        $this->writerPurgingWith($this->purger)->publish($zone, $this->locale());

        self::assertContains(CacheTags::SITE, $this->purger->purged, 'The zone is in the header of every page of the site, the shop included.');
        self::assertNotContains(CacheTags::page((int) $zone->getId()), $this->purger->purged, 'No page carries the tag of the zone.');

        CmsPageTableMap::clearInstancePool();
        $zone = CmsPageQuery::create()->findPk($zone->getId());
        self::assertNotNull($zone);
        self::assertSame('', trim((string) $zone->setLocale($this->locale())->getRewrittenUrl($this->locale())), 'The zone has no address.');
        self::assertSame(0, (int) $this->getPropelConnection()->query(\sprintf("SELECT COUNT(*) FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $zone->getId()))->fetchColumn(), 'Nothing reserved in the table of addresses.');
        self::assertSame(0, CmsPageSearchQuery::create()->filterByPageId($zone->getId())->count(), 'Nothing in the search index.');
    }

    public function testUnpublishingAndRenderingAgainDropTheSiteToo(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Un bandeau.</p>'));
        $writer = $this->writerPurgingWith($this->purger);
        $writer->publish($zone, $this->locale());

        $this->purger->purged = [];
        $writer->unpublish($zone, $this->locale());
        self::assertContains(CacheTags::SITE, $this->purger->purged, 'Taking the zone offline empties the header of the cached pages.');

        $this->purger->purged = [];
        $this->purgingWith(ContentTargets::class, $this->purger)->forPage($zone, $this->locale())->afterRenderedAgain();
        self::assertContains(CacheTags::SITE, $this->purger->purged, 'Rendered again, the zone changes in every page.');
    }

    public function testTheZoneIsNeitherTrashedNorSetOnline(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();

        try {
            $this->writer()->moveToTrash($zone);
            self::fail('The zone went to the bin.');
        } catch (ModelPageSettingException) {
            self::addToAssertionCount(1);
        }

        try {
            $this->writer()->toggleVisibility($zone);
            self::fail('The zone was set online.');
        } catch (ModelPageSettingException) {
            self::addToAssertionCount(1);
        }

        CmsPageTableMap::clearInstancePool();
        $stored = CmsPageQuery::create()->findPk($zone->getId());
        self::assertNull($stored?->getDeletedAt());
        self::assertSame(0, $stored?->getVisible());
    }

    public function testTheHookDrawsThePublishedZoneInTheHeader(): void
    {
        $this->publishedZone('<h2>Soldes</h2><p>Trente pourcents.</p>');

        $markup = $this->hook()->render('layout.header.bottom', []);

        self::assertStringStartsWith('<div class="cms-zone cms-zone--header">', $markup);
        self::assertStringContainsString('Trente pourcents.', $markup);
        self::assertStringEndsWith('</div>', $markup);
        self::assertSame('', $this->hook()->render('layout.footer.top', []), 'Only the header hook is answered.');
    }

    public function testTheHookDrawsNothingUntilTheZoneIsPublished(): void
    {
        self::assertSame('', $this->hook()->render('layout.header.bottom', []), 'No zone page yet.');

        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Brouillon</h2><p>Pas en ligne.</p>'));

        self::assertSame('', $this->hook()->render('layout.header.bottom', []), 'A draft is drawn nowhere.');

        $this->writer()->publish($zone, $this->locale());
        self::assertNotSame('', $this->hook()->render('layout.header.bottom', []));

        $this->writer()->unpublish($zone, $this->locale());
        self::assertSame('', $this->hook()->render('layout.header.bottom', []), 'Offline again, nothing is drawn.');
    }

    public function testALanguageWithoutAPublishedZoneDrawsNothing(): void
    {
        $zone = $this->publishedZone('<h2>Soldes</h2><p>Le texte de la langue servie.</p>');
        $other = 'en_US' === $this->locale() ? 'fr_FR' : 'en_US';
        $reader = $this->getService(HeaderZoneReader::class);

        self::assertNotNull($reader->publishedHtml($this->locale()));
        self::assertNull($reader->publishedHtml($other), 'No fallback on the default language: the banner would be in the wrong one.');
        self::assertSame([$this->locale()], $this->getService(HeaderZone::class)->publishedLocales($zone));
    }

    public function testTheReaderLeavesAnOrdinaryPageAlone(): void
    {
        $this->createPage('Page ordinaire', html: '<h2>Page</h2><p>Une page ordinaire, en ligne.</p>');

        self::assertNull($this->getService(HeaderZoneReader::class)->publishedHtml($this->locale()), 'The reader asks for the zone, not for any published page.');
        self::assertSame('', $this->hook()->render('layout.header.bottom', []));
    }

    private function publishedZone(string $html): CmsPage
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText($html));
        $this->getService(CmsPageWriter::class)->publish($zone, $this->locale());

        return $zone;
    }

    private function hook(): HeaderZoneThemeHook
    {
        return $this->getService(HeaderZoneThemeHook::class);
    }
}
