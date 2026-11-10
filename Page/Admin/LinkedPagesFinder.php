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

namespace TheliaCMS\Page\Admin;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Admin\SectionUsageFinder;
use TheliaCMS\Media\Admin\MediaUsageFinder;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContent;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\PageTagRepository;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeRepository;

/**
 * The pages behind a number of the back office ("used by 3 pages"): one entry
 * point for every screen that shows one.
 *
 * Each kind reads its rows from the finder that already gives the number
 * (SectionUsageFinder, MediaUsageFinder, the tag and type repositories), and
 * the total here is the length of those rows: the list cannot disagree with
 * the number. The bin follows the rule of the number it explains: a component
 * or an image counts the pages out of the bin only, a tag or a type counts
 * the binned pages too, and the row says so.
 */
final readonly class LinkedPagesFinder
{
    /** How many rows are named; the rest is counted. */
    public const int SHOWN = 25;

    public function __construct(
        private SectionUsageFinder $components,
        private MediaUsageFinder $media,
        private CmsMediaLibrary $library,
        private PageTagRepository $tags,
        private PageTypeRepository $types,
        private CmsPageAdminRepository $pages,
        private CatalogueItems $items,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return LinkedPages|null null when the element does not exist (an unknown tag, an image that is not the CMS's)
     */
    public function find(LinkedPageKind $kind, string $key, string $locale, int $languageId): ?LinkedPages
    {
        $language = [EditLanguage::PARAMETER => $languageId];

        switch ($kind) {
            case LinkedPageKind::Component:
                $refs = $this->components->usersOf($key);
                $listUrl = null;
                break;

            case LinkedPageKind::Media:
                $imageId = ctype_digit($key) ? (int) $key : 0;

                if (null === $this->library->ownedImage($imageId)) {
                    return null;
                }

                $refs = $this->mediaRefs($imageId);
                $listUrl = null;
                break;

            case LinkedPageKind::Tag:
                $tag = ctype_digit($key) ? $this->tags->find((int) $key) : null;

                if (null === $tag) {
                    return null;
                }

                $refs = array_map(static fn (CmsPage $page): array => ['type' => 'page', 'id' => (int) $page->getId()], $this->tags->pagesOf($tag));
                $listUrl = $this->urls->generate('admin.cms.pages.list', [PageFilters::TAG => (string) $tag->getId()] + $language);
                break;

            case LinkedPageKind::PageType:
                if (1 !== preg_match('/^'.PageTypeCode::ROUTE_REQUIREMENT.'$/', $key)) {
                    return null;
                }

                $refs = array_map(static fn (CmsPage $page): array => ['type' => 'page', 'id' => (int) $page->getId()], $this->types->pagesOfType($key));
                $listUrl = $this->urls->generate('admin.cms.pages.list', [PageFilters::TYPE => $key] + $language);
                break;
        }

        return new LinkedPages($kind, $key, \count($refs), $this->rows($refs, $locale), $listUrl);
    }

    /**
     * @return list<array{type: string, id: int}>
     */
    private function mediaRefs(int $imageId): array
    {
        $refs = [];

        foreach ($this->media->pagesUsing($imageId) as $usage) {
            $refs[] = ['type' => 'page', 'id' => (int) $usage['page']->getId()];
        }

        foreach ($this->media->itemsUsing($imageId) as $usage) {
            $refs[] = ['type' => $usage['type']->value, 'id' => $usage['id']];
        }

        return $refs;
    }

    /**
     * The first rows: the pages by title, then the catalogue items as they come.
     *
     * @param list<array{type: string, id: int}> $refs
     *
     * @return list<LinkedPage>
     */
    private function rows(array $refs, string $locale): array
    {
        $pageIds = [];
        $itemRefs = [];

        foreach ($refs as $ref) {
            if ('page' === $ref['type']) {
                $pageIds[] = $ref['id'];
            } else {
                $itemRefs[] = $ref;
            }
        }

        $titles = $this->pages->titles($pageIds, $locale);
        $shownIds = \array_slice(array_keys($titles), 0, self::SHOWN);
        $rows = [];

        if ([] !== $shownIds) {
            $contents = [];

            foreach (CmsPageContentQuery::create()->filterByPageId($shownIds, Criteria::IN)->filterByLocale($locale)->find() as $content) {
                $contents[(int) $content->getPageId()] = $content;
            }

            $models = [];

            foreach (CmsPageQuery::create()->filterById($shownIds, Criteria::IN)->find() as $page) {
                $models[(int) $page->getId()] = $page;
            }

            foreach ($shownIds as $id) {
                $page = $models[$id] ?? null;

                if (!$page instanceof CmsPage) {
                    continue;
                }

                $content = $contents[$id] ?? null;
                $rows[] = new LinkedPage('page', $id, $titles[$id], PageStatus::resolve($page, $content instanceof CmsPageContent ? $content : null), null !== $page->getDeletedAt());
            }
        }

        foreach (\array_slice($itemRefs, 0, max(0, self::SHOWN - \count($rows))) as $ref) {
            $type = CatalogueItemType::tryFrom($ref['type']);
            $title = null === $type ? '' : ($this->items->find($type, $ref['id'], $locale)?->title ?? '');
            $rows[] = new LinkedPage($ref['type'], $ref['id'], '' === $title ? '#'.$ref['id'] : $title, null, false);
        }

        return $rows;
    }
}
