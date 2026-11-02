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

namespace TheliaCMS\Page;

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Model\CmsTagQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\Field\PageFields;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;

/**
 * The single front-office read path for pages. Draft columns are never selected
 * here: a page the visitor sees is always the published snapshot.
 */
final readonly class PublishedPageRepository
{
    public function __construct(
        private CmsMediaLibrary $library,
        private PageFields $fields,
    ) {
    }

    public function find(int $pageId, string $locale, ?\DateTimeInterface $now = null): ?PublishedPage
    {
        $page = CmsPageQuery::create()->findPk($pageId);

        if (!$page instanceof CmsPage || !$this->isLive($page, $now ?? new \DateTimeImmutable())) {
            return null;
        }

        $content = CmsPageContentQuery::create()
            ->filterByPageId($pageId)
            ->filterByLocale($locale)
            ->findOne();

        if (null === $content || null === $content->getPublishedAt() || null === $content->getPublishedHtml()) {
            return null;
        }

        $page->setLocale($locale);

        return new PublishedPage(
            id: $pageId,
            locale: $locale,
            title: (string) $page->getTitle(),
            pageType: PageTypeCode::orDefault($page->getPageType()),
            html: (string) $content->getPublishedHtml(),
            chapo: $this->nullIfBlank($page->getChapo()),
            description: $this->nullIfBlank($page->getDescription()),
            image: $this->imageOf($page, $locale),
            metaTitle: $this->nullIfBlank($page->getMetaTitle()),
            metaDescription: $this->nullIfBlank($page->getMetaDescription()),
            noindex: 1 === $page->getNoindex(),
            nofollow: 1 === $page->getNofollow(),
            publishedAt: $content->getPublishedAt(),
            tag: $this->tagOf($page),
            tagColor: $this->tagColorOf($page)?->background,
            tagTextColor: $this->tagColorOf($page)?->textColor(),
            fields: $this->fields->valuesOf($page, $locale),
            provenance: $content->getPublishedProvenance(),
        );
    }

    /**
     * The pages online that carry this tag, the most recently published first.
     *
     * Online means what find() means: visible, out of the bin, inside the
     * publication window, and published in this language. Only the columns a
     * list needs are read; the body of a page is for find().
     *
     * @return list<PageCard>
     */
    public function withTag(string $tag, string $locale, ?\DateTimeInterface $now = null): array
    {
        $name = PageTagName::normalize($tag);

        if ('' === $name) {
            return [];
        }

        // Read once: the name the site stores is the one every card carries,
        // whatever the case the caller typed it in.
        $tag = CmsTagQuery::create()->filterByName($name)->findOne();

        if (!$tag instanceof CmsTag) {
            return [];
        }

        $now ??= new \DateTimeImmutable();

        $pages = CmsPageQuery::create()
            ->filterByTagId($tag->getId())
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->filterByVisible(1)
            ->useCmsPageContentQuery()
                ->filterByLocale($locale)
                ->filterByPublishedAt(null, Criteria::ISNOTNULL)
                ->filterByPublishedHtml(null, Criteria::ISNOTNULL)
            ->endUse()
            ->withColumn(CmsPageContentTableMap::COL_PUBLISHED_AT, 'content_published_at')
            ->addDescendingOrderByColumn('content_published_at')
            ->find();

        $cards = [];
        /** @var array<string, FieldDefinitions> $definitions read once per type */
        $definitions = [];

        foreach ($pages as $page) {
            if (!$this->isLive($page, $now)) {
                continue;
            }

            $type = PageTypeCode::orDefault($page->getPageType());
            $definitions[$type] ??= $this->fields->definitionsOf($page);

            $page->setLocale($locale);
            $publishedAt = $page->getVirtualColumn('content_published_at');

            $cards[] = new PageCard(
                id: (int) $page->getId(),
                locale: $locale,
                title: (string) $page->getTitle(),
                url: (string) $page->getUrl($locale),
                pageType: PageTypeCode::orDefault($page->getPageType()),
                chapo: $this->nullIfBlank($page->getChapo()),
                description: $this->nullIfBlank($page->getDescription()),
                image: $this->imageOf($page, $locale),
                tag: (string) $tag->getName(),
                tagColor: PageTagColor::fromStored($tag->getColor(), $tag->getTextColor())?->background,
                tagTextColor: PageTagColor::fromStored($tag->getColor(), $tag->getTextColor())?->textColor(),
                publishedAt: \is_string($publishedAt) ? new \DateTimeImmutable($publishedAt) : null,
                fields: $this->fields->valuesOf($page, $locale, $definitions[$type]),
            );
        }

        return $cards;
    }

    /**
     * The pages online of a type, the model left out: what a list of the
     * recipes of the site shows. Online means what find() means.
     *
     * The pages of the type are read once and ordered, filtered and cut
     * here: the values live in JSON, and a series of pages is a few hundred
     * at most (docs/adr/0009).
     *
     * @return list<PageCard>
     */
    public function ofType(string $pageType, string $locale, ?PageListQuery $query = null, ?\DateTimeInterface $now = null): array
    {
        if (!PageTypeCode::isValid($pageType)) {
            return [];
        }

        $query ??= new PageListQuery();
        $now ??= new \DateTimeImmutable();

        $pages = CmsPageQuery::create()
            ->filterByPageType($pageType)
            ->filterByModel(0)
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->filterByVisible(1)
            ->useCmsPageContentQuery()
                ->filterByLocale($locale)
                ->filterByPublishedAt(null, Criteria::ISNOTNULL)
                ->filterByPublishedHtml(null, Criteria::ISNOTNULL)
            ->endUse()
            ->withColumn(CmsPageContentTableMap::COL_PUBLISHED_AT, 'content_published_at')
            ->find();

        $definitions = null;
        $rows = [];

        foreach ($pages as $page) {
            if (!$this->isLive($page, $now)) {
                continue;
            }

            $definitions ??= $this->fields->definitionsOf($page);
            $values = $this->fields->valuesOf($page, $locale, $definitions);

            if (!$query->matches($values, $definitions)) {
                continue;
            }

            $page->setLocale($locale);
            $publishedAt = $page->getVirtualColumn('content_published_at');

            $rows[] = [
                'page' => $page,
                'title' => (string) $page->getTitle(),
                'position' => (int) $page->getPosition(),
                'published_at' => \is_string($publishedAt) ? new \DateTimeImmutable($publishedAt) : null,
                'values' => $values,
            ];
        }

        if (null === $definitions) {
            return [];
        }

        usort($rows, static fn (array $a, array $b): int => $query->compare($a, $b, $definitions));
        $rows = \array_slice($rows, $query->offset, $query->limit);

        return array_map(function (array $row) use ($locale, $pageType): PageCard {
            $page = $row['page'];

            return new PageCard(
                id: (int) $page->getId(),
                locale: $locale,
                title: $row['title'],
                url: (string) $page->getUrl($locale),
                pageType: $pageType,
                chapo: $this->nullIfBlank($page->getChapo()),
                description: $this->nullIfBlank($page->getDescription()),
                image: $this->imageOf($page, $locale),
                tag: $this->tagOf($page),
                tagColor: $this->tagColorOf($page)?->background,
                tagTextColor: $this->tagColorOf($page)?->textColor(),
                publishedAt: $row['published_at'],
                fields: $row['values'],
            );
        }, $rows);
    }

    /**
     * The draft of a page, shaped exactly like a published one so the same
     * template renders it.
     *
     * Only reachable through a signed preview link, and always marked
     * `noindex`: a draft that a search engine picks up is a page published by
     * accident.
     */
    public function draft(int $pageId, string $locale): ?PublishedPage
    {
        $page = CmsPageQuery::create()->findPk($pageId);

        if (!$page instanceof CmsPage || null !== $page->getDeletedAt()) {
            return null;
        }

        $content = CmsPageContentQuery::create()
            ->filterByPageId($pageId)
            ->filterByLocale($locale)
            ->findOne();

        if (null === $content || null === $content->getDraftHtml()) {
            return null;
        }

        $page->setLocale($locale);

        return new PublishedPage(
            id: $pageId,
            locale: $locale,
            title: (string) $page->getTitle(),
            pageType: PageTypeCode::orDefault($page->getPageType()),
            html: (string) $content->getDraftHtml(),
            chapo: $this->nullIfBlank($page->getChapo()),
            description: $this->nullIfBlank($page->getDescription()),
            image: $this->imageOf($page, $locale),
            metaTitle: $this->nullIfBlank($page->getMetaTitle()),
            metaDescription: $this->nullIfBlank($page->getMetaDescription()),
            noindex: true,
            nofollow: true,
            tag: $this->tagOf($page),
            tagColor: $this->tagColorOf($page)?->background,
            tagTextColor: $this->tagColorOf($page)?->textColor(),
            fields: $this->fields->valuesOf($page, $locale),
            provenance: $content->getDraftProvenance(),
        );
    }

    /**
     * Whether a visitor opening this page in this language gets it, rather than
     * a 404.
     *
     * Same conditions as find(), without reading the published HTML: callers
     * ask this about pages they are not going to render, and that column holds
     * the whole page.
     */
    public function isReachable(int $pageId, string $locale, ?\DateTimeInterface $now = null): bool
    {
        $page = CmsPageQuery::create()->findPk($pageId);

        if (!$page instanceof CmsPage || !$this->isLive($page, $now ?? new \DateTimeImmutable())) {
            return false;
        }

        return 0 < CmsPageContentQuery::create()
            ->filterByPageId($pageId)
            ->filterByLocale($locale)
            ->filterByPublishedAt(null, Criteria::ISNOTNULL)
            ->filterByPublishedHtml(null, Criteria::ISNOTNULL)
            ->count();
    }

    /**
     * Locales the page is actually published in — feeds hreflang and the
     * language switcher.
     *
     * @return list<string>
     */
    public function publishedLocales(int $pageId): array
    {
        $contents = CmsPageContentQuery::create()
            ->filterByPageId($pageId)
            ->filterByPublishedAt(null, Criteria::ISNOTNULL)
            ->select(['Locale'])
            ->find()
            ->toArray();

        return array_values(array_map(strval(...), $contents));
    }

    /**
     * Visible, not in the bin, inside its publication window, and not the
     * model of a type: a model is a reference for new pages, never a page of
     * the site, even set visible and holding a published version an import
     * brought in.
     */
    private function isLive(CmsPage $page, \DateTimeInterface $now): bool
    {
        if (null !== $page->getDeletedAt() || 1 !== $page->getVisible() || 1 === $page->getModel()) {
            return false;
        }

        $publishAt = $page->getPublishAt();
        if (null !== $publishAt && $publishAt > $now) {
            return false;
        }

        $unpublishAt = $page->getUnpublishAt();

        return null === $unpublishAt || $unpublishAt > $now;
    }

    private function tagOf(CmsPage $page): ?string
    {
        if (null === $page->getTagId()) {
            return null;
        }

        $tag = $page->getCmsTag();

        return $tag instanceof CmsTag ? (string) $tag->getName() : null;
    }

    /**
     * A code or a name of the former palette: anything else in the table reads
     * as no color.
     */
    private function tagColorOf(CmsPage $page): ?PageTagColor
    {
        if (null === $page->getTagId()) {
            return null;
        }

        $tag = $page->getCmsTag();

        return PageTagColor::fromStored($tag?->getColor(), $tag?->getTextColor());
    }

    private function nullIfBlank(?string $value): ?string
    {
        return '' === trim((string) $value) ? null : $value;
    }

    /**
     * Null for a page without an image, and for one whose image has since been
     * deleted from the library: there is no foreign key to keep the id honest.
     */
    private function imageOf(CmsPage $page, string $locale): ?PageImage
    {
        $imageId = $page->getImageId();

        if (null === $imageId) {
            return null;
        }

        $image = LibraryImageQuery::create()->findPk($imageId);

        if (!$image instanceof LibraryImage) {
            return null;
        }

        $url = $this->library->publicUrl($image);

        if (null === $url) {
            return null;
        }

        $this->library->measure($image);
        $image->setLocale($locale);

        return new PageImage(
            id: (int) $image->getId(),
            url: $url,
            alt: 1 === $image->getDecorative() ? '' : $this->nullIfBlank($image->getAlt()),
            width: $image->getWidth(),
            height: $image->getHeight(),
        );
    }
}
