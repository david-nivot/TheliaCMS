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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Model\Lang;
use TheliaCMS\Locale\AlternateUrls;
use TheliaCMS\Model\CmsTag;
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Page\PageTagRepository;
use TheliaCMS\Page\PageTypeDetails;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PublishedAccessibilityFinder;
use TheliaCMS\Page\SampleTextPageFinder;
use TheliaCMS\Page\StalePages;
use TheliaCMS\TheliaCMS;

/**
 * Everything the page listing renders, assembled in one place.
 *
 * The screen has two shapes and the choice between them is one rule: with a word
 * to look for, a state, a tag or a type to keep, or a sort, it is a list of results; with
 * nothing asked, it is the tree. Both produce the same kind of row, so the template has one table
 * and not two.
 */
final readonly class PageListPresenter
{
    public function __construct(
        private CmsPageAdminRepository $pages,
        private PageFilterPresenter $filterBar,
        private SampleTextPageFinder $sampleTextPages,
        private PublishedAccessibilityFinder $accessibilityPages,
        private UrlGeneratorInterface $urls,
        private PageTagRepository $tags,
        private PageTypeRepository $types,
        private StalePages $stalePages,
        private AlternateUrls $alternates,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PageFilters $filters, Lang $lang): array
    {
        $locale = $lang->getLocale();
        $languageId = (int) $lang->getId();
        $liveCount = $this->pages->countLive();
        $trashCount = $this->pages->countInTrash();
        // Read once for the whole screen: the filter bar, the chips and the
        // rows all name tags and types.
        $tags = $this->tags->all();
        $types = $this->types->allDetails();
        $tagNames = [];

        foreach ($tags as $tag) {
            $tagNames[(int) $tag->getId()] = (string) $tag->getName();
        }

        $typeNames = array_map(static fn (PageTypeDetails $details): string => $details->title($locale), $types);

        $authors = $this->pages->authors();

        $filters = $filters->knowing($tagNames, $typeNames, $authors);
        // A page that is gone, in the bin or a model holds no page to list.
        $section = null === $filters->inside ? null : $this->pages->section($filters->inside, $locale);

        if (null === $section) {
            $filters = $filters->withoutFilter(PageFilters::INSIDE);
        }

        $filters = $this->unfoldSmallSite($filters, $liveCount);
        $results = $filters->isFiltering() ? $this->pages->results($locale, $filters) : null;
        $rows = null === $results ? $this->pages->branch($locale, $filters) : $results->rows;
        // The pages of one page, nothing else asked, are siblings in the order of
        // the site: they move like the rows of the tree.
        $siblings = null !== $results && $filters->isInsideOnly() ? $results : null;

        return [
            'is_filtering' => $filters->isFiltering(),
            'rows' => $this->rows($rows, $filters, $languageId, $locale, null === $results, $this->stalePages->ids($locale, array_map(static fn (PageListRow $row): int => $row->id(), $rows)), $tags, $typeNames, $siblings),
            'results' => $results,
            'section' => null === $section ? null : $this->section($section, $filters, $languageId),
            'reorderable' => null === $results || null !== $siblings,
            // A changed script is fetched again, not read from the cache of the browser.
            'tree_script_version' => substr(hash('xxh3', (string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/page-tree.js')), 0, 12),
            'live_count' => $liveCount,
            'filters' => $this->filterBar->present($filters, $languageId, $trashCount, $tagNames, $typeNames, $this->pages->statusCounts($locale), $authors, $section?->title),
            'filter_query' => $filters->toQueryParams(),
            // The selection is offered on the flat list only: on the tree, a
            // folded branch would go to the bin under a box nobody saw ticked.
            'bulk' => $filters->isFiltering() ? [
                'url' => $this->urls->generate('admin.cms.pages.bulk', [EditLanguage::PARAMETER => $languageId, ...$filters->toQueryParams()]),
                'tags' => $tagNames,
                'no_tag' => CmsPageBulkController::NO_TAG,
                'language' => (string) $lang->getTitle(),
                // A changed script is fetched again, not read from the cache of the browser.
                'script_version' => substr(hash('xxh3', (string) @filemtime(__DIR__.'/../../templates/backOffice/default-twig/assets/page-bulk.js')), 0, 12),
            ] : null,
            'edit_locale' => $locale,
            'edit_language_id' => $languageId,
            'home_page_id' => (int) TheliaCMS::getConfigValue('home_page_id', 0),
            'trash_count' => $trashCount,
            // Pages online with the installer text still on them. Named here
            // rather than only counted somewhere else: this is the screen
            // somebody opens to go and write them.
            'sample_text_pages' => $this->sampleTextPages->publishedPagesStillHoldingSampleText($locale),
            // Pages online with an image nobody described or a link that says
            // nothing: published before the check, or by a path that skips it.
            'accessibility_pages' => $this->accessibilityPages->publishedPagesWithProblems($locale),
            'page_templates' => $this->pageTemplates($locale, $types),
        ];
    }

    /**
     * The types a page can be started from: the ones with a model, a brief
     * or fields. A type that is only a code is picked in the form of a page.
     *
     * @param array<string, PageTypeDetails> $types by code
     *
     * @return list<array{code: string, title: string, has_model: bool}>
     */
    private function pageTemplates(string $locale, array $types): array
    {
        $models = $this->types->modelIds();
        $templates = [];

        foreach ($types as $code => $details) {
            if (!isset($models[$code]) && !$details->isTemplate()) {
                continue;
            }

            $templates[] = ['code' => $code, 'title' => $details->title($locale), 'has_model' => isset($models[$code])];
        }

        return $templates;
    }

    /**
     * What the head of the list says about the page whose pages it shows: the
     * way down to it, each step a list of its own, and where a new page starts
     * under it.
     *
     * @return array{id: int, title: string, trail: list<array{title: string, url: string}>, all_url: string, create_url: string, edit_url: string}
     */
    private function section(PageSection $section, PageFilters $filters, int $languageId): array
    {
        // The new page and the settings come back to this list.
        $return = $filters->toReturnParameter();
        $returnQuery = null === $return ? [] : [PageFilters::RETURN => $return];

        return [
            'id' => $section->id,
            'title' => $section->title,
            'trail' => array_map(
                fn (array $step): array => ['title' => $step['title'], 'url' => $this->filterBar->listUrl($filters->inside($step['id']), $languageId)],
                $section->trail,
            ),
            // The tree, with its branches as they were left.
            'all_url' => $this->filterBar->listUrl(new PageFilters(open: $filters->open, foldChosen: $filters->foldChosen), $languageId),
            'create_url' => $this->urls->generate('admin.cms.pages.create', [EditLanguage::PARAMETER => $languageId, 'parent' => $section->id, ...$returnQuery]),
            'edit_url' => $this->route('admin.cms.pages.edit', $section->id, $languageId, extra: $returnQuery),
        ];
    }

    /**
     * A site nobody needs to fold is not folded.
     *
     * Adding branches to the listing must not cost a click to the sites that were
     * fine without them, so under a few dozen pages the whole tree opens by
     * itself. Once the editor folds something, that choice is in the address and
     * this stops applying.
     */
    private function unfoldSmallSite(PageFilters $filters, int $liveCount): PageFilters
    {
        if ($filters->isFiltering() || $filters->foldChosen || $liveCount > PageFilters::OPEN_EVERYTHING_UNDER) {
            return $filters;
        }

        return $filters->withEverythingOpen($this->pages->branchingPageIds());
    }

    /**
     * @param list<PageListRow>     $rows
     * @param array<int, true>      $stale     the pages to translate again in this language, by id
     * @param list<CmsTag>          $allTags   every tag of the site, read once for the whole listing:
     *                                         asking each page for its tag would be a query per line
     * @param array<string, string> $typeNames the names of the page types, by code
     * @param PageResults|null      $siblings  the results when they are the pages of one page and nothing else, which move among themselves
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, PageFilters $filters, int $languageId, string $locale, bool $isTree, array $stale, array $allTags, array $typeNames, ?PageResults $siblings = null): array
    {
        $firstOfParent = [];
        $lastOfParent = [];
        $tags = [];

        foreach ($allTags as $tag) {
            $color = PageTagColor::fromStored($tag->getColor(), $tag->getTextColor());
            $tags[(int) $tag->getId()] = [
                'name' => (string) $tag->getName(),
                'badge' => PageTagColor::badgeClassOf($color),
                'badge_style' => PageTagColor::badgeStyleOf($color),
            ];
        }

        // Siblings always come out of the tree walk together and in position
        // order, so the ends of each group are the pages that cannot move
        // further. Computed here rather than asked per row: the alternative is a
        // query per line to find out whether a page is the last of its siblings.
        foreach ($rows as $index => $row) {
            $parent = (int) $row->page->getParent();
            $firstOfParent[$parent] ??= $index;
            $lastOfParent[$parent] = $index;
        }

        $presented = [];
        $titles = [];
        // The screens a row opens carry the state of the list, so their "back to
        // the list" lands on this list and not on the folded tree.
        $return = $filters->toReturnParameter();
        $returnQuery = null === $return ? [] : [PageFilters::RETURN => $return];

        foreach ($rows as $index => $row) {
            $parent = (int) $row->page->getParent();
            $id = $row->id();
            // Among the pages of one page, the ends are those of all of them, not
            // of the page of results on screen.
            $rank = null === $siblings ? null : ($siblings->page - 1) * $siblings->perPage + $index;

            $localTitle = trim((string) $row->page->getTitle());
            // A branch comes after its page: the title of a parent is known by then.
            $titles[$id] = $this->title($row);

            $presented[] = [
                'id' => $id,
                'title' => $this->title($row),
                // True when the name on screen comes from another language, so the
                // row can say the page still has to be written in this one.
                'is_untranslated' => '' === $localTitle,
                // Written from another language whose draft changed since.
                'is_stale_translation' => isset($stale[$id]),
                'address' => $row->address,
                // Opened from the list only when visitors can read it: the
                // address of a page that is not online answers nothing.
                'public_url' => $row->status->isOnline() && '' !== $row->address ? $this->alternates->onSiteOf($row->address, $locale) : null,
                'depth' => $row->depth,
                'level' => $row->depth + 1,
                'child_count' => $row->childCount,
                'is_open' => $row->isOpen,
                'status_label' => $row->status->label(),
                'status_badge' => $row->status->badgeClass(),
                // The listing marks a hidden page once. Which of the two marks it
                // keeps is decided here, on the enum, and not in the template on
                // the text of a label that translation would change.
                'is_unpublished' => PageStatus::Unpublished === $row->status,
                'visible' => 1 === $row->page->getVisible(),
                // Each page above, a link to the list of its pages.
                'ancestors' => array_map(
                    fn (int $ancestorId, string $title): array => ['title' => $title, 'url' => $this->filterBar->listUrl($filters->inside($ancestorId), $languageId)],
                    $row->ancestorIds,
                    $row->ancestorTitles,
                ),
                'children_url' => $row->hasChildren() ? $this->filterBar->listUrl($filters->inside($id), $languageId) : null,
                'anchor' => self::rowAnchor($id),
                // The pages of the branch the tree leaves out after this one.
                'more' => $row->moreSiblings > 0 ? [
                    'count' => $row->moreSiblings,
                    'parent_id' => $parent,
                    'parent_title' => $titles[$parent] ?? '',
                    'url' => $this->filterBar->listUrl($filters->inside($parent), $languageId),
                ] : null,
                'tag' => $tags[(int) $row->page->getTagId()]['name'] ?? null,
                'tag_badge' => $tags[(int) $row->page->getTagId()]['badge'] ?? PageTagColor::NO_COLOR_BADGE,
                'tag_badge_style' => $tags[(int) $row->page->getTagId()]['badge_style'] ?? '',
                'modified_at' => $row->modifiedAt,
                // A type the site no longer describes is named by its code.
                'type' => $typeNames[(string) $row->page->getPageType()] ?? (string) $row->page->getPageType(),
                'toggle_url' => $row->hasChildren() ? $this->filterBar->toggleUrl($filters, $languageId, $id) : null,
                'edit_url' => $this->route('admin.cms.pages.edit', $id, $languageId, extra: $returnQuery),
                'builder_url' => $this->route('admin.cms.pages.content.edit', $id, $languageId, extra: $returnQuery),
                // The actions that come back to this screen carry the state of it
                // in their address, so acting on a row does not fold the tree the
                // editor just opened or drop the search they are working from.
                'visibility_url' => $this->route('admin.cms.pages.visibility', $id, $languageId, $filters),
                'delete_url' => $this->route('admin.cms.pages.delete', $id, $languageId, $filters),
                'move_up_url' => $this->moveUrl($id, 'up', $languageId, $filters),
                'move_down_url' => $this->moveUrl($id, 'down', $languageId, $filters),
                // Reordering is only offered on the tree: a position moved from a
                // list of results would be a position among siblings the editor
                // cannot see.
                'can_move_up' => null !== $rank ? $rank > 0 : $isTree && $firstOfParent[$parent] !== $index,
                'can_move_down' => null !== $rank ? $rank < $siblings->total - 1 : $isTree && ($lastOfParent[$parent] !== $index || $row->moreSiblings > 0),
            ];
        }

        return $presented;
    }

    /**
     * The id of the row of a page on the list, which a link or a redirection
     * names to land on it.
     */
    public static function rowAnchor(int $pageId): string
    {
        return 'cms-page-'.$pageId;
    }

    /**
     * The name the row shows: the title in the language being edited, the title in
     * another language when there is none, and the identifier only when the page
     * has never been given a name at all.
     */
    private function title(PageListRow $row): string
    {
        $title = trim((string) $row->page->getTitle());

        if ('' !== $title) {
            return $title;
        }

        return $row->titleInAnotherLanguage ?? '#'.$row->id();
    }

    /**
     * @param array<string, string> $extra
     */
    private function route(string $name, int $id, int $languageId, ?PageFilters $filters = null, array $extra = []): string
    {
        return $this->urls->generate($name, [
            'id' => $id,
            EditLanguage::PARAMETER => $languageId,
            ...$filters?->toQueryParams() ?? [],
            ...$extra,
        ]);
    }

    private function moveUrl(int $id, string $direction, int $languageId, PageFilters $filters): string
    {
        return $this->urls->generate('admin.cms.pages.move', [
            'id' => $id,
            'direction' => $direction,
            EditLanguage::PARAMETER => $languageId,
            ...$filters->toQueryParams(),
        ]);
    }
}
