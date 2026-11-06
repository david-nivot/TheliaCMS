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

namespace TheliaCMS\ImportExport\Step;

use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageSearch;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Search\SearchTextExtractor;
use TheliaCMS\Zone\HeaderZone;

/**
 * The pages of a file, with their content in every language and their search entry.
 */
final readonly class PagesStep
{
    public function __construct(
        private PageTypeRepository $pageTypes,
        private PageAddresses $addresses,
        private PageTaxonomy $taxonomy,
        private ImportedContent $content,
        private SearchTextExtractor $searchText,
        private PublishedContentSanitizer $sanitizer,
        private HeaderZone $zones,
    ) {
    }

    /**
     * @param array<int, int> $mediaMap
     *
     * @return array<string, int> page id here, by uid in the file
     */
    public function import(
        SiteDocument $document,
        ImportOptions $options,
        ImportReport $report,
        array $mediaMap,
        ConnectionInterface $connection,
    ): array {
        $pageIds = [];
        $knownTypes = $this->pageTypes->codes($connection);
        $zoneImported = false;

        foreach ($document->pages() as $page) {
            $uid = (string) ($page['uid'] ?? '');
            $translations = $page['translations'] ?? [];

            if ([] === $translations) {
                $report->skipped('pages');

                continue;
            }

            $isZone = true === ($page['zone'] ?? false);
            $demotedZone = false;

            // The site has one zone: a second in the file comes in as an
            // ordinary draft page, as a second model does.
            if ($isZone && $zoneImported) {
                $report->warn(\sprintf('Page "%s" is a header zone, and the file holds another one: it was imported as an ordinary draft page.', $uid));
                $isZone = false;
                $demotedZone = true;
            }

            $parentUid = null === ($page['parent'] ?? null) ? null : (string) $page['parent'];
            $parentId = !$isZone && null !== $parentUid ? ($pageIds[$parentUid] ?? 0) : 0;

            // The zone has no address to be found by: it is the page of the
            // site that is one, whatever the file calls it.
            $existing = $isZone ? $this->zones->find($connection) : $this->addresses->existing($translations, $parentId);
            $zoneImported = $zoneImported || $isZone;

            if (null !== $existing && !$options->replace) {
                $pageIds[$uid] = (int) $existing->getId();
                $report->skipped('pages');

                continue;
            }

            $model = $existing ?? new CmsPage();
            $isModel = $this->taxonomy->isModel($page, $existing, $report, $connection);
            $model
                ->setParent($parentId)
                ->setPosition($isZone ? 0 : (int) ($page['position'] ?? 0))
                // The model of a type, the header zone and a zone left over are
                // never online as pages, whatever the file says.
                ->setVisible(!$isModel && !$isZone && !$demotedZone && ($page['visible'] ?? true) ? 1 : 0)
                ->setPageType($this->taxonomy->pageTypeOf($page, $report, $connection, $knownTypes))
                ->setModel($isModel ? 1 : ($isZone ? HeaderZone::MODEL : 0))
                // A file written before the setting existed keeps what the page has, or the band.
                ->setTitleBand(\array_key_exists('title_band', $page) ? (($page['title_band'] ?? true) ? 1 : 0) : ($existing?->getTitleBand() ?? 1))
                ->setFields(self::fieldsJson($page['fields'] ?? null))
                ->setTagId($this->taxonomy->tagIdOf($page, $report, $connection))
                ->setImageId($this->mappedImage($page['image_id'] ?? null, $mediaMap))
                ->setPublishAt(ImportedDate::parse($page['publish_at'] ?? null))
                ->setUnpublishAt(ImportedDate::parse($page['unpublish_at'] ?? null));

            foreach ($translations as $locale => $translation) {
                $model->setLocale((string) $locale)
                    ->setTitle((string) ($translation['title'] ?? ''))
                    ->setChapo($this->summaryHtml($translation['chapo'] ?? null, $options))
                    ->setDescription($this->summaryHtml($translation['description'] ?? null, $options))
                    ->setFixedUrl(\is_string($translation['fixed_url'] ?? null) && '' !== trim($translation['fixed_url']) ? trim($translation['fixed_url']) : null)
                    ->setMetaTitle($translation['meta_title'] ?? null)
                    ->setMetaDescription($translation['meta_description'] ?? null)
                    ->setOgTitle($translation['og_title'] ?? null)
                    ->setOgDescription($translation['og_description'] ?? null)
                    ->setOgImageId($this->mappedImage($translation['og_image_id'] ?? null, $mediaMap))
                    ->setTwitterCard($translation['twitter_card'] ?? null)
                    ->setCanonical($translation['canonical'] ?? null)
                    ->setNoindex(($translation['noindex'] ?? false) ? 1 : 0)
                    ->setNofollow(($translation['nofollow'] ?? false) ? 1 : 0)
                    ->setTranslatedFields(self::fieldsJson($translation['fields'] ?? null));
            }

            $model->save($connection);
            $pageIds[$uid] = (int) $model->getId();

            $this->importPageContents($model, $document->carriesDocuments() ? $page['contents'] ?? [] : [], $mediaMap, $options, $report, $connection, $demotedZone);

            // The zone has no address: it is drawn in a header, not served.
            foreach ($isZone ? [] : $translations as $locale => $translation) {
                $this->addresses->refresh($model, (string) $locale, $translation['slug'] ?? null, $report);
            }

            null !== $existing ? $report->replaced('pages') : $report->created('pages');
        }

        return $pageIds;
    }

    /**
     * Values of fields as the file carries them, stored as they are: they
     * are read through the definition of the type, which leaves out what it
     * does not take.
     */
    private static function fieldsJson(mixed $values): ?string
    {
        if (!\is_array($values) || [] === $values || array_is_list($values)) {
            return null;
        }

        return json_encode($values, \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $contents
     * @param array<int, int>      $mediaMap
     */
    private function importPageContents(CmsPage $page, array $contents, array $mediaMap, ImportOptions $options, ImportReport $report, ConnectionInterface $connection, bool $withoutPublished = false): void
    {
        foreach ($contents as $locale => $content) {
            $locale = (string) $locale;
            $where = \sprintf('page "%s" in %s', $page->setLocale($locale)->getTitle(), $locale);

            $model = CmsPageContentQuery::create()
                ->filterByPageId($page->getId())
                ->filterByLocale($locale)
                ->findOne()
                ?? (new CmsPageContent())->setPageId($page->getId())->setLocale($locale);

            // The model of a type is never published: a version the file
            // gives it, edited by hand or written elsewhere, is left out.
            // Nor is a zone left over, imported as a draft page.
            $published = $withoutPublished || 1 === $page->getModel() ? [] : $content;
            $publishedHtml = $this->content->html($published['published_html'] ?? null, $mediaMap, $options, $report, 'The published version of '.$where);

            $model
                ->setDraftDocument($this->content->document($content['draft_document'] ?? null, $mediaMap, $report, 'The draft of '.$where))
                ->setDraftHtml($this->content->html($content['draft_html'] ?? null, $mediaMap, $options, $report, 'The draft of '.$where))
                ->setDraftProvenance(ProvenanceMark::normalize($content['draft_provenance'] ?? null))
                ->setPublishedDocument($this->content->document($published['published_document'] ?? null, $mediaMap, $report, 'The published version of '.$where))
                ->setPublishedHtml($publishedHtml)
                ->setPublishedProvenance(ProvenanceMark::normalize($published['published_provenance'] ?? null))
                ->setPublishedAt(ImportedDate::parse($published['published_at'] ?? null));

            // The back office reads "edited since it went live" from this
            // column against the publication date. Saving without setting it
            // stamps it with the time of the import, and every page of a
            // restored site then asks to be published again.
            $model->setUpdatedAt(
                ImportedDate::parse($content['updated_at'] ?? null)
                ?? $model->getPublishedAt()
                ?? new \DateTimeImmutable(),
            );

            $model->save($connection);

            // Without this, an imported site answers nothing at all on its own
            // search page: the index is built at publish time, and importing is
            // not publishing.
            // The zone is in no search: it is no page of the site.
            if (null !== $model->getPublishedAt() && HeaderZone::MODEL !== $page->getModel()) {
                $this->index($page, $locale, $publishedHtml, $connection);
            }
        }
    }

    private function index(CmsPage $page, string $locale, ?string $html, ConnectionInterface $connection): void
    {
        $index = CmsPageSearchQuery::create()
            ->filterByPageId($page->getId())
            ->filterByLocale($locale)
            ->findOne()
            ?? (new CmsPageSearch())->setPageId($page->getId())->setLocale($locale);

        $page->setLocale($locale);

        $index->setContent(trim($page->getTitle().' '.$this->searchText->extract($html)))->save($connection);
    }

    /**
     * @param array<int, int> $mediaMap
     */
    private function mappedImage(mixed $imageId, array $mediaMap): ?int
    {
        if (null === $imageId) {
            return null;
        }

        return $mediaMap[(int) $imageId] ?? null;
    }

    /**
     * The summary and the description of a page are printed by the theme as
     * they are. A file is not a trusted author either: nothing in it gets past
     * the filter an editor without the custom code right is held to, unless
     * the import is allowed custom code.
     */
    private function summaryHtml(mixed $html, ImportOptions $options): ?string
    {
        return \is_string($html) ? $this->sanitizer->html($html, $options->allowCustomCode) : null;
    }
}
