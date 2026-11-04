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

namespace TheliaCMS\ImportExport;

use Propel\Runtime\Propel;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\ImportExport\Step\CatalogueContentsStep;
use TheliaCMS\ImportExport\Step\ComponentsStep;
use TheliaCMS\ImportExport\Step\FormsStep;
use TheliaCMS\ImportExport\Step\MediaStep;
use TheliaCMS\ImportExport\Step\MenusStep;
use TheliaCMS\ImportExport\Step\PagesStep;
use TheliaCMS\ImportExport\Step\PageTypesStep;
use TheliaCMS\ImportExport\Step\SettingsStep;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Page\PageInvalidator;
use TheliaCMS\Partial\Definition\FormPartial;
use TheliaCMS\Partial\PartialCache;

/**
 * Applies an export file to this site.
 *
 * Two rules make the result predictable:
 *
 * 1. **Nothing existing is touched unless asked.** A page already at the
 *    address the file describes, a menu already carrying the code, are left
 *    alone and counted. `$replace` is what overwrites them.
 * 2. **All of it or none of it.** The whole import runs in one transaction, so
 *    a file that turns out to be broken halfway through leaves a site in the
 *    state it was, rather than half a starter kit.
 */
final readonly class SiteImporter
{
    public function __construct(
        private MediaStep $media,
        private PageTypesStep $pageTypes,
        private ComponentsStep $components,
        private PagesStep $pagesStep,
        private CatalogueContentsStep $catalogueContents,
        private MenusStep $menus,
        private FormsStep $forms,
        private SettingsStep $settingsStep,
        private SectionCatalog $catalog,
        private PartialCache $partials,
        private CachePurger $httpCache,
        private PageInvalidator $pages,
        private MenuCache $menuCache,
    ) {
    }

    public function import(SiteDocument $document, ImportOptions $options): ImportReport
    {
        $report = new ImportReport();

        foreach ($document->reparentedPages() as $uid) {
            $report->warn(\sprintf('Page "%s" refers to a parent that is not in the file: it was imported at the root of the tree.', $uid));
        }

        // A file written by 1.x holds the markup of the former editor, which
        // has no document to be read back from: the pages come in without
        // content, and everything else as before.
        if (!$document->carriesDocuments()) {
            $report->warn('This file was written by an earlier version of the module: its pages were imported without their content.');
        }

        // Blocks are no longer part of the module: a file that carries some
        // imports everything else and says what it left out.
        if ([] !== $document->blocks()) {
            $report->warn(\sprintf('This file holds %d block(s), which this version of the module no longer has: they were left out.', \count($document->blocks())));
        }

        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $mediaMap = $this->media->map($document, $report);
            $this->pageTypes->import($document, $report, $connection);
            $this->components->import($document, $options, $report);
            // The catalogue the documents are checked against is built once:
            // it has to see the components just brought in.
            $this->catalog->reset();
            $this->components->reportMissing($document, $report);
            $pageIds = $this->pagesStep->import($document, $options, $report, $mediaMap, $connection);
            $this->catalogueContents->import($document, $options, $report, $mediaMap, $connection);

            $this->menus->import($document, $options, $report, $pageIds, $connection);
            $this->forms->import($document, $options, $report, $pageIds, $connection);

            if ($options->withSettings) {
                $this->settingsStep->import($document, $report, $pageIds);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();
            $this->catalog->reset();

            throw $throwable;
        }

        // Pages, menus, forms, settings: an import may change any page of the
        // site, the one case where the whole of it is dropped.
        // The lists of pages, the sitemap and the next scheduled change.
        $this->menuCache->invalidate();
        $this->pages->listsChanged();
        $this->partials->invalidate(FormPartial::NAME);
        $this->httpCache->purge([CacheTags::SITE]);

        return $report;
    }
}
