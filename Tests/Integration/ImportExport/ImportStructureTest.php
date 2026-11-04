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

use TheliaCMS\ImportExport\ExportFormat;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\CmsPageI18nQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * The shape an import gives the tree of pages and the entries of a menu.
 */
final class ImportStructureTest extends CmsIntegrationTestCase
{
    public function testAMenuReplacedByTheImportKeepsOnlyTheEntriesOfTheFile(): void
    {
        $this->import(['menus' => [$this->menu('structure-menu', ['Ancienne entrée', 'Autre ancienne entrée'])]]);

        $menu = CmsMenuQuery::findShared('structure-menu');
        self::assertNotNull($menu);
        self::assertSame(2, CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->count());

        $report = $this->import(['menus' => [$this->menu('structure-menu', ['Entrée du fichier'])]], new ImportOptions(replace: true));

        self::assertSame(1, $report->replacedCounts()['menus'] ?? null);
        $labels = [];

        foreach (CmsMenuItemQuery::create()->filterByMenuId($menu->getId())->find() as $item) {
            $labels[] = $item->setLocale($this->locale())->getLabel();
        }

        self::assertSame(['Entrée du fichier'], $labels, 'The entries the site had are not merged with the ones of the file.');
    }

    public function testImportedPagesKeepThePositionTheFileGivesThem(): void
    {
        $this->import(['pages' => [
            $this->page('Racine placée', null, 3),
            $this->page('Enfant placé en cinq', 'Racine placée', 5),
            $this->page('Enfant placé en quatre', 'Racine placée', 4),
        ]]);

        self::assertSame(3, $this->positionOf('Racine placée'));
        self::assertSame(5, $this->positionOf('Enfant placé en cinq'));
        self::assertSame(4, $this->positionOf('Enfant placé en quatre'));
    }

    /**
     * @param array<string, mixed> $sections
     */
    private function import(array $sections, ImportOptions $options = new ImportOptions()): ImportReport
    {
        return $this->getService(SiteImporter::class)->import(SiteDocument::fromArray([
            'format' => ExportFormat::NAME,
            'version' => ExportFormat::VERSION,
            'module_version' => '2.3.1',
            'locales' => [$this->locale()],
            ...$sections,
        ]), $options);
    }

    /**
     * @param list<string> $labels
     *
     * @return array<string, mixed>
     */
    private function menu(string $code, array $labels): array
    {
        $items = [];

        foreach ($labels as $position => $label) {
            $items[] = [
                'uid' => 'item-'.$position,
                'parent' => null,
                'position' => $position + 1,
                'target_type' => 'url',
                'url' => '/'.$position,
                'translations' => [$this->locale() => ['label' => $label]],
            ];
        }

        return ['code' => $code, 'translations' => [$this->locale() => ['title' => $code]], 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $title, ?string $parentTitle, int $position): array
    {
        return [
            'uid' => 'page-'.md5($title),
            'parent' => null === $parentTitle ? null : 'page-'.md5($parentTitle),
            'position' => $position,
            'visible' => true,
            'page_type' => 'default',
            'translations' => [$this->locale() => ['title' => $title, 'slug' => 'structure-'.md5($title)]],
            'contents' => [],
        ];
    }

    private function positionOf(string $title): int
    {
        $pageId = (int) CmsPageI18nQuery::create()->filterByTitle($title)->findOne()?->getId();
        $page = CmsPageQuery::create()->findPk($pageId);
        self::assertNotNull($page, \sprintf('Page "%s" was imported.', $title));

        return (int) $page->getPosition();
    }
}
