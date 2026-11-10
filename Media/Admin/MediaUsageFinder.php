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

namespace TheliaCMS\Media\Admin;

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Media\Icon\IconName;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Model\CmsComponentQuery;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;

/**
 * Which pages use which image.
 *
 * Editors delete images they believe unused, and a deleted image leaves broken
 * pictures behind. An image reaches a page two ways: as a URL inside its stored
 * HTML, which has no join to make, so the contents are read once and scanned in
 * memory for every image at a time — one query for a whole grid rather than one
 * per card — and as the image of the page, read from its column. The document
 * of a catalogue item uses an image the same way the content of a page does.
 * An image named as an icon is also used where a document cites its name
 * (`"cms:<name>"`), a draft included: the drawing is written into the page
 * when it is saved, so no URL of the image ever shows it. So is it where the
 * template of a component of the site writes `cms_icon('cms:<name>')`.
 */
final readonly class MediaUsageFinder
{
    public function __construct(private IconNames $icons)
    {
    }

    /**
     * Number of live pages using each image, keyed by image id.
     *
     * @param list<int> $imageIds
     *
     * @return array<int, int>
     */
    public function countsFor(array $imageIds): array
    {
        // Page ids as keys: a page holding the image in two languages, or in its
        // content and as its image, is one page using it.
        $pagesByImage = array_fill_keys($imageIds, []);
        $iconNames = $this->iconNames();

        foreach ($this->pageContents() as $content) {
            foreach ($imageIds as $imageId) {
                if ($this->references($content['html'], $imageId, $iconNames)) {
                    $pagesByImage[$imageId][$content['pageId']] = true;
                }
            }
        }

        foreach ($this->pagesShowing($imageIds) as $pageId => $imageId) {
            $pagesByImage[$imageId][$pageId] = true;
        }

        foreach ($this->itemContents() as $content) {
            foreach ($imageIds as $imageId) {
                if ($this->references($content['html'], $imageId, $iconNames)) {
                    $pagesByImage[$imageId][$content['type']->value.'-'.$content['id']] = true;
                }
            }
        }

        foreach ($this->componentTemplates() as $component) {
            foreach ($imageIds as $imageId) {
                if (isset($iconNames[$imageId]) && IconName::isQuotedIn($component['template'], $iconNames[$imageId])) {
                    $pagesByImage[$imageId]['component-'.$component['code']] = true;
                }
            }
        }

        return array_map(\count(...), $pagesByImage);
    }

    /**
     * Codes of the components of the site whose template cites an icon.
     *
     * @return list<string>
     */
    public function componentsUsing(int $imageId): array
    {
        $name = $this->iconNames()[$imageId] ?? null;

        if (null === $name) {
            return [];
        }

        $codes = [];

        foreach ($this->componentTemplates() as $component) {
            if (IconName::isQuotedIn($component['template'], $name)) {
                $codes[] = $component['code'];
            }
        }

        return $codes;
    }

    /**
     * Live pages using an image, with the locales they use it in.
     *
     * @return list<array{page: CmsPage, locales: list<string>}>
     */
    public function pagesUsing(int $imageId): array
    {
        $localesByPage = [];
        $iconNames = $this->iconNames();

        foreach ($this->pageContents() as $content) {
            if (!$this->references($content['html'], $imageId, $iconNames)) {
                continue;
            }

            $localesByPage[$content['pageId']][] = $content['locale'];
        }

        // The image of a page is the same in every language, so it names none.
        foreach (array_keys($this->pagesShowing([$imageId])) as $pageId) {
            $localesByPage[$pageId] ??= [];
        }

        if ([] === $localesByPage) {
            return [];
        }

        $usages = [];

        foreach (CmsPageQuery::create()->filterById(array_keys($localesByPage), Criteria::IN)->find() as $page) {
            $usages[] = [
                'page' => $page,
                'locales' => $localesByPage[(int) $page->getId()],
            ];
        }

        return $usages;
    }

    /**
     * Catalogue items whose document uses an image, with the locales they
     * use it in.
     *
     * @return list<array{type: CatalogueItemType, id: int, locales: list<string>}>
     */
    public function itemsUsing(int $imageId): array
    {
        $usages = [];
        $iconNames = $this->iconNames();

        foreach ($this->itemContents() as $content) {
            if (!$this->references($content['html'], $imageId, $iconNames)) {
                continue;
            }

            $key = $content['type']->value.'-'.$content['id'];
            $usages[$key] ??= ['type' => $content['type'], 'id' => $content['id'], 'locales' => []];
            $usages[$key]['locales'][] = $content['locale'];
        }

        return array_values($usages);
    }

    /**
     * Number of live pages, catalogue items and components of the site using
     * an image.
     */
    public function useCount(int $imageId): int
    {
        return \count($this->pagesUsing($imageId)) + \count($this->itemsUsing($imageId)) + \count($this->componentsUsing($imageId));
    }

    /**
     * The Twig template of every component of the site, draft or ready.
     *
     * @return list<array{code: string, template: string}>
     */
    private function componentTemplates(): array
    {
        $rows = CmsComponentQuery::create()
            ->filterByTemplate(null, Criteria::ISNOTNULL)
            ->select(['Code', 'Template'])
            ->find()
            ->toArray();

        return array_map(static fn (array $row): array => ['code' => (string) $row['Code'], 'template' => (string) $row['Template']], $rows);
    }

    /**
     * Stored content of every page not in the bin, one row per locale.
     *
     * Four columns, not one. The draft counts as a use — an image referenced
     * by an unpublished draft is still expected to be there when that draft
     * goes live — and the documents are read too: an image a section names in
     * its props is in the document before any rendering shows it. Missing a
     * use would let an editor delete an image out from under a page.
     *
     * @return list<array{pageId: int, locale: string, html: string}>
     */
    private function pageContents(): array
    {
        $rows = CmsPageContentQuery::create()
            ->useCmsPageQuery()
                ->filterByDeletedAt(null, Criteria::ISNULL)
            ->endUse()
            ->select(['PageId', 'Locale', 'DraftHtml', 'PublishedHtml', 'DraftDocument', 'PublishedDocument'])
            ->find()
            ->toArray();

        $contents = [];

        foreach ($rows as $row) {
            $contents[] = [
                'pageId' => (int) $row['PageId'],
                'locale' => (string) $row['Locale'],
                'html' => (string) $row['DraftHtml'].(string) $row['PublishedHtml'].(string) $row['DraftDocument'].(string) $row['PublishedDocument'],
            ];
        }

        return $contents;
    }

    /**
     * Stored documents of the catalogue items, one row per locale, the four
     * columns read as for a page.
     *
     * @return list<array{type: CatalogueItemType, id: int, locale: string, html: string}>
     */
    private function itemContents(): array
    {
        $rows = CmsItemContentQuery::create()
            ->select(['ItemType', 'ItemId', 'Locale', 'DraftHtml', 'PublishedHtml', 'DraftDocument', 'PublishedDocument'])
            ->find()
            ->toArray();

        $contents = [];

        foreach ($rows as $row) {
            $type = CatalogueItemType::tryFrom((string) $row['ItemType']);

            if (null === $type) {
                continue;
            }

            $contents[] = [
                'type' => $type,
                'id' => (int) $row['ItemId'],
                'locale' => (string) $row['Locale'],
                'html' => (string) $row['DraftHtml'].(string) $row['PublishedHtml'].(string) $row['DraftDocument'].(string) $row['PublishedDocument'],
            ];
        }

        return $contents;
    }

    /**
     * Live pages whose image is one of these, as page id => image id.
     *
     * @param list<int> $imageIds
     *
     * @return array<int, int>
     */
    private function pagesShowing(array $imageIds): array
    {
        if ([] === $imageIds) {
            return [];
        }

        $rows = CmsPageQuery::create()
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->filterByImageId($imageIds, Criteria::IN)
            ->select(['Id', 'ImageId'])
            ->find()
            ->toArray();

        $pages = [];

        foreach ($rows as $row) {
            $pages[(int) $row['Id']] = (int) $row['ImageId'];
        }

        return $pages;
    }

    /**
     * @param array<int, string> $iconNames the icon name of each image that has one, by image id
     */
    private function references(string $html, int $imageId, array $iconNames): bool
    {
        // An icon is cited by its name in a document, quotes included so that
        // `cms:arrow` is not found in `cms:arrow-left`.
        if (isset($iconNames[$imageId]) && str_contains($html, '"'.IconName::prefixed($iconNames[$imageId]).'"')) {
            return true;
        }

        // The editor project is JSON, and a JSON encoder is free to write the
        // slashes of a URL escaped.
        $html = str_replace('\\/', '/', $html);

        // A video is served from a route of its own, which ends on its id.
        return str_contains($html, '/image-library/'.$imageId.'/') || str_contains($html, '/video-library/'.$imageId.'.mp4');
    }

    /**
     * @return array<int, string>
     */
    private function iconNames(): array
    {
        return $this->icons->namesByImage();
    }
}
