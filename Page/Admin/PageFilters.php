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

use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Page\PageTypeCode;

/**
 * What the editor asked the page listing to show.
 *
 * The listing has two shapes, and this object decides which one: with nothing
 * asked it is the tree, opened branch by branch; with a word to look for, a
 * state, a tag, a type, a period or an author to keep it is a flat list of
 * results, paginated and ordered by title unless a sort says otherwise.
 *
 * A sort does the same: an order other than the positions of the tree is an
 * order of rows that are no longer siblings.
 *
 * They are not two screens. A tree hiding the rows that do not match is a tree
 * whose remaining indentation lies about who the parents are, and the position
 * buttons on it would move a page among siblings it cannot see. Once something
 * is filtered, the parent of a row is written out instead of being drawn.
 */
final readonly class PageFilters
{
    public const string SEARCH = 'q';
    public const string STATUSES = 'status';
    public const string VISIBILITY = 'visibility';
    public const string PAGE = 'page';
    public const string OPEN = 'open';
    public const string TAG = 'tag';
    public const string TYPE = 'type';
    public const string SORT = 'sort';
    public const string DIRECTION = 'dir';
    public const string DATE = 'date';
    public const string FROM = 'from';
    public const string TO = 'to';
    public const string AUTHOR = 'author';
    public const string INSIDE = 'inside';

    /** How a day is written in the address: the value of an `<input type="date">`. */
    public const string DAY_FORMAT = 'Y-m-d';

    public const string ASCENDING = 'asc';
    public const string DESCENDING = 'desc';

    /**
     * The state of the listing carried by the screens opened from it, so their
     * "back to the list" link lands on the list the editor left.
     */
    public const string RETURN = 'return';

    /** The parameters a return may hold, and nothing else. */
    private const array RETURNABLE = [self::SEARCH, self::STATUSES, self::VISIBILITY, self::PAGE, self::OPEN, self::TAG, self::TYPE, self::SORT, self::DIRECTION, self::DATE, self::FROM, self::TO, self::AUTHOR, self::INSIDE];

    /** A state of the listing never needs more; anything longer is refused. */
    private const int RETURN_MAX_LENGTH = 1000;

    public const string VISIBLE_ONLY = 'online';
    public const string HIDDEN_ONLY = 'offline';

    /** What the address says for "the pages that carry no tag". */
    public const string UNTAGGED = 'none';

    /** The tag filter of the pages that carry no tag: no tag has id 0. */
    public const int NO_TAG = 0;

    /** Rows per page of results. */
    public const int PER_PAGE = 50;

    /**
     * A site small enough to be read whole is shown whole, so that adding search
     * to the listing does not cost a click to every site that never needed it.
     */
    public const int OPEN_EVERYTHING_UNDER = 40;

    /**
     * @param list<PageStatus>        $statuses
     * @param list<int>               $open       branches the editor unfolded
     * @param int|null                $tag        the tag to keep, NO_TAG for the pages
     *                                            without one, null for any
     * @param string|null             $type       the code of the page type to keep
     * @param bool|null               $descending the direction of the sort, null for
     *                                            the one the sort starts in
     * @param PageDate|null           $date       the date the period applies to, null
     *                                            when there is no period
     * @param \DateTimeImmutable|null $from       the first day kept, at midnight
     * @param \DateTimeImmutable|null $to         the last day kept, at midnight
     * @param int|null                $author     the administrator who changed the
     *                                            pages last
     * @param int|null                $inside     the page whose pages are listed, the
     *                                            ones directly under it
     * @param bool                    $foldChosen whether the editor said anything about
     *                                            folding at all, which is not the same
     *                                            as having nothing open: a small site
     *                                            opens by itself, and closing its only
     *                                            branch leaves an empty list that would
     *                                            otherwise read as "never chose" and
     *                                            open it again
     */
    public function __construct(
        public string $search = '',
        public array $statuses = [],
        public ?bool $visible = null,
        public int $page = 1,
        public array $open = [],
        public bool $foldChosen = false,
        public ?int $tag = null,
        public ?string $type = null,
        public ?PageSort $sort = null,
        public ?bool $descending = null,
        public ?PageDate $date = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?int $author = null,
        public ?int $inside = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query;

        $statuses = [];

        foreach ((array) $query->all(self::STATUSES) as $value) {
            $status = \is_string($value) ? PageStatus::tryFrom($value) : null;

            if (null !== $status && !\in_array($status, $statuses, true)) {
                $statuses[] = $status;
            }
        }

        $visibility = trim((string) $query->get(self::VISIBILITY, ''));
        [$date, $from, $to] = self::readPeriod(
            trim((string) $query->get(self::DATE, '')),
            trim((string) $query->get(self::FROM, '')),
            trim((string) $query->get(self::TO, '')),
        );

        return new self(
            search: trim((string) $query->get(self::SEARCH, '')),
            statuses: $statuses,
            visible: match ($visibility) {
                self::VISIBLE_ONLY => true,
                self::HIDDEN_ONLY => false,
                default => null,
            },
            page: max(1, $query->getInt(self::PAGE, 1)),
            open: self::readOpen((string) $query->get(self::OPEN, '')),
            foldChosen: $query->has(self::OPEN),
            tag: self::readTag(trim((string) $query->get(self::TAG, ''))),
            type: self::readType(trim((string) $query->get(self::TYPE, ''))),
            sort: PageSort::tryFrom(trim((string) $query->get(self::SORT, ''))),
            descending: match (trim((string) $query->get(self::DIRECTION, ''))) {
                self::ASCENDING => false,
                self::DESCENDING => true,
                default => null,
            },
            date: $date,
            from: $from,
            to: $to,
            author: self::readId(trim((string) $query->get(self::AUTHOR, ''))),
            inside: self::readId(trim((string) $query->get(self::INSIDE, ''))),
        );
    }

    /**
     * The state written in a return parameter, or the plain listing when the
     * parameter holds anything else than the filters of the listing.
     *
     * Read back through fromRequest() and written out again by toQueryParams(),
     * never echoed: a return is a list of filters, not an address, so it cannot
     * send the editor anywhere but the page list.
     */
    public static function fromReturnParameter(string $raw): self
    {
        if ('' === $raw || \strlen($raw) > self::RETURN_MAX_LENGTH) {
            return new self();
        }

        parse_str($raw, $parameters);

        if ([] !== array_diff(array_map(strval(...), array_keys($parameters)), self::RETURNABLE)) {
            return new self();
        }

        try {
            return self::fromRequest(new Request($parameters));
        } catch (\UnexpectedValueException) {
            // A list where a word is expected, or the other way round.
            return new self();
        }
    }

    /**
     * The return parameter of a request, rebuilt from the filters it holds, to
     * pass on to the next screen; nothing when there is none.
     *
     * @return array<string, string>
     */
    public static function returnQuery(Request $request): array
    {
        $return = self::fromReturnParameter((string) $request->query->get(self::RETURN, ''))->toReturnParameter();

        return null === $return ? [] : [self::RETURN => $return];
    }

    /**
     * This state as a return parameter, or null for the plain listing, which
     * needs none.
     */
    public function toReturnParameter(): ?string
    {
        $params = $this->toQueryParams();

        return [] === $params ? null : http_build_query($params);
    }

    /**
     * The same state without a tag, a type or an author the site does not have.
     *
     * The address only proves a value is shaped like an id or a code; a tag
     * deleted since the address was kept would otherwise filter on nothing and
     * show an empty list with a chip naming nothing.
     *
     * @param array<int, mixed>    $tags    the tags of the site, by id
     * @param array<string, mixed> $types   the page types of the site, by code
     * @param array<int, mixed>    $authors the administrators who changed a page last, by id
     */
    public function knowing(array $tags, array $types, array $authors = []): self
    {
        // "Without a tag" means nothing on a site that has no tag at all.
        $tagKnown = null === $this->tag
            || ([] !== $tags && (self::NO_TAG === $this->tag || isset($tags[$this->tag])));
        $typeKnown = null === $this->type || isset($types[$this->type]);
        // A deleted account is null, and still names the pages it changed.
        $authorKnown = null === $this->author || \array_key_exists($this->author, $authors);

        if ($tagKnown && $typeKnown && $authorKnown) {
            return $this;
        }

        return $this->with(
            page: $this->page,
            unsetTag: !$tagKnown,
            unsetType: !$typeKnown,
            unsetAuthor: !$authorKnown,
        );
    }

    /**
     * Whether the editor asked for something in particular, which is what turns
     * the tree into a list of results.
     */
    public function isFiltering(): bool
    {
        return '' !== $this->search
            || [] !== $this->statuses
            || null !== $this->visible
            || null !== $this->tag
            || null !== $this->type
            || null !== $this->sort
            || null !== $this->date
            || null !== $this->author
            || null !== $this->inside;
    }

    /**
     * Whether the list is the pages of one page and nothing else: in the order
     * of the site, which a move changes, rather than in an order of results.
     */
    public function isInsideOnly(): bool
    {
        return null !== $this->inside
            && '' === $this->search
            && [] === $this->statuses
            && null === $this->visible
            && null === $this->tag
            && null === $this->type
            && null === $this->sort
            && null === $this->date
            && null === $this->author;
    }

    /**
     * Every page directly under this one, nothing else asked: what a click on
     * a page that holds others opens. The branches of the tree stay as they
     * are, for the way back to it.
     */
    public function inside(int $pageId): self
    {
        return new self(open: $this->open, foldChosen: $this->foldChosen, inside: $pageId);
    }

    /**
     * Whether the rows come latest first or Z first.
     */
    public function isDescending(): bool
    {
        return $this->descending ?? $this->sort?->startsDescending() ?? false;
    }

    /**
     * Sorted by this order: the same order again reverses it, another one
     * starts in its own direction.
     */
    public function sortedBy(PageSort $sort): self
    {
        return $this->with(
            sort: $sort,
            descending: $sort === $this->sort ? !$this->isDescending() : $sort->startsDescending(),
        );
    }

    /**
     * Whether anything at all has been touched, unfolded branches included.
     */
    public function isEmpty(): bool
    {
        return !$this->isFiltering() && !$this->foldChosen && [] === $this->open;
    }

    /**
     * How many filters beyond the search box are on, for the badge on the toggle.
     */
    public function advancedCount(): int
    {
        return \count($this->statuses)
            + (null === $this->visible ? 0 : 1)
            + (null === $this->tag ? 0 : 1)
            + (null === $this->type ? 0 : 1)
            + (null === $this->date ? 0 : 1)
            + (null === $this->author ? 0 : 1);
    }

    /**
     * The parameters that describe this state, defaults left out so that a plain
     * listing keeps a plain address.
     *
     * @return array<string, string|list<string>>
     */
    public function toQueryParams(): array
    {
        $params = [];

        if ('' !== $this->search) {
            $params[self::SEARCH] = $this->search;
        }

        if ([] !== $this->statuses) {
            $params[self::STATUSES] = array_map(static fn (PageStatus $status): string => $status->value, $this->statuses);
        }

        if (null !== $this->visible) {
            $params[self::VISIBILITY] = $this->visible ? self::VISIBLE_ONLY : self::HIDDEN_ONLY;
        }

        if (null !== $this->tag) {
            $params[self::TAG] = self::NO_TAG === $this->tag ? self::UNTAGGED : (string) $this->tag;
        }

        if (null !== $this->type) {
            $params[self::TYPE] = $this->type;
        }

        if (null !== $this->sort) {
            $params[self::SORT] = $this->sort->value;

            // Left out when it is the one the sort starts in.
            if ($this->isDescending() !== $this->sort->startsDescending()) {
                $params[self::DIRECTION] = $this->isDescending() ? self::DESCENDING : self::ASCENDING;
            }
        }

        if (null !== $this->date) {
            // Left out when it is the one the bar starts on.
            if (PageDate::Modified !== $this->date) {
                $params[self::DATE] = $this->date->value;
            }

            if (null !== $this->from) {
                $params[self::FROM] = $this->from->format(self::DAY_FORMAT);
            }

            if (null !== $this->to) {
                $params[self::TO] = $this->to->format(self::DAY_FORMAT);
            }
        }

        if (null !== $this->author) {
            $params[self::AUTHOR] = (string) $this->author;
        }

        if (null !== $this->inside) {
            $params[self::INSIDE] = (string) $this->inside;
        }

        if ($this->page > 1) {
            $params[self::PAGE] = (string) $this->page;
        }

        // Present and empty says "closed on purpose"; absent says "nothing chosen".
        if ($this->foldChosen || [] !== $this->open) {
            $params[self::OPEN] = implode(',', $this->open);
        }

        return $params;
    }

    public function withoutFilter(string $key): self
    {
        return match ($key) {
            self::SEARCH => $this->with(search: ''),
            self::STATUSES => $this->with(statuses: []),
            self::VISIBILITY => $this->with(visible: null, unsetVisible: true),
            self::TAG => $this->with(unsetTag: true),
            self::TYPE => $this->with(unsetType: true),
            self::SORT => $this->with(unsetSort: true),
            self::DATE => $this->with(unsetDate: true),
            self::AUTHOR => $this->with(unsetAuthor: true),
            self::INSIDE => $this->with(unsetInside: true),
            default => $this,
        };
    }

    public function withoutStatus(PageStatus $status): self
    {
        return $this->with(statuses: array_values(array_filter(
            $this->statuses,
            static fn (PageStatus $kept): bool => $kept !== $status,
        )));
    }

    /**
     * Folds or unfolds one branch, keeping the rest of the state.
     */
    public function toggling(int $pageId): self
    {
        $open = \in_array($pageId, $this->open, true)
            ? array_values(array_filter($this->open, static fn (int $id): bool => $id !== $pageId))
            : [...$this->open, $pageId];

        return $this->with(open: $open, foldChosen: true);
    }

    public function withoutAnyBranchOpen(): self
    {
        return $this->with(open: [], foldChosen: true);
    }

    /**
     * Every branch unfolded, used only where the whole site is small enough that
     * showing it whole is the friendlier default.
     *
     * @param list<int> $ids
     */
    public function withEverythingOpen(array $ids): self
    {
        // Not a choice the editor made, so it is not written into the address:
        // the next screen works it out the same way.
        return $this->with(open: $ids);
    }

    public function isOpen(int $pageId): bool
    {
        return \in_array($pageId, $this->open, true);
    }

    /**
     * @param list<PageStatus>|null $statuses
     * @param list<int>|null        $open
     */
    private function with(
        ?string $search = null,
        ?array $statuses = null,
        ?bool $visible = null,
        ?int $page = null,
        ?array $open = null,
        bool $unsetVisible = false,
        ?bool $foldChosen = null,
        bool $unsetTag = false,
        bool $unsetType = false,
        ?PageSort $sort = null,
        ?bool $descending = null,
        bool $unsetSort = false,
        bool $unsetDate = false,
        bool $unsetAuthor = false,
        bool $unsetInside = false,
    ): self {
        return new self(
            search: $search ?? $this->search,
            statuses: $statuses ?? $this->statuses,
            // A nullable value cannot say "leave it alone" with null alone.
            visible: $unsetVisible ? null : ($visible ?? $this->visible),
            // Any change to what is shown invalidates the page number: results
            // page 4 of a narrower search is usually empty.
            page: $page ?? 1,
            open: $open ?? $this->open,
            foldChosen: $foldChosen ?? $this->foldChosen,
            tag: $unsetTag ? null : $this->tag,
            type: $unsetType ? null : $this->type,
            sort: $unsetSort ? null : ($sort ?? $this->sort),
            descending: $unsetSort ? null : ($descending ?? $this->descending),
            date: $unsetDate ? null : $this->date,
            from: $unsetDate ? null : $this->from,
            to: $unsetDate ? null : $this->to,
            author: $unsetAuthor ? null : $this->author,
            inside: $unsetInside ? null : $this->inside,
        );
    }

    /**
     * An id, or the word for "without a tag"; anything else is no tag filter.
     */
    private static function readTag(string $raw): ?int
    {
        if (self::UNTAGGED === $raw) {
            return self::NO_TAG;
        }

        return self::readId($raw);
    }

    private static function readId(string $raw): ?int
    {
        return 1 === preg_match('/^[1-9]\d{0,9}$/D', $raw) ? (int) $raw : null;
    }

    /**
     * Which date, from which day, to which day. A day that is not one is
     * ignored, bounds given the wrong way round are put back, and a date with
     * no day is no period at all. The date is the last change unless the
     * address names another.
     *
     * @return array{PageDate|null, \DateTimeImmutable|null, \DateTimeImmutable|null}
     */
    private static function readPeriod(string $date, string $from, string $to): array
    {
        $first = self::readDay($from);
        $last = self::readDay($to);

        if (null === $first && null === $last) {
            return [null, null, null];
        }

        if (null !== $first && null !== $last && $first > $last) {
            [$first, $last] = [$last, $first];
        }

        return [PageDate::tryFrom($date) ?? PageDate::Modified, $first, $last];
    }

    /**
     * A day of the calendar, written as an `<input type="date">` sends it;
     * anything else, the 31st of February included, is no day.
     */
    private static function readDay(string $raw): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!'.self::DAY_FORMAT, $raw);

        return false !== $day && $day->format(self::DAY_FORMAT) === $raw ? $day : null;
    }

    private static function readType(string $raw): ?string
    {
        return '' !== $raw && PageTypeCode::isValid($raw) ? $raw : null;
    }

    /**
     * @return list<int>
     */
    private static function readOpen(string $raw): array
    {
        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $id = (int) trim($part);

            if ($id > 0 && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
