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

use TheliaCMS\Model\CmsPage;

/**
 * One line of the page listing, carrying everything the template needs.
 *
 * The state, the address, the number of pages underneath and the names of the
 * pages above are all read in batches before the rows are built. A template that
 * asks the page object for them instead issues one query per line, which is what
 * this screen used to do: 637 pages cost 1963 statements.
 */
final readonly class PageListRow
{
    /**
     * @param int                     $depth                  0 for a page at the root of the site
     * @param string                  $address                the address visitors use, leading slash included
     * @param int                     $childCount             pages directly underneath, bin excluded
     * @param bool                    $isOpen                 whether the branch is unfolded
     * @param list<string>            $ancestorTitles         the pages above it, closest last
     * @param list<int>               $ancestorIds            the same pages, by id, in the same order
     * @param string|null             $titleInAnotherLanguage a title to show when this language has none
     * @param \DateTimeImmutable|null $modifiedAt             the last change of the page or of its content in this language
     */
    public function __construct(
        public CmsPage $page,
        public int $depth,
        public PageStatus $status,
        public string $address,
        public int $childCount,
        public bool $isOpen = false,
        public array $ancestorTitles = [],
        public array $ancestorIds = [],
        public ?string $titleInAnotherLanguage = null,
        public ?\DateTimeImmutable $modifiedAt = null,
    ) {
    }

    /**
     * The latest of the page and of its content: saving the content does not
     * touch the page row, so the page alone would date a page rewritten this
     * morning from the day its settings were last saved.
     *
     * The same rule as the sort of the listing, CmsPageAdminRepository::modifiedSql().
     */
    public static function latestOf(mixed $page, mixed $content): ?\DateTimeImmutable
    {
        $dates = array_values(array_filter(
            [$page, $content],
            static fn (mixed $date): bool => $date instanceof \DateTimeInterface,
        ));

        if ([] === $dates) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface(max($dates));
    }

    public function id(): int
    {
        return (int) $this->page->getId();
    }

    public function hasChildren(): bool
    {
        return $this->childCount > 0;
    }

    /**
     * The path an editor recognises, for the rows shown outside the tree: on a
     * list of results, indentation has nothing to indent from.
     */
    public function ancestorPath(): string
    {
        return implode(' / ', $this->ancestorTitles);
    }
}
