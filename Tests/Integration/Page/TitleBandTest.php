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

namespace TheliaCMS\Tests\Integration\Page;

use Thelia\Core\Template\TemplateHelperInterface;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\CmsPageRenderer;
use TheliaCMS\Page\PublishedPageRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A page can open on its own sections, without the heading band of the theme
 * (`cms_page.title_band = 0`): its title stays the level 1 heading, off the
 * screen (ADR 0010).
 */
final class TitleBandTest extends CmsIntegrationTestCase
{
    public function testAPageOpensOnTheBandUnlessItIsSetOtherwise(): void
    {
        $page = $this->createPage('Page ordinaire');

        self::assertSame(1, $this->reloaded($page)->getTitleBand());
        self::assertTrue($this->published($page)->titleBand);

        $page->setTitleBand(0)->save();

        self::assertFalse($this->published($page)->titleBand);
    }

    public function testWithoutTheBandTheTitleIsAHiddenLevelOneHeadingAndTheBandIsNotDrawn(): void
    {
        $this->useAnInstalledFrontTheme();

        if ('flexy' !== $this->getService(TemplateHelperInterface::class)->getActiveFrontTemplate()->getName()) {
            self::markTestSkipped('The layout shipped for the theme is the one of Flexy.');
        }

        $page = $this->createPage('Accueil du site');

        $withBand = $this->getService(CmsPageRenderer::class)->render($this->published($page));
        self::assertStringContainsString('class="Subheader', $withBand);
        self::assertStringNotContainsString('cms-page__title--hidden', $withBand);

        $page->setTitleBand(0)->save();
        $withoutBand = $this->getService(CmsPageRenderer::class)->render($this->published($page));

        self::assertStringNotContainsString('class="Subheader', $withoutBand, 'No heading band of the theme, no breadcrumb.');
        self::assertMatchesRegularExpression('#<h1 class="cms-page__title cms-page__title--hidden">\s*Accueil du site\s*</h1>#', $withoutBand, 'The title is still the level 1 heading.');
        self::assertSame(1, preg_match_all('#<h1[\s>]#', $withoutBand), 'One level 1 heading.');
    }

    public function testACopyOfThePageKeepsItsChoice(): void
    {
        $page = $this->createPage('Page sans bandeau');
        $page->setTitleBand(0)->save();

        $copy = $this->writer()->duplicate($page, $this->locale(), ' (copie)');

        self::assertSame(0, $this->reloaded($copy)->getTitleBand());
    }

    public function testTheChoiceTravelsInTheExportFile(): void
    {
        $page = $this->createPage('Page exportée sans bandeau');
        $page->setTitleBand(0)->save();
        $document = $this->exportedPage($page);

        self::assertFalse($document['pages'][0]['title_band']);

        $this->forget($page);
        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame(0, $this->imported('Page exportée sans bandeau')->getTitleBand());
    }

    public function testAFileWrittenBeforeTheSettingGivesTheBand(): void
    {
        $page = $this->createPage('Page d’un ancien fichier');
        $page->setTitleBand(0)->save();
        $document = $this->exportedPage($page);
        unset($document['pages'][0]['title_band']);

        $this->forget($page);
        $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($document), new ImportOptions());

        self::assertSame(1, $this->imported('Page d’un ancien fichier')->getTitleBand());
    }

    private function published(CmsPage $page): \TheliaCMS\Page\PublishedPage
    {
        CmsPageTableMap::clearInstancePool();
        $published = $this->getService(PublishedPageRepository::class)->find((int) $page->getId(), $this->locale());
        self::assertNotNull($published);

        return $published;
    }

    private function reloaded(CmsPage $page): CmsPage
    {
        CmsPageTableMap::clearInstancePool();
        $reloaded = CmsPageQuery::create()->findPk($page->getId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function forget(CmsPage $page): void
    {
        $connection = $this->getPropelConnection();
        $connection->exec(\sprintf("DELETE FROM rewriting_url WHERE view = 'cmspage' AND view_id = %d", $page->getId()));
        $page->delete();
        CmsPageTableMap::clearInstancePool();
    }

    private function imported(string $title): CmsPage
    {
        CmsPageTableMap::clearInstancePool();
        $page = CmsPageQuery::create()->useCmsPageI18nQuery()->filterByTitle($title)->endUse()->findOne();
        self::assertNotNull($page, 'The page is imported.');

        return $page;
    }
}
