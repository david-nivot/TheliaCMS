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
use Thelia\Model\LangQuery;
use Thelia\Model\ModuleQuery;
use TheliaCMS\Catalogue\CatalogueItemKeys;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormFieldQuery;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsMenu;
use TheliaCMS\Model\CmsMenuItemQuery;
use TheliaCMS\Model\CmsMenuQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageTypeDefinitionQuery;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\Field\PageFields;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Zone\HeaderZone;
use TheliaLibrary\Model\LibraryImageQuery;

/**
 * Writes the content of the site as one JSON document.
 *
 * What it deliberately leaves out is as much part of the design as what it
 * takes:
 *
 * - **form submissions**, because they are what visitors wrote about
 *   themselves, and a starter kit copied onto a laptop is not where they
 *   belong;
 * - **third-party snippets**, because they carry the measurement accounts of
 *   one site, and importing them elsewhere sends that site's traffic to them;
 * - **revisions**, because they are the history of one site, not its content
 *   (the documents of the catalogue items travel without theirs either);
 * - **image files**, whose names travel so the importing site can point at its
 *   own copies (§2.13).
 */
final readonly class SiteExporter
{
    public function __construct(
        private CmsSettings $settings,
        private MediaReferences $media,
        private ?SiteComponentRepository $components = null,
        private CatalogueItemKeys $itemKeys = new CatalogueItemKeys(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $locales = $this->locales();
        $pages = [];
        $mediaIds = [];

        foreach ($this->pagesInTreeOrder() as $page) {
            $document = $this->pageDocument($page, $locales);
            $pages[] = $document;
            $mediaIds = [...$mediaIds, ...$this->mediaOf($document)];
        }

        $catalogueContents = $this->catalogueContents();

        foreach ($catalogueContents as $document) {
            $mediaIds = [...$mediaIds, ...$this->mediaOf($document)];
        }

        return [
            ...ExportFormat::header($this->moduleVersion(), $locales, new \DateTimeImmutable()),
            'page_types' => $this->pageTypes($locales),
            // Before the pages: a page names the components it is made of.
            'components' => $this->siteComponents(),
            'pages' => $pages,
            'catalogue_contents' => $catalogueContents,
            'menus' => $this->menus($locales),
            'forms' => $this->forms($locales),
            'settings' => $this->settingsDocument(),
            'media' => $this->mediaDocument($mediaIds),
        ];
    }

    /**
     * The document of a single page.
     *
     * @param list<string> $locales
     *
     * @return array<string, mixed>
     */
    public function pageDocument(CmsPage $page, array $locales): array
    {
        $parent = (int) $page->getParent();

        $document = [
            'uid' => self::pageUid((int) $page->getId()),
            'parent' => $parent > 0 ? self::pageUid($parent) : null,
            'position' => (int) $page->getPosition(),
            'visible' => 1 === $page->getVisible(),
            'page_type' => (string) $page->getPageType(),
            'model' => 1 === $page->getModel(),
            // The header zone: hidden, matched on its kind and not on an address it has not.
            'zone' => HeaderZone::MODEL === $page->getModel(),
            'title_band' => 0 !== $page->getTitleBand(),
            'fields' => PageFields::decode($page->getFields()),
            'tag' => $page->getCmsTag()?->getName(),
            'tag_color' => $page->getCmsTag()?->getColor(),
            'tag_text_color' => $page->getCmsTag()?->getTextColor(),
            'image_id' => $page->getImageId(),
            'publish_at' => $this->date($page->getPublishAt()),
            'unpublish_at' => $this->date($page->getUnpublishAt()),
            'translations' => [],
            'contents' => [],
        ];

        foreach ($locales as $locale) {
            $page->setLocale($locale);
            $title = (string) $page->getTitle();

            if ('' === trim($title)) {
                continue;
            }

            $document['translations'][$locale] = [
                'title' => $title,
                'slug' => $this->slugOf($page, $locale),
                'fixed_url' => $page->getFixedUrl(),
                'chapo' => $page->getChapo(),
                'description' => $page->getDescription(),
                'meta_title' => $page->getMetaTitle(),
                'meta_description' => $page->getMetaDescription(),
                'og_title' => $page->getOgTitle(),
                'og_description' => $page->getOgDescription(),
                'og_image_id' => $page->getOgImageId(),
                'twitter_card' => $page->getTwitterCard(),
                'canonical' => $page->getCanonical(),
                'noindex' => 1 === $page->getNoindex(),
                'nofollow' => 1 === $page->getNofollow(),
                'fields' => PageFields::decode($page->getTranslatedFields()),
            ];
        }

        foreach (CmsPageContentQuery::create()->filterByPageId($page->getId())->find() as $content) {
            $document['contents'][(string) $content->getLocale()] = [
                'draft_document' => $content->getDraftDocument(),
                'draft_html' => $content->getDraftHtml(),
                'draft_provenance' => $content->getDraftProvenance(),
                'published_document' => $content->getPublishedDocument(),
                'published_html' => $content->getPublishedHtml(),
                'published_provenance' => $content->getPublishedProvenance(),
                'published_at' => $this->date($content->getPublishedAt()),
                // Carried because the back office compares it with the
                // publication date to tell whether a page holds unpublished
                // work. Left out, every imported page would claim to have been
                // edited since it went live.
                'updated_at' => $this->date($content->getUpdatedAt()),
            ];
        }

        return $document;
    }

    /**
     * The documents of the catalogue items, one entry per item: its type,
     * the key another site finds it by (CatalogueItemKeys) and its contents
     * by locale, in the shape of the contents of a page.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogueContents(): array
    {
        $items = [];

        foreach (CmsItemContentQuery::create()->orderByItemType()->orderByItemId()->orderByLocale()->find() as $content) {
            $type = CatalogueItemType::tryFrom((string) $content->getItemType());

            if (null === $type) {
                continue;
            }

            $index = $type->value.'-'.$content->getItemId();
            $items[$index] ??= [
                'type' => $type->value,
                'key' => $this->itemKeys->keyOf($type, (int) $content->getItemId()),
                'contents' => [],
            ];

            $items[$index]['contents'][(string) $content->getLocale()] = [
                'draft_document' => $content->getDraftDocument(),
                'draft_html' => $content->getDraftHtml(),
                'draft_provenance' => $content->getDraftProvenance(),
                'published_document' => $content->getPublishedDocument(),
                'published_html' => $content->getPublishedHtml(),
                'published_provenance' => $content->getPublishedProvenance(),
                'published_at' => $this->date($content->getPublishedAt()),
                'updated_at' => $this->date($content->getUpdatedAt()),
            ];
        }

        return array_values($items);
    }

    /**
     * The page types described as templates: a type that is only a code
     * travels with the pages that carry it, as it always has.
     *
     * @param list<string> $locales
     *
     * @return list<array<string, mixed>>
     */
    private function pageTypes(array $locales): array
    {
        $types = [];

        foreach (CmsPageTypeDefinitionQuery::create()->orderByCode()->find() as $type) {
            $fields = FieldDefinitions::fromJson($type->getFields());
            $translations = $this->translationsOf($type, $locales, ['title' => 'getTitle', 'description' => 'getDescription']);

            if ($fields->isEmpty() && null === $type->getBrief() && null === $type->getInstructions() && [] === $translations) {
                continue;
            }

            $types[] = [
                'code' => (string) $type->getCode(),
                'brief' => $type->getBrief(),
                'brief_tone' => $type->getBriefTone(),
                'brief_audience' => $type->getBriefAudience(),
                'instructions' => $type->getInstructions(),
                'fields' => $fields->toArray(),
                'translations' => $translations,
            ];
        }

        return $types;
    }

    /**
     * The components the site made, as written, the prototypes marked as
     * such: a page of the export may hold one. A candidate never kept is a
     * call of the ledger, not a component.
     *
     * @return list<array<string, mixed>>
     */
    private function siteComponents(): array
    {
        $prototypes = $this->components?->prototypes() ?? [];

        return array_map(static fn ($component): array => [
            'code' => $component->code,
            'label' => $component->label,
            'description' => $component->description,
            'props' => $component->props,
            'sample' => (object) $component->sample,
            'template' => $component->template,
            'css' => $component->css,
            'provenance' => $component->provenance,
            'status' => \in_array($component->code, $prototypes, true) ? SiteComponentRepository::STATUS_DRAFT : SiteComponentRepository::STATUS_READY,
        ], $this->components?->all() ?? []);
    }

    public static function pageUid(int $id): string
    {
        return 'page-'.$id;
    }

    /**
     * @param list<string> $locales
     *
     * @return list<array<string, mixed>>
     */
    private function menus(array $locales): array
    {
        $menus = [];

        // Shared menus first ('' sorts before any locale): an importer meets
        // the menu a language was detached from before the language.
        foreach (CmsMenuQuery::create()->orderByMenuLocale()->orderById()->find() as $menu) {
            $menus[] = [
                'code' => (string) $menu->getCode(),
                // '' for the shared menu of the code, the locale for a
                // language that has a menu of its own.
                'locale' => (string) $menu->getMenuLocale(),
                'translations' => $this->translationsOf($menu, $locales, ['title' => 'getTitle']),
                'items' => $this->menuItems($menu, $locales),
            ];
        }

        return $menus;
    }

    /**
     * @param list<string> $locales
     *
     * @return list<array<string, mixed>>
     */
    private function menuItems(CmsMenu $menu, array $locales): array
    {
        $items = [];

        $rows = CmsMenuItemQuery::create()
            ->filterByMenuId($menu->getId())
            ->orderByParent()
            ->orderByPosition()
            ->find();

        foreach ($rows as $item) {
            $parent = (int) $item->getParent();
            $targetId = $item->getTargetId();

            $items[] = [
                'uid' => 'item-'.$item->getId(),
                'parent' => $parent > 0 ? 'item-'.$parent : null,
                'position' => (int) $item->getPosition(),
                'target_type' => (string) $item->getTargetType(),
                // A menu entry pointing at a CMS page travels as the page it
                // points at; one pointing at a content, a folder, a category or
                // a product of the core keeps the raw id, which only means
                // something on a site holding the same catalogue.
                'target' => 'page' === $item->getTargetType() && null !== $targetId
                    ? self::pageUid((int) $targetId)
                    : null,
                'target_id' => 'page' === $item->getTargetType() ? null : $targetId,
                'url' => $item->getUrl(),
                'open_new_tab' => 1 === $item->getOpenNewTab(),
                'translations' => $this->translationsOf($item, $locales, ['label' => 'getLabel']),
            ];
        }

        return $items;
    }

    /**
     * @param list<string> $locales
     *
     * @return list<array<string, mixed>>
     */
    private function forms(array $locales): array
    {
        $forms = [];

        foreach (CmsFormQuery::create()->filterByDeletedAt(null, Criteria::ISNULL)->orderById()->find() as $form) {
            $forms[] = [
                'code' => (string) $form->getCode(),
                'active' => 1 === $form->getActive(),
                // Recipients travel: a contact form with nobody to write to is
                // not a working form, and the address belongs to the site being
                // set up rather than to the visitor.
                'recipients' => $form->getRecipients(),
                'store_submissions' => 1 === $form->getStoreSubmissions(),
                'retention_days' => (int) $form->getRetentionDays(),
                'send_receipt' => 1 === $form->getSendReceipt(),
                'lead_event' => 1 === $form->getLeadEvent(),
                'privacy_policy_page' => null !== $form->getPrivacyPolicyPageId()
                    ? self::pageUid((int) $form->getPrivacyPolicyPageId())
                    : null,
                'translations' => $this->translationsOf($form, $locales, [
                    'title' => 'getTitle',
                    'description' => 'getDescription',
                    'submit_label' => 'getSubmitLabel',
                    'success_message' => 'getSuccessMessage',
                    'legal_notice' => 'getLegalNotice',
                    'receipt_subject' => 'getReceiptSubject',
                    'receipt_body' => 'getReceiptBody',
                ]),
                'fields' => $this->formFields($form, $locales),
            ];
        }

        return $forms;
    }

    /**
     * @param list<string> $locales
     *
     * @return list<array<string, mixed>>
     */
    private function formFields(CmsForm $form, array $locales): array
    {
        $fields = [];

        $rows = CmsFormFieldQuery::create()
            ->filterByFormId($form->getId())
            ->orderByPosition()
            ->find();

        foreach ($rows as $field) {
            $fields[] = [
                'name' => (string) $field->getName(),
                'type' => (string) $field->getType(),
                'position' => (int) $field->getPosition(),
                'required' => 1 === $field->getRequired(),
                'options' => $field->getOptions(),
                'translations' => $this->translationsOf($field, $locales, [
                    'label' => 'getLabel',
                    'placeholder' => 'getPlaceholder',
                    'help' => 'getHelp',
                    'choices' => 'getChoices',
                ]),
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsDocument(): array
    {
        $homePageId = (int) TheliaCMS::getConfigValue('home_page_id', '0');
        $notFoundPageId = $this->settings->notFoundPageId();
        $maintenancePageId = $this->settings->maintenancePageId();

        return [
            'site_mode' => $this->settings->siteMode()->value,
            'home_page' => $homePageId > 0 ? self::pageUid($homePageId) : null,
            '404_page' => null !== $notFoundPageId ? self::pageUid($notFoundPageId) : null,
            'maintenance_page' => null !== $maintenancePageId ? self::pageUid($maintenancePageId) : null,
            'trash_retention_days' => $this->settings->trashRetentionDays(),
            'http_cache_ttl' => $this->settings->httpCacheTtl(),
            // Left out on purpose: whether the site is under maintenance right
            // now, and the addresses allowed through it. Those describe how one
            // installation is being operated, not what it holds.
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function mediaDocument(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            return [];
        }

        $media = [];

        foreach (LibraryImageQuery::create()->filterById($ids, Criteria::IN)->orderById()->find() as $image) {
            $fileName = $image->getFileName();

            if (null === $fileName) {
                continue;
            }

            $media[] = [
                'id' => (int) $image->getId(),
                'file_name' => $fileName,
            ];
        }

        return $media;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<int>
     */
    private function mediaOf(array $document): array
    {
        $fragments = [];

        foreach ($document['contents'] ?? [] as $content) {
            $fragments[] = $content['published_html'] ?? null;
            $fragments[] = $content['published_css'] ?? null;
            $fragments[] = $content['draft_html'] ?? null;
            $fragments[] = $content['draft_css'] ?? null;
        }

        $ids = $this->media->collect($fragments);

        if (null !== ($document['image_id'] ?? null)) {
            $ids[] = (int) $document['image_id'];
        }

        foreach ($document['translations'] ?? [] as $translation) {
            if (null !== ($translation['og_image_id'] ?? null)) {
                $ids[] = (int) $translation['og_image_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string>          $locales
     * @param array<string, string> $columns property name in the file, getter on the model
     *
     * @return array<string, array<string, mixed>>
     */
    private function translationsOf(object $model, array $locales, array $columns): array
    {
        $translations = [];

        foreach ($locales as $locale) {
            $model->setLocale($locale);
            $values = [];
            $hasContent = false;

            foreach ($columns as $property => $getter) {
                $value = $model->{$getter}();
                $values[$property] = $value;

                if (null !== $value && '' !== trim((string) $value)) {
                    $hasContent = true;
                }
            }

            if ($hasContent) {
                $translations[$locale] = $values;
            }
        }

        return $translations;
    }

    /**
     * @return list<CmsPage>
     */
    private function pagesInTreeOrder(): array
    {
        return iterator_to_array(
            CmsPageQuery::create()
                ->filterByDeletedAt(null, Criteria::ISNULL)
                ->orderByParent()
                ->orderByPosition()
                ->orderById()
                ->find(),
            false,
        );
    }

    /**
     * The last segment of the rewritten URL: the ancestors are prepended again
     * by the URL service when the tree is rebuilt on the importing site.
     */
    private function slugOf(CmsPage $page, string $locale): ?string
    {
        $url = $page->getRewrittenUrl($locale);

        if (null === $url || '' === $url) {
            return null;
        }

        $segments = explode('/', $url);

        return end($segments) ?: null;
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return array_values(array_map(
            static fn ($lang): string => (string) $lang->getLocale(),
            iterator_to_array(LangQuery::create()->filterByActive(1)->orderByPosition()->find(), false),
        ));
    }

    private function date(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DATE_ATOM);
    }

    private function moduleVersion(): string
    {
        $module = ModuleQuery::create()->findOneByCode('TheliaCMS');

        return (string) ($module?->getVersion() ?? '');
    }
}
