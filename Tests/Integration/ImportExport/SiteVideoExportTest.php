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

use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\ImportExport\SiteExporter;
use TheliaCMS\ImportExport\SiteImporter;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\Mp4Videos;

/**
 * A video a page plays travels like an image does: the file names it with
 * the id it had, and the importing site points the page at its own copy,
 * matched by file name. The file itself is not carried.
 */
final class SiteVideoExportTest extends CmsIntegrationTestCase
{
    public function testTheFileNamesTheVideoAPagePlaysAndTheImportPointsThePageAtTheOneOfThisSite(): void
    {
        $video = Mp4Videos::row($this->getService(CmsMediaLibrary::class), $this->locale());
        $this->createPage('Page vidéo', html: \sprintf('<h1>Page vidéo</h1><video preload="none" src="/video-library/%d.mp4"></video>', $video->getId()));

        $file = $this->getService(SiteExporter::class)->export();

        self::assertContains(['id' => (int) $video->getId(), 'file_name' => (string) $video->getFileName()], $file['media'], 'The video is named by its file, as an image is.');

        // As written by another site, where the same file had another id.
        $elsewhere = (int) $video->getId() + 5000;
        $file['media'] = [['id' => $elsewhere, 'file_name' => (string) $video->getFileName()]];

        foreach ($file['pages'] as $index => $exported) {
            foreach ($exported['contents'] as $locale => $content) {
                $file['pages'][$index]['contents'][$locale]['published_html'] = str_replace('/video-library/'.$video->getId().'.mp4', '/video-library/'.$elsewhere.'.mp4', (string) $content['published_html']);
            }
        }

        $this->getPropelConnection()->exec('DELETE FROM cms_page_search');
        $this->getPropelConnection()->exec('DELETE FROM cms_page_revision');
        $this->getPropelConnection()->exec('DELETE FROM cms_page_content');
        $this->getPropelConnection()->exec('DELETE FROM cms_page_i18n');
        $this->getPropelConnection()->exec('DELETE FROM cms_page');
        $this->getPropelConnection()->exec("DELETE FROM rewriting_url WHERE view = 'cmspage'");

        $report = $this->getService(SiteImporter::class)->import(SiteDocument::fromArray($file), new ImportOptions());

        self::assertSame([], $report->warnings());

        $published = array_map(static fn ($content): string => (string) $content->getPublishedHtml(), iterator_to_array(CmsPageContentQuery::create()->find()));

        self::assertNotSame([], array_filter($published, static fn (string $html): bool => str_contains($html, '/video-library/'.$video->getId().'.mp4')), 'The page plays the video of this site.');
        self::assertSame([], array_filter($published, static fn (string $html): bool => str_contains($html, '/video-library/'.$elsewhere.'.mp4')), 'Not the id it had elsewhere.');
    }
}
