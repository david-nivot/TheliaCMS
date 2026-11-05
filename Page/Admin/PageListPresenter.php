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
use TheliaCMS\Page\PageTagColor;
use TheliaCMS\Page\PageTagRepository;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\SampleTextPageFinder;
use TheliaCMS\Page\StalePages;
use TheliaCMS\TheliaCMS;

/**
 * Everything the page listing renders, assembled in one place.
 *
 * The screen has two shapes and the choice between them is one rule: with a word
 * to look for or a state to keep, it is a list of results; with nothing asked, it
 * is the tree. Both produce the same kind of row, so the template has one table
 * and not two.
 */
final readonly class PageListPresenter
{
    public function __construct(
        private CmsPageAdminRepository $pages,
        private PageFilterPresenter $filterBar,
        private SampleTextPageFinder $sampleTextPages,
        private UrlGeneratorInterface $urls,
        private PageTagRepository $tags,
        private PageTypeRepository $types,
        private StalePages $stalePages,
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

        $filters = $this->unfoldSmallSite($filters, $liveCount);
        $results = $filters->isFiltering() ? $this->pages->results($locale, $filters) : null;
        $rows = null === $results ? $this->pages->branch($locale, $filters) : $results->rows;

        return [
            'is_filtering' => $filters->isFiltering(),
            'rows' => $this->rows($rows, $filters, $languageId, null === $results, $this->stalePages->ids($locale, array_map(static fn (PageListRow $row): int => $row->id(), $rows))),
            'results' => $results,
            'live_count' => $liveCount,
            'filters' => $this->filterBar->present($filters, $languageId, $trashCount),
            'filter_query' => $filters->toQueryParams(),
            'edit_locale' => $locale,
            'edit_language_id' => $languageId,
            'home_page_id' => (int) TheliaCMS::getConfigValue('home_page_id', 0),
            'trash_count' => $trashCount,
            // Pages online with the installer text still on them. Named here
            // rather than only counted somewhere else: this is the screen
            // somebody opens to go and write them.
            'sample_text_pages' => $this->sampleTextPages->publishedPagesStillHoldingSampleText($locale),
            'page_templates' => $this->pageTemplates($locale),
        ];
    }

    /**
     * The types a page can be started from: the ones with a model, a brief
     * or fields. A type that is only a code is picked in the form of a page.
     *
     * @return list<array{code: string, title: string, has_model: bool}>
     */
    private function pageTemplates(string $locale): array
    {
        $models = $this->types->modelIds();
        $templates = [];

        foreach ($this->types->allDetails() as $code => $details) {
            if (!isset($models[$code]) && !$details->isTemplate()) {
                continue;
            }

            $templates[] = ['code' => $code, 'title' => $details->title($locale), 'has_model' => isset($models[$code])];
        }

        return $templates;
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
     * @param list<PageListRow> $rows
     * @param array<int, true>  $stale the pages to translate again in this language, by id
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, PageFilters $filters, int $languageId, bool $isTree, array $stale): array
    {
        $firstOfParent = [];
        $lastOfParent = [];
        // One query for the whole listing: asking each page for its tag would
        // be a query per line.
        $tags = [];

        foreach ($this->tags->all() as $tag) {
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

        foreach ($rows as $index => $row) {
            $parent = (int) $row->page->getParent();
            $id = $row->id();

            $localTitle = trim((string) $row->page->getTitle());

            $presented[] = [
                'id' => $id,
                'title' => $this->title($row),
                // True when the name on screen comes from another language, so the
                // row can say the page still has to be written in this one.
                'is_untranslated' => '' === $localTitle,
                // Written from another language whose draft changed since.
                'is_stale_translation' => isset($stale[$id]),
                'address' => $row->address,
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
                'ancestor_path' => $row->ancestorPath(),
                'tag' => $tags[(int) $row->page->getTagId()]['name'] ?? null,
                'tag_badge' => $tags[(int) $row->page->getTagId()]['badge'] ?? PageTagColor::NO_COLOR_BADGE,
                'tag_badge_style' => $tags[(int) $row->page->getTagId()]['badge_style'] ?? '',
                'toggle_url' => $row->hasChildren() ? $this->filterBar->toggleUrl($filters, $languageId, $id) : null,
                'edit_url' => $this->route('admin.cms.pages.edit', $id, $languageId),
                'builder_url' => $this->route('admin.cms.pages.content.edit', $id, $languageId),
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
                'can_move_up' => $isTree && $firstOfParent[$parent] !== $index,
                'can_move_down' => $isTree && $lastOfParent[$parent] !== $index,
            ];
        }

        return $presented;
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

    private function route(string $name, int $id, int $languageId, ?PageFilters $filters = null): string
    {
        return $this->urls->generate($name, [
            'id' => $id,
            EditLanguage::PARAMETER => $languageId,
            ...$filters?->toQueryParams() ?? [],
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
