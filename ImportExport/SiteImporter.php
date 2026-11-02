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

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ContentQuery;
use Thelia\Model\FolderQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Catalogue\CatalogueItemKeys;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\DealerStores;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Form\FieldChoices;
use TheliaCMS\Form\FormCode;
use TheliaCMS\Form\Recipients;
use TheliaCMS\Form\RetentionPolicy;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Menu\MenuCache;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormField;
use TheliaCMS\Model\CmsFormFieldQuery;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsItemContent;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItem;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageSearch;
use TheliaCMS\Model\CmsPageSearchQuery;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Page\PageTagName;
use TheliaCMS\Page\PageTagWriter;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Partial\Definition\FormPartial;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Partial\PartialCache;
use TheliaCMS\Search\SearchTextExtractor;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteMode;
use TheliaCMS\TheliaCMS;
use TheliaLibrary\Model\LibraryImageQuery;

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
        private CmsUrlService $urls,
        private MediaReferences $media,
        private SearchTextExtractor $searchText,
        private CmsSettings $settings,
        private MenuCache $menuCache,
        private PageTypeRepository $pageTypes,
        private PageTypeWriter $pageTypeWriter,
        private PublishedContentSanitizer $sanitizer,
        private PageTagWriter $tagWriter,
        private DocumentValidator $documentValidator,
        private SectionCatalog $catalog,
        private PartialCache $partials,
        private CachePurger $httpCache,
        private ?SiteComponentRepository $components = null,
        private SiteComponentValidator $componentValidator = new SiteComponentValidator(),
        private CatalogueItemKeys $itemKeys = new CatalogueItemKeys(),
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
            $mediaMap = $this->mediaMap($document, $report);
            $this->importPageTypes($document, $report, $connection);
            $this->importComponents($document, $options, $report);
            // The catalogue the documents are checked against is built once:
            // it has to see the components just brought in.
            $this->catalog->reset();
            $this->reportMissingComponents($document, $report);
            $pageIds = $this->importPages($document, $options, $report, $mediaMap, $connection);
            $this->importCatalogueContents($document, $options, $report, $mediaMap, $connection);

            $this->importMenus($document, $options, $report, $pageIds, $connection);
            $this->importForms($document, $options, $report, $pageIds, $connection);

            if ($options->withSettings) {
                $this->importSettings($document, $report, $pageIds);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();
            $this->catalog->reset();

            throw $throwable;
        }

        // Pages, menus, forms, settings: an import may change any page of the
        // site, the one case where the whole of it is dropped.
        $this->menuCache->invalidate();
        $this->partials->invalidate(PageListPartial::NAME);
        $this->partials->invalidate(FormPartial::NAME);
        $this->httpCache->purge([CacheTags::SITE]);

        return $report;
    }

    /**
     * Matches the images of the file with the ones already here, by file name.
     *
     * @return array<int, int>
     */
    private function mediaMap(SiteDocument $document, ImportReport $report): array
    {
        $map = [];
        $missing = [];

        foreach ($document->media() as $id => $fileName) {
            $image = LibraryImageQuery::create()->findOneByFileName($fileName);

            if (null === $image) {
                $missing[] = $fileName;

                continue;
            }

            $map[$id] = (int) $image->getId();
        }

        if ([] !== $missing) {
            $report->warn(\sprintf(
                'These images are not in the media library of this site, and the content pointing at them will show a broken image until they are uploaded: %s',
                implode(', ', $missing),
            ));
        }

        return $map;
    }

    /**
     * @param array<int, int> $mediaMap
     *
     * @return array<string, int> page id here, by uid in the file
     */
    private function importPages(
        SiteDocument $document,
        ImportOptions $options,
        ImportReport $report,
        array $mediaMap,
        ConnectionInterface $connection,
    ): array {
        $pageIds = [];
        $knownTypes = $this->pageTypes->codes($connection);

        foreach ($document->pages() as $page) {
            $uid = (string) ($page['uid'] ?? '');
            $translations = $page['translations'] ?? [];

            if ([] === $translations) {
                $report->skipped('pages');

                continue;
            }

            $parentUid = null === ($page['parent'] ?? null) ? null : (string) $page['parent'];
            $parentId = null !== $parentUid ? ($pageIds[$parentUid] ?? 0) : 0;

            $existing = $this->existingPage($translations, $parentId);

            if (null !== $existing && !$options->replace) {
                $pageIds[$uid] = (int) $existing->getId();
                $report->skipped('pages');

                continue;
            }

            $model = $existing ?? new CmsPage();
            $isModel = $this->isModel($page, $existing, $report, $connection);
            $model
                ->setParent($parentId)
                ->setPosition((int) ($page['position'] ?? 0))
                // The model of a type is never online, whatever the file says.
                ->setVisible(!$isModel && ($page['visible'] ?? true) ? 1 : 0)
                ->setPageType($this->pageTypeOf($page, $report, $connection, $knownTypes))
                ->setModel($isModel ? 1 : 0)
                ->setFields(self::fieldsJson($page['fields'] ?? null))
                ->setTagId($this->tagIdOf($page, $report, $connection))
                ->setImageId($this->mappedImage($page['image_id'] ?? null, $mediaMap))
                ->setPublishAt($this->date($page['publish_at'] ?? null))
                ->setUnpublishAt($this->date($page['unpublish_at'] ?? null));

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

            $this->importPageContents($model, $document->carriesDocuments() ? $page['contents'] ?? [] : [], $mediaMap, $options, $report, $connection);

            foreach ($translations as $locale => $translation) {
                $this->refreshUrl($model, (string) $locale, $translation['slug'] ?? null, $report);
            }

            null !== $existing ? $report->replaced('pages') : $report->created('pages');
        }

        return $pageIds;
    }

    /**
     * The types the file describes as templates. A type the site lacks, or
     * has as a bare code, takes the description of the file; a type the site
     * has described already keeps its own, and the report says so when the
     * two differ: the fields of a type are what its pages hold values under.
     */
    private function importPageTypes(SiteDocument $document, ImportReport $report, ConnectionInterface $connection): void
    {
        foreach ($document->pageTypes() as $row) {
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';

            if (!PageTypeCode::isValid($code)) {
                $report->warn('A page type that is not a valid code was left out.');

                continue;
            }

            try {
                $fields = FieldDefinitions::fromArray(\is_array($row['fields'] ?? null) ? $row['fields'] : []);
            } catch (InvalidFieldsException $exception) {
                $report->warn(\sprintf('The fields of the page type "%s" were left out: %s', $code, $exception->getMessage()));
                $fields = FieldDefinitions::none();
            }

            $current = $this->pageTypes->details($code, $connection);

            if ($current->isTemplate()) {
                if ($current->fields()->toArray() !== $fields->toArray()) {
                    $report->warn(\sprintf('Page type "%s" already has fields on this site: they were kept, and the ones of the file left out.', $code));
                }

                continue;
            }

            $briefText = \is_string($row['brief'] ?? null) && '' !== trim($row['brief']) ? trim($row['brief']) : null;
            $brief = null === $briefText ? null : new Brief($briefText, self::stringOrNull($row['brief_tone'] ?? null), self::stringOrNull($row['brief_audience'] ?? null));
            $translations = \is_array($row['translations'] ?? null) ? $row['translations'] : [];

            if ([] === $translations) {
                $translations = [Lang::getDefaultLanguage()->getLocale() => []];
            }

            foreach ($translations as $locale => $translation) {
                $this->pageTypeWriter->describe(
                    $code,
                    (string) $locale,
                    self::stringOrNull($translation['title'] ?? null),
                    self::stringOrNull($translation['description'] ?? null),
                    $brief,
                    $fields,
                    $connection,
                    self::stringOrNull($row['instructions'] ?? null),
                );
            }
        }
    }

    /**
     * The components the site made, checked as if the model had just written
     * them: a file is no reason to trust a template. One the site already has
     * under the same code is kept unless the import replaces, and then only
     * when the two differ.
     */
    private function importComponents(SiteDocument $document, ImportOptions $options, ImportReport $report): void
    {
        if (null === $this->components) {
            return;
        }

        foreach ($document->components() as $row) {
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';

            try {
                $component = $this->componentValidator->validate(new SiteComponent(
                    $code,
                    \is_string($row['label'] ?? null) ? $row['label'] : '',
                    \is_string($row['description'] ?? null) ? $row['description'] : '',
                    \is_array($row['props'] ?? null) && array_is_list($row['props']) ? array_values(array_filter($row['props'], 'is_array')) : [],
                    \is_array($row['sample'] ?? null) ? $row['sample'] : [],
                    \is_string($row['template'] ?? null) ? $row['template'] : '',
                    \is_string($row['css'] ?? null) ? $row['css'] : '',
                    ProvenanceMark::normalize($row['provenance'] ?? null),
                ));
            } catch (InvalidSiteComponentException $exception) {
                $report->warn(\sprintf('The component "%s" was left out: %s', $code, implode(' ', $exception->problems)));

                continue;
            }

            // A file written before prototypes existed says nothing: what it
            // holds was kept for the whole site.
            $status = SiteComponentRepository::STATUS_DRAFT === ($row['status'] ?? null) ? SiteComponentRepository::STATUS_DRAFT : SiteComponentRepository::STATUS_READY;
            $existing = $this->components->find($component->code);

            if (null === $existing) {
                $this->components->save($component, $status);
                $report->created('components');

                continue;
            }

            if ($existing == $component || !$options->replace) {
                if ($existing != $component) {
                    $report->warn(\sprintf('The component "%s" of this site differs from the one of the file: the one of the site was kept.', $component->code));
                }

                $report->skipped('components');

                continue;
            }

            $this->components->save($component, $status, $component->code);
            $report->replaced('components');
        }
    }

    /**
     * A page of the file made of a component of a site the file does not
     * carry and this site does not have: it comes in with the HTML of the
     * file, without the styles of that component, and cannot be saved again
     * until such a component is made here.
     */
    private function reportMissingComponents(SiteDocument $document, ImportReport $report): void
    {
        if (null === $this->components) {
            return;
        }

        $missing = [];

        foreach ($document->pages() as $page) {
            foreach (\is_array($page['contents'] ?? null) ? $page['contents'] : [] as $content) {
                foreach (['draft_document', 'published_document'] as $column) {
                    $decoded = \is_array($content) && \is_string($content[$column] ?? null) ? json_decode($content[$column], true) : null;

                    foreach (\is_array($decoded) && \is_array($decoded['sections'] ?? null) ? $decoded['sections'] : [] as $section) {
                        $name = \is_array($section) && \is_string($section['component'] ?? null) ? $section['component'] : '';

                        if (str_starts_with($name, SiteComponent::PREFIX) && null === $this->components->find(substr($name, \strlen(SiteComponent::PREFIX)))) {
                            $missing[$name] = true;
                        }
                    }
                }
            }
        }

        foreach (array_keys($missing) as $name) {
            $report->warn(\sprintf('Pages of the file are made of the component "%s", which neither the file nor this site has: they keep the HTML of the file, without its styles, and cannot be saved until it is made here.', $name));
        }
    }

    /**
     * Whether an imported page is the model of its type. A type has one: a
     * model the site has already keeps the place, and the page of the file
     * comes in as an ordinary draft.
     *
     * @param array<string, mixed> $page
     */
    private function isModel(array $page, ?CmsPage $existing, ImportReport $report, ConnectionInterface $connection): bool
    {
        if (true !== ($page['model'] ?? false)) {
            return false;
        }

        $model = $this->pageTypes->modelOf(PageTypeCode::orDefault(\is_string($page['page_type'] ?? null) ? $page['page_type'] : null), $connection);

        if (null === $model || (null !== $existing && (int) $model->getId() === (int) $existing->getId())) {
            return true;
        }

        $report->warn(\sprintf('Page "%s" is the model of its type, which already has one here: it was imported as an ordinary page.', (string) ($page['uid'] ?? '')));

        return false;
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

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $contents
     * @param array<int, int>      $mediaMap
     */
    private function importPageContents(CmsPage $page, array $contents, array $mediaMap, ImportOptions $options, ImportReport $report, ConnectionInterface $connection): void
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
            $published = 1 === $page->getModel() ? [] : $content;
            $publishedHtml = $this->importedHtml($published['published_html'] ?? null, $mediaMap, $options, $report, 'The published version of '.$where);

            $model
                ->setDraftDocument($this->importedDocument($content['draft_document'] ?? null, $mediaMap, $report, 'The draft of '.$where))
                ->setDraftHtml($this->importedHtml($content['draft_html'] ?? null, $mediaMap, $options, $report, 'The draft of '.$where))
                ->setDraftProvenance(ProvenanceMark::normalize($content['draft_provenance'] ?? null))
                ->setPublishedDocument($this->importedDocument($published['published_document'] ?? null, $mediaMap, $report, 'The published version of '.$where))
                ->setPublishedHtml($publishedHtml)
                ->setPublishedProvenance(ProvenanceMark::normalize($published['published_provenance'] ?? null))
                ->setPublishedAt($this->date($published['published_at'] ?? null));

            // The back office reads "edited since it went live" from this
            // column against the publication date. Saving without setting it
            // stamps it with the time of the import, and every page of a
            // restored site then asks to be published again.
            $model->setUpdatedAt(
                $this->date($content['updated_at'] ?? null)
                ?? $model->getPublishedAt()
                ?? new \DateTimeImmutable(),
            );

            $model->save($connection);

            // Without this, an imported site answers nothing at all on its own
            // search page: the index is built at publish time, and importing is
            // not publishing.
            if (null !== $model->getPublishedAt()) {
                $this->index($page, $locale, $publishedHtml, $connection);
            }
        }
    }

    /**
     * The documents of the catalogue items, each put on the item its key names
     * here. An item this site does not have is said, not created: the module
     * writes no product, category, content or folder.
     *
     * @param array<int, int> $mediaMap
     */
    private function importCatalogueContents(SiteDocument $document, ImportOptions $options, ImportReport $report, array $mediaMap, ConnectionInterface $connection): void
    {
        foreach ($document->catalogueContents() as $entry) {
            $type = CatalogueItemType::tryFrom((string) ($entry['type'] ?? ''));
            $key = \is_array($entry['key'] ?? null) ? $entry['key'] : [];
            $id = null === $type ? null : $this->itemKeys->find($type, $key);

            if (null === $type || null === $id) {
                $report->warn(\sprintf(
                    'The CMS content of the %s %s was left out: this site has no such item.',
                    (string) ($entry['type'] ?? '?'),
                    self::describeKey($key),
                ));
                $report->skipped('catalogue contents');

                continue;
            }

            $existing = CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->count($connection);

            if ($existing > 0 && !$options->replace) {
                $report->skipped('catalogue contents');

                continue;
            }

            foreach (\is_array($entry['contents'] ?? null) ? $entry['contents'] : [] as $locale => $content) {
                if (!\is_array($content)) {
                    continue;
                }

                $locale = (string) $locale;
                $where = \sprintf('the CMS content of the %s %s in %s', $type->value, self::describeKey($key), $locale);
                $model = CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->filterByLocale($locale)->findOne($connection)
                    ?? (new CmsItemContent())->setItemType($type->value)->setItemId($id)->setLocale($locale);

                $model
                    ->setDraftDocument($this->importedDocument($content['draft_document'] ?? null, $mediaMap, $report, 'The draft of '.$where))
                    ->setDraftHtml($this->importedHtml($content['draft_html'] ?? null, $mediaMap, $options, $report, 'The draft of '.$where))
                    ->setDraftProvenance(ProvenanceMark::normalize($content['draft_provenance'] ?? null))
                    ->setPublishedDocument($this->importedDocument($content['published_document'] ?? null, $mediaMap, $report, 'The published version of '.$where))
                    ->setPublishedHtml($this->importedHtml($content['published_html'] ?? null, $mediaMap, $options, $report, 'The published version of '.$where))
                    ->setPublishedProvenance(ProvenanceMark::normalize($content['published_provenance'] ?? null))
                    ->setPublishedAt($this->date($content['published_at'] ?? null));

                // As for a page: kept, or every imported document would read
                // "modified since publication".
                $model->setUpdatedAt($this->date($content['updated_at'] ?? null) ?? $model->getPublishedAt() ?? new \DateTimeImmutable());
                $model->save($connection);
            }

            $existing > 0 ? $report->replaced('catalogue contents') : $report->created('catalogue contents');
        }
    }

    /**
     * @param array<string, mixed> $key
     */
    private static function describeKey(array $key): string
    {
        if (\is_string($key['reference'] ?? null) && '' !== $key['reference']) {
            return \sprintf('with the reference "%s"', $key['reference']);
        }

        $urls = \is_array($key['urls'] ?? null) ? array_filter($key['urls'], 'is_string') : [];

        return [] === $urls ? 'with no reference nor address' : \sprintf('at "%s"', reset($urls));
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
     * @param array<string, int> $pageIds
     */
    private function importMenus(
        SiteDocument $document,
        ImportOptions $options,
        ImportReport $report,
        array $pageIds,
        ConnectionInterface $connection,
    ): void {
        $menus = $document->menus();
        // The shared menus first, whatever order the file is in: the menu of
        // a language is only taken in beside the shared menu of its code.
        usort($menus, static fn (array $a, array $b): int => (CmsMenuQuery::SHARED === self::menuLocale($b)) <=> (CmsMenuQuery::SHARED === self::menuLocale($a)));

        foreach ($menus as $menu) {
            $code = trim((string) ($menu['code'] ?? ''));

            if ('' === $code) {
                $report->skipped('menus');

                continue;
            }

            $menuLocale = self::menuLocale($menu);

            if (CmsMenuQuery::SHARED !== $menuLocale) {
                if (!LangQuery::create()->filterByLocale($menuLocale)->exists($connection)) {
                    $report->warn(\sprintf('The menu "%s" of the language %s was left out: this site has no such language.', $code, $menuLocale));
                    $report->skipped('menus');

                    continue;
                }

                // Nothing on this site would list it, and nothing would remove
                // it with the menu of its code.
                if (null === CmsMenuQuery::findShared($code, $connection)) {
                    $report->warn(\sprintf('The menu "%s" of the language %s was left out: neither the file nor this site has the shared menu of that code.', $code, $menuLocale));
                    $report->skipped('menus');

                    continue;
                }
            }

            $existing = CmsMenuQuery::findForLocale($code, $menuLocale, $connection);

            if (null !== $existing && !$options->replace) {
                $report->skipped('menus');

                continue;
            }

            $model = $existing ?? (new CmsMenu())->setCode($code)->setMenuLocale($menuLocale);

            foreach ($menu['translations'] ?? [] as $locale => $translation) {
                $model->setLocale((string) $locale)->setTitle((string) ($translation['title'] ?? $code));
            }

            $model->save($connection);

            // The entries of a replaced menu are rebuilt rather than merged:
            // merging two trees by position produces an order nobody asked for.
            CmsMenuItemQuery::create()->filterByMenuId($model->getId())->delete($connection);

            $itemIds = [];

            foreach ($menu['items'] ?? [] as $item) {
                $targetType = (string) ($item['target_type'] ?? 'page');
                $targetId = $this->menuTarget($item, $targetType, $pageIds, $report, $connection);

                if ('page' === $targetType && null === $targetId) {
                    $report->skipped('menu entries');

                    continue;
                }

                $parentUid = null === ($item['parent'] ?? null) ? null : (string) $item['parent'];

                $entry = (new CmsMenuItem())
                    ->setMenuId($model->getId())
                    ->setParent(null !== $parentUid ? ($itemIds[$parentUid] ?? 0) : 0)
                    ->setPosition((int) ($item['position'] ?? 0))
                    ->setTargetType($targetType)
                    ->setTargetId($targetId)
                    ->setUrl($item['url'] ?? null)
                    ->setOpenNewTab(($item['open_new_tab'] ?? false) ? 1 : 0);

                foreach ($item['translations'] ?? [] as $locale => $translation) {
                    $entry->setLocale((string) $locale)->setLabel($translation['label'] ?? null);
                }

                $entry->save($connection);
                $itemIds[(string) ($item['uid'] ?? '')] = (int) $entry->getId();
            }

            null !== $existing ? $report->replaced('menus') : $report->created('menus');

            if (null !== $existing && CmsMenuQuery::SHARED === $menuLocale) {
                $this->warnAboutKeptMenus($code, $menus, $report, $connection);
            }
        }
    }

    /**
     * A shared menu replaced by the file does not reach the languages that
     * have a menu of their own, unless the file carries that language too.
     *
     * @param list<array<mixed>> $menus
     */
    private function warnAboutKeptMenus(string $code, array $menus, ImportReport $report, ConnectionInterface $connection): void
    {
        $inFile = array_map(
            static fn (array $menu): string => self::menuLocale($menu),
            array_filter($menus, static fn (array $menu): bool => trim((string) ($menu['code'] ?? '')) === $code),
        );

        $kept = array_values(array_diff(array_map(
            'strval',
            CmsMenuQuery::create()
                ->filterByCode($code)
                ->filterByMenuLocale(CmsMenuQuery::SHARED, Criteria::NOT_EQUAL)
                ->orderByMenuLocale()
                ->select(['MenuLocale'])
                ->find($connection)
                ->toArray(),
        ), $inFile));

        if ([] !== $kept) {
            $report->warn(\sprintf('The shared menu "%s" was replaced, but the language(s) %s keep a menu of their own that the file does not carry.', $code, implode(', ', $kept)));
        }
    }

    /**
     * A file written before languages could have a menu of their own has no
     * key: its menus are the shared ones.
     *
     * @param array<mixed> $menu
     */
    private static function menuLocale(array $menu): string
    {
        $locale = $menu['locale'] ?? null;

        return \is_string($locale) ? trim($locale) : CmsMenuQuery::SHARED;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, int>   $pageIds
     */
    private function menuTarget(array $item, string $targetType, array $pageIds, ImportReport $report, ConnectionInterface $connection): ?int
    {
        if ('page' !== $targetType) {
            $rawId = $item['target_id'] ?? null;
            $catalogueType = CatalogueItemType::tryFrom($targetType);

            // The entry is kept either way: the menu screen lists it with the
            // reason it is not shown, and the item may be created afterwards.
            if (null !== $rawId && null !== $catalogueType && !self::catalogueItemExists($catalogueType, (int) $rawId, $connection)) {
                $report->warn(\sprintf(
                    'A menu entry points at a %s of the catalogue by its number (%d), which this site does not have: the entry was kept, and the menu screen lists it as having no target.',
                    $targetType,
                    (int) $rawId,
                ));
            } elseif (null !== $rawId) {
                $report->warn(\sprintf(
                    'A menu entry points at a %s of the catalogue by its number (%d): check that it is the right one on this site.',
                    $targetType,
                    (int) $rawId,
                ));
            }

            return null !== $rawId ? (int) $rawId : null;
        }

        $uid = $item['target'] ?? null;

        if (null === $uid) {
            return null;
        }

        $pageId = $pageIds[(string) $uid] ?? null;

        if (null === $pageId) {
            $report->warn(\sprintf('A menu entry points at page "%s", which is not in the file: the entry was left out.', (string) $uid));
        }

        return $pageId;
    }

    private static function catalogueItemExists(CatalogueItemType $type, int $id, ConnectionInterface $connection): bool
    {
        return match ($type) {
            CatalogueItemType::Product => null !== ProductQuery::create()->findPk($id, $connection),
            CatalogueItemType::Category => null !== CategoryQuery::create()->findPk($id, $connection),
            CatalogueItemType::Content => null !== ContentQuery::create()->findPk($id, $connection),
            CatalogueItemType::Folder => null !== FolderQuery::create()->findPk($id, $connection),
            CatalogueItemType::Dealer => null !== DealerStores::find($id, null, $connection),
        };
    }

    /**
     * @param array<string, int> $pageIds
     */
    private function importForms(
        SiteDocument $document,
        ImportOptions $options,
        ImportReport $report,
        array $pageIds,
        ConnectionInterface $connection,
    ): void {
        foreach ($document->forms() as $form) {
            $code = trim((string) ($form['code'] ?? ''));

            if ('' === $code) {
                $report->skipped('forms');

                continue;
            }

            // A page prints the code in the script that reports a sent form.
            if (!FormCode::isValid($code)) {
                $report->warn(\sprintf('A form whose code "%s" is not lowercase letters, digits and dashes was left out.', mb_substr($code, 0, FormCode::MAX_LENGTH)));
                $report->skipped('forms');

                continue;
            }

            $existing = CmsFormQuery::create()->findOneByCode($code);

            if (null !== $existing && !$options->replace) {
                $report->skipped('forms');

                continue;
            }

            $model = $existing ?? (new CmsForm())->setCode($code);
            $privacyUid = $form['privacy_policy_page'] ?? null;

            $model
                ->setActive(($form['active'] ?? true) ? 1 : 0)
                ->setRecipients(self::recipients($code, $form['recipients'] ?? null, $report))
                ->setStoreSubmissions(($form['store_submissions'] ?? true) ? 1 : 0)
                ->setRetentionDays(self::retentionDays($code, $form['retention_days'] ?? null, $report))
                ->setSendReceipt(($form['send_receipt'] ?? false) ? 1 : 0)
                ->setLeadEvent(($form['lead_event'] ?? true) ? 1 : 0)
                ->setPrivacyPolicyPageId(null !== $privacyUid ? ($pageIds[(string) $privacyUid] ?? null) : null)
                ->setDeletedAt(null);

            foreach ($form['translations'] ?? [] as $locale => $translation) {
                $model->setLocale((string) $locale)
                    ->setTitle((string) ($translation['title'] ?? $code))
                    ->setDescription($translation['description'] ?? null)
                    ->setSubmitLabel($translation['submit_label'] ?? null)
                    ->setSuccessMessage($translation['success_message'] ?? null)
                    ->setLegalNotice($translation['legal_notice'] ?? null)
                    ->setReceiptSubject($translation['receipt_subject'] ?? null)
                    ->setReceiptBody($translation['receipt_body'] ?? null);
            }

            $model->save($connection);

            // Same reasoning as the menu entries: the fields of a form are its
            // shape, and half of an old shape mixed with half of a new one is
            // not a form anyone designed.
            CmsFormFieldQuery::create()->filterByFormId($model->getId())->delete($connection);

            foreach ($form['fields'] ?? [] as $field) {
                $fieldModel = (new CmsFormField())
                    ->setFormId($model->getId())
                    ->setName((string) ($field['name'] ?? ''))
                    ->setType((string) ($field['type'] ?? 'text'))
                    ->setPosition((int) ($field['position'] ?? 0))
                    ->setRequired(($field['required'] ?? false) ? 1 : 0)
                    ->setOptions($field['options'] ?? null);

                foreach ($field['translations'] ?? [] as $locale => $translation) {
                    $fieldModel->setLocale((string) $locale)
                        ->setLabel($translation['label'] ?? null)
                        ->setPlaceholder($translation['placeholder'] ?? null)
                        ->setHelp($translation['help'] ?? null)
                        ->setChoices(FieldChoices::fromExport($translation['choices'] ?? null));
                }

                $fieldModel->save($connection);
            }

            null !== $existing ? $report->replaced('forms') : $report->created('forms');
        }
    }

    /**
     * The addresses of the file that are addresses: the back office refuses
     * the others, and a form sending to them sends nowhere.
     */
    private static function recipients(string $code, mixed $stored, ImportReport $report): ?string
    {
        if (!\is_string($stored)) {
            return null;
        }

        $rejected = Recipients::rejected($stored);

        if ([] !== $rejected) {
            $report->warn(\sprintf('Form "%s" was imported without the recipients that are not an email address: %s', $code, implode(', ', $rejected)));
        }

        $addresses = Recipients::parse($stored);

        return [] === $addresses ? null : Recipients::toStorage($addresses);
    }

    /**
     * Read as the form screen reads it: a negative duration, or one that is
     * not a number at all, would keep the answers forever.
     */
    private static function retentionDays(string $code, mixed $stored, ImportReport $report): int
    {
        $days = \is_int($stored) || (\is_string($stored) && 1 === preg_match('/^-?\d+$/', $stored)) ? (int) $stored : null;
        $kept = RetentionPolicy::normalize($days);

        if (null !== $stored && $kept !== $days) {
            $report->warn(\sprintf('The answers of form "%s" are kept %d days: the duration of the file is not one the form screen accepts.', $code, $kept));
        }

        return $kept;
    }

    /**
     * @param array<string, int> $pageIds
     */
    private function importSettings(SiteDocument $document, ImportReport $report, array $pageIds): void
    {
        $settings = $document->settings();

        if ([] === $settings) {
            return;
        }

        $page = static fn (?string $uid): ?int => null !== $uid ? ($pageIds[$uid] ?? null) : null;

        $homePageId = $page($settings['home_page'] ?? null);

        $this->settings->save(
            SiteMode::fromStorage($settings['site_mode'] ?? null),
            $page($settings['404_page'] ?? null),
            $this->settings->isMaintenanceActive(),
            implode("\n", $this->settings->maintenanceAllowlist()),
            $page($settings['maintenance_page'] ?? null),
            (int) ($settings['trash_retention_days'] ?? 30),
            (int) ($settings['http_cache_ttl'] ?? 0),
        );

        TheliaCMS::setConfigValue('home_page_id', (string) ($homePageId ?? ''));

        $report->created('settings');
    }

    /**
     * The page already published at the address this document describes, if any.
     *
     * Identity is the address rather than the title: two pages of the same site
     * may well be called "Contact", and none of them may be at the same place
     * in the tree.
     *
     * @param array<string, mixed> $translations
     */
    private function existingPage(array $translations, int $parentId): ?CmsPage
    {
        foreach ($translations as $locale => $translation) {
            $slug = trim((string) ($translation['slug'] ?? ''));
            $fixed = trim((string) ($translation['fixed_url'] ?? ''), ' /');

            if ('' === $slug && '' === $fixed) {
                continue;
            }

            $url = '' !== $fixed ? $fixed : $this->addressUnder($parentId, (string) $locale, $slug);

            $existing = RewritingUrlQuery::create()
                ->filterByUrl($url)
                ->filterByView(TheliaCMS::PAGE_VIEW)
                ->filterByViewLocale((string) $locale)
                ->filterByRedirected(null, Criteria::ISNULL)
                ->findOne();

            if (null === $existing) {
                continue;
            }

            $page = CmsPageQuery::create()
                ->filterByDeletedAt(null, Criteria::ISNULL)
                ->findPk((int) $existing->getViewId());

            if ($page instanceof CmsPage) {
                return $page;
            }
        }

        return null;
    }

    private function addressUnder(int $parentId, string $locale, string $slug): string
    {
        if (0 === $parentId) {
            return $slug;
        }

        $parent = CmsPageQuery::create()->findPk($parentId);
        $parentUrl = $parent?->getRewrittenUrl($locale);

        return null !== $parentUrl && '' !== $parentUrl ? $parentUrl.'/'.$slug : $slug;
    }

    private function refreshUrl(CmsPage $page, string $locale, ?string $slug, ImportReport $report): void
    {
        try {
            $this->urls->refresh($page, $locale, null !== $slug && '' !== $slug ? $slug : null);
        } catch (\InvalidArgumentException $exception) {
            // A slug this site reserves — the address of its own back office,
            // for one — or a fixed address another object answers on. The page
            // is kept, under an address derived from its title, rather than the
            // whole import being refused.
            $page->setLocale($locale)->setFixedUrl(null)->save();
            $this->urls->refresh($page, $locale);
            $report->warn(\sprintf('Page "%s" could not keep its address in %s: %s', $page->getTitle(), $locale, $exception->getMessage()));
        }
    }

    /**
     * The type of an imported page.
     *
     * Files written before 1.2.0 carry the former `layout` key, whose three
     * values are page types of the same code. A type this site lacks is created
     * when `$knownTypes` is given, which only an import of a site does: that is
     * the gesture of whoever runs the site. Starting a page from a template
     * needs nothing more than the right to write pages, so there a missing type
     * is the default one, never a type added behind the settings screen.
     *
     * @param array<string, mixed> $page
     * @param list<string>|null    $knownTypes the codes of the site, completed as types are created
     */
    private function pageTypeOf(array $page, ImportReport $report, ConnectionInterface $connection, ?array &$knownTypes = null): string
    {
        $raw = $page['page_type'] ?? $page['layout'] ?? PageTypeCode::DEFAULT;
        $code = \is_string($raw) ? $raw : '';

        if (!PageTypeCode::isValid($code)) {
            $report->warn('A page type that is not a valid code was replaced by the default type.');

            return PageTypeCode::DEFAULT;
        }

        if (null === $knownTypes) {
            return $this->pageTypes->exists($code, $connection) ? $code : PageTypeCode::DEFAULT;
        }

        if (!\in_array($code, $knownTypes, true)) {
            $this->pageTypeWriter->add($code, $connection);
            $knownTypes[] = $code;
            $report->warn(\sprintf('Page type "%s" did not exist on this site: it was created.', $code));
        }

        return $code;
    }

    /**
     * The tag of an imported page, created here when the site lacks it: a tag
     * is a label of the content, so bringing one in takes no more than the
     * right to write the page, from a site file or from a saved template.
     * Files written before 1.3.0 carry no tag, before 2.5.0 no color, and
     * before 2.7.0 a color that is a name of the former palette. The color
     * goes to a tag created here: a tag the site has keeps its own.
     *
     * @param array<string, mixed> $page
     */
    private function tagIdOf(array $page, ImportReport $report, ConnectionInterface $connection): ?int
    {
        $raw = $page['tag'] ?? null;

        if (null === $raw) {
            return null;
        }

        $name = \is_string($raw) ? PageTagName::normalize($raw) : '';

        if (!PageTagName::isValid($name)) {
            $report->warn('A page tag that is not a name was left out.');

            return null;
        }

        return (int) $this->tagWriter->add($name, $this->tagColorOf($page, $name, $report), $connection)->getId();
    }

    /**
     * @param array<string, mixed> $page
     */
    private function tagColorOf(array $page, string $name, ImportReport $report): ?PageTagColor
    {
        $raw = $page['tag_color'] ?? null;

        if (null === $raw) {
            return null;
        }

        $rawText = $page['tag_text_color'] ?? null;
        $color = \is_string($raw) ? PageTagColor::fromStored($raw, \is_string($rawText) ? $rawText : null) : null;

        if (null === $color) {
            $report->warn(\sprintf('The tag "%s" was imported without its color, which is not the code of a color.', $name));
        }

        return $color;
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

    /**
     * Markup of the file, filtered as a publication filters it: the draft is
     * shown by the preview, the published version by the front, and both
     * would otherwise carry whatever the file was edited to hold.
     *
     * @param array<int, int> $mediaMap
     */
    private function importedHtml(mixed $html, array $mediaMap, ImportOptions $options, ImportReport $report, string $where): ?string
    {
        if (!\is_string($html)) {
            return null;
        }

        $remapped = (string) $this->media->remap($html, $mediaMap);
        $filtered = $this->sanitizer->html($remapped, $options->allowCustomCode);

        if ($filtered === $remapped) {
            return $filtered;
        }

        $removedEmbeds = $this->sanitizer->customCodeCount($remapped) - $this->sanitizer->customCodeCount($filtered);

        $report->warn(\sprintf(
            '%s was filtered: it held markup a page may not publish%s.',
            $where,
            $removedEmbeds > 0 ? \sprintf(', among which %d element(s) of custom code, which an import keeps only when it is allowed custom code', $removedEmbeds) : '',
        ));

        return $filtered;
    }

    /**
     * A document of the file, checked as the content screen checks the one it
     * saves. The sections of a component of the site that neither the file
     * nor this site has are kept as they are, and said so: the document is
     * saved again once the component is made here. A document the screen
     * would refuse otherwise is left out, and the content keeps only its
     * filtered markup.
     *
     * @param array<int, int> $mediaMap
     */
    private function importedDocument(mixed $json, array $mediaMap, ImportReport $report, string $where): ?string
    {
        if (!\is_string($json)) {
            return null;
        }

        $remapped = $this->media->remap($json, $mediaMap);

        try {
            $document = Document::fromJson($remapped);

            if (null === $document) {
                return null;
            }

            $known = array_values(array_filter($document->sections, $this->isKnownOrNotOfTheSite(...)));

            if (\count($known) < \count($document->sections)) {
                // The sections of the missing component have no schema to be
                // read against here; every other one is checked all the same.
                $this->documentValidator->validate(new Document($known));

                return $remapped;
            }

            return $this->documentValidator->validate($document)->toJson();
        } catch (InvalidDocumentException $exception) {
            $report->warn(\sprintf('%s was imported without its document, which this site does not accept: %s', $where, implode(' ', $exception->problems)));

            return null;
        }
    }

    private function isKnownOrNotOfTheSite(Section $section): bool
    {
        return !str_starts_with($section->component, SiteComponent::PREFIX) || null !== $this->catalog->find($section->component);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
