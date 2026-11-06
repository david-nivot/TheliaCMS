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
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\TheliaCMS;

/**
 * Builds what the filter bar of the page listing shows: the state of each
 * control, the chips naming what is currently on, and the address that removes
 * each of them.
 *
 * The addresses are built here rather than in the template because removing one
 * filter has to keep the others, the language being edited and the branches that
 * are unfolded, and a template assembling that by hand loses one of them.
 */
final readonly class PageFilterPresenter
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<int, string>      $tags    the names of the tags of the site, by id
     * @param array<string, string>   $types   the names of the page types, by code
     * @param array<string, int>      $counts  the live pages in each state, by state, the whole site
     * @param array<int, string|null> $authors the administrators who changed a page last, by id, null for a deleted account
     *
     * @return array{
     *     parameters: array{search: string, statuses: string, visibility: string, tag: string, type: string, sort: string, date: string, from: string, to: string, author: string},
     *     search: string,
     *     is_filtering: bool,
     *     is_empty: bool,
     *     advanced_count: int,
     *     status_options: list<array{value: string, label: string, badge: string, checked: bool, count: int}>,
     *     visibility: string,
     *     tag_options: list<array{value: string, label: string, selected: bool}>,
     *     type_options: list<array{value: string, label: string, selected: bool}>,
     *     sort_options: list<array{value: string, label: string, selected: bool}>,
     *     date_options: list<array{value: string, label: string, selected: bool}>,
     *     from: string,
     *     to: string,
     *     author_options: list<array{value: string, label: string, selected: bool}>,
     *     sort_headers: array<string, array{url: string, aria_sort: string}>,
     *     reverse_url: string|null,
     *     chips: list<array{label: string, remove_url: string}>,
     *     sort_chip: array{label: string, remove_url: string}|null,
     *     clear_url: string,
     *     fold_all_url: string|null,
     *     trash_url: string,
     *     trash_count: int,
     *     hidden_params: array<string, string>,
     * }
     */
    public function present(PageFilters $filters, int $languageId, int $trashCount, array $tags = [], array $types = [], array $counts = [], array $authors = []): array
    {
        return [
            // The names the form posts under, taken from the object that reads
            // them back, so a rename cannot leave the two out of step.
            'parameters' => [
                'search' => PageFilters::SEARCH,
                'statuses' => PageFilters::STATUSES,
                'visibility' => PageFilters::VISIBILITY,
                'tag' => PageFilters::TAG,
                'type' => PageFilters::TYPE,
                'sort' => PageFilters::SORT,
                'date' => PageFilters::DATE,
                'from' => PageFilters::FROM,
                'to' => PageFilters::TO,
                'author' => PageFilters::AUTHOR,
            ],
            'search' => $filters->search,
            'is_filtering' => $filters->isFiltering(),
            'is_empty' => $filters->isEmpty(),
            'advanced_count' => $filters->advancedCount(),
            'status_options' => $this->statusOptions($filters, $counts),
            'visibility' => match ($filters->visible) {
                true => PageFilters::VISIBLE_ONLY,
                false => PageFilters::HIDDEN_ONLY,
                null => '',
            },
            // A select with nothing to choose from is a control that does
            // nothing: no tag on the site, or a single type, hides it.
            'tag_options' => [] === $tags ? [] : $this->tagOptions($filters, $tags),
            'type_options' => \count($types) < 2 ? [] : $this->typeOptions($filters, $types),
            // Chosen in the bar, or by the headers of the columns it sorts on;
            // the bar starts a sort in its own direction, the header reverses it.
            'sort_options' => $this->sortOptions($filters),
            'date_options' => $this->dateOptions($filters),
            'from' => $filters->from?->format(PageFilters::DAY_FORMAT) ?? '',
            'to' => $filters->to?->format(PageFilters::DAY_FORMAT) ?? '',
            'author_options' => $this->authorOptions($filters, $authors),
            'sort_headers' => $this->sortHeaders($filters, $languageId),
            // The creation date has no column, so no header to reverse it from.
            'reverse_url' => null === $filters->sort
                ? null
                : $this->listUrl($filters->sortedBy($filters->sort), $languageId),
            'chips' => $this->chips($filters, $languageId, $tags, $types, $authors),
            'sort_chip' => $this->sortChip($filters, $languageId),
            'clear_url' => $this->listUrl(new PageFilters(open: $filters->open, foldChosen: $filters->foldChosen), $languageId),
            // Offered only when something is unfolded, and there is no "unfold
            // everything": on a site of several hundred pages that button gives
            // back the wall of rows this screen exists to avoid.
            'fold_all_url' => [] === $filters->open
                ? null
                : $this->listUrl($filters->withoutAnyBranchOpen(), $languageId),
            'trash_url' => $this->urls->generate('admin.cms.pages.trash', array_filter([
                EditLanguage::PARAMETER => $languageId,
                PageFilters::SEARCH => '' === $filters->search ? null : $filters->search,
            ], static fn (mixed $value): bool => null !== $value)),
            'trash_count' => $trashCount,
            // Carried through the filter form so submitting it does not fold the
            // tree or switch the language back.
            'hidden_params' => array_filter([
                EditLanguage::PARAMETER => (string) $languageId,
                PageFilters::OPEN => $filters->foldChosen || [] !== $filters->open
                    ? implode(',', $filters->open)
                    : null,
            ], static fn (?string $value): bool => null !== $value),
        ];
    }

    /**
     * The address of the listing with one branch folded or unfolded.
     */
    public function toggleUrl(PageFilters $filters, int $languageId, int $pageId): string
    {
        return $this->listUrl($filters->toggling($pageId), $languageId);
    }

    public function listUrl(PageFilters $filters, int $languageId): string
    {
        return $this->urls->generate('admin.cms.pages.list', [
            EditLanguage::PARAMETER => $languageId,
            ...$filters->toQueryParams(),
        ]);
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function sortOptions(PageFilters $filters): array
    {
        return array_map(
            fn (PageSort $sort): array => [
                'value' => $sort->value,
                'label' => $this->translate($sort->label()),
                'selected' => $sort === $filters->sort,
            ],
            PageSort::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function dateOptions(PageFilters $filters): array
    {
        return array_map(
            fn (PageDate $date): array => [
                'value' => $date->value,
                'label' => $this->translate($date->label()),
                'selected' => $date === ($filters->date ?? PageDate::Modified),
            ],
            PageDate::cases(),
        );
    }

    /**
     * @param array<int, string|null> $authors
     *
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function authorOptions(PageFilters $filters, array $authors): array
    {
        $options = [];

        foreach ($authors as $id => $name) {
            $options[] = ['value' => (string) $id, 'label' => $this->authorName($id, $name), 'selected' => $id === $filters->author];
        }

        return $options;
    }

    /**
     * An account deleted since is named by its number: two of them would
     * otherwise read the same.
     */
    private function authorName(int $id, ?string $name): string
    {
        return $name ?? $this->translate('Deleted account #%id%', ['%id%' => (string) $id]);
    }

    /**
     * The address and the state of the columns a click sorts on, by sort.
     *
     * @return array<string, array{url: string, aria_sort: string}>
     */
    private function sortHeaders(PageFilters $filters, int $languageId): array
    {
        $headers = [];

        foreach ([PageSort::Title, PageSort::Modified] as $sort) {
            $headers[$sort->value] = [
                'url' => $this->listUrl($filters->sortedBy($sort), $languageId),
                'aria_sort' => $sort !== $filters->sort ? 'none' : ($filters->isDescending() ? 'descending' : 'ascending'),
            ];
        }

        return $headers;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{value: string, label: string, badge: string, checked: bool, count: int}>
     */
    private function statusOptions(PageFilters $filters, array $counts): array
    {
        return array_map(
            static fn (PageStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'badge' => $status->badgeClass(),
                'checked' => \in_array($status, $filters->statuses, true),
                // Zero stays a box like the others: ticking it says "none", which
                // is an answer, and a box that vanishes moves the ones after it.
                'count' => $counts[$status->value] ?? 0,
            ],
            PageStatus::cases(),
        );
    }

    /**
     * @param array<int, string> $tags
     *
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function tagOptions(PageFilters $filters, array $tags): array
    {
        $options = [[
            'value' => PageFilters::UNTAGGED,
            'label' => $this->translate('Pages without a tag'),
            'selected' => PageFilters::NO_TAG === $filters->tag,
        ]];

        foreach ($tags as $id => $name) {
            $options[] = ['value' => (string) $id, 'label' => $name, 'selected' => $id === $filters->tag];
        }

        return $options;
    }

    /**
     * @param array<string, string> $types
     *
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function typeOptions(PageFilters $filters, array $types): array
    {
        $options = [];

        foreach ($types as $code => $name) {
            $options[] = ['value' => $code, 'label' => $name, 'selected' => $code === $filters->type];
        }

        return $options;
    }

    /**
     * @param array<int, string>      $tags
     * @param array<string, string>   $types
     * @param array<int, string|null> $authors
     *
     * @return list<array{label: string, remove_url: string}>
     */
    private function chips(PageFilters $filters, int $languageId, array $tags, array $types, array $authors): array
    {
        $chips = [];

        if ('' !== $filters->search) {
            $chips[] = [
                'label' => $this->translate('Containing "%word%"', ['%word%' => $filters->search]),
                'remove_url' => $this->listUrl($filters->withoutFilter(PageFilters::SEARCH), $languageId),
            ];
        }

        foreach ($filters->statuses as $status) {
            $chips[] = [
                'label' => $this->translate($status->label()),
                'remove_url' => $this->listUrl($filters->withoutStatus($status), $languageId),
            ];
        }

        if (null !== $filters->visible) {
            $chips[] = [
                'label' => $filters->visible
                    ? $this->translate('Shown on the site')
                    : $this->translate('Hidden from the site'),
                'remove_url' => $this->listUrl($filters->withoutFilter(PageFilters::VISIBILITY), $languageId),
            ];
        }

        if (null !== $filters->tag) {
            $chips[] = [
                'label' => PageFilters::NO_TAG === $filters->tag
                    ? $this->translate('Without a tag')
                    : $this->translate('Tag: %name%', ['%name%' => $tags[$filters->tag] ?? '#'.$filters->tag]),
                'remove_url' => $this->listUrl($filters->withoutFilter(PageFilters::TAG), $languageId),
            ];
        }

        if (null !== $filters->type) {
            $chips[] = [
                'label' => $this->translate('Type: %name%', ['%name%' => $types[$filters->type] ?? $filters->type]),
                'remove_url' => $this->listUrl($filters->withoutFilter(PageFilters::TYPE), $languageId),
            ];
        }

        if (null !== $filters->date) {
            $chips[] = [
                'label' => $this->translate('%date%: %period%', [
                    '%date%' => $this->translate($filters->date->label()),
                    '%period%' => $this->period($filters->from, $filters->to),
                ]),
                'remove_url' => $this->listUrl($filters->withoutFilter(PageFilters::DATE), $languageId),
            ];
        }

        if (null !== $filters->author) {
            $chips[] = [
                'label' => $this->translate('Last changed by %name%', ['%name%' => $this->authorName($filters->author, $authors[$filters->author] ?? null)]),
                'remove_url' => $this->listUrl($filters->withoutFilter(PageFilters::AUTHOR), $languageId),
            ];
        }

        return $chips;
    }

    private function period(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): string
    {
        return match (true) {
            null === $to => $this->translate('from %from%', ['%from%' => (string) $from?->format('d/m/Y')]),
            null === $from => $this->translate('until %to%', ['%to%' => $to->format('d/m/Y')]),
            default => $this->translate('from %from% to %to%', ['%from%' => $from->format('d/m/Y'), '%to%' => $to->format('d/m/Y')]),
        };
    }

    /**
     * The chip of the sort, apart from the filters: a sort keeps every page,
     * so it cannot follow "Showing only".
     *
     * @return array{label: string, remove_url: string}|null
     */
    private function sortChip(PageFilters $filters, int $languageId): ?array
    {
        if (null === $filters->sort) {
            return null;
        }

        // Removing the sort gives the tree back when nothing else is on.
        return [
            'label' => $this->translate($filters->sort->chipLabel($filters->isDescending())),
            'remove_url' => $this->listUrl($filters->withoutFilter(PageFilters::SORT), $languageId),
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function translate(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }
}
