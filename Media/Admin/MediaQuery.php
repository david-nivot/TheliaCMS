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

use Symfony\Component\HttpFoundation\Request;
use TheliaCMS\Media\MediaFilter;
use TheliaCMS\Media\MediaKind;
use TheliaCMS\Media\MediaOrder;
use TheliaCMS\Media\MediaView;

/**
 * What the list of the media asks for, read from its address so a filtered
 * list can be bookmarked, shared and come back to from an image.
 */
final readonly class MediaQuery
{
    public const int PER_PAGE = 48;

    /** A list of named images is the images of one sending, not a selection. */
    private const int MAX_IMAGES = 200;

    /**
     * @param list<int>  $images only these images, as a sending of files names them: read by the describe queue, not by the list
     * @param ?MediaKind $kind   only the images or only the videos; both when null, and never read from the address here
     * @param MediaView  $view   cards or rows: kept in the address like a filter, so the pages, an image opened and the way back keep it
     */
    public function __construct(
        public ?string $search = null,
        public ?MediaFilter $filter = null,
        public ?string $tag = null,
        public MediaOrder $order = MediaOrder::Newest,
        public int $page = 1,
        public array $images = [],
        public ?MediaKind $kind = null,
        public MediaView $view = MediaView::Grid,
    ) {
    }

    /**
     * The same query narrowed to one kind of file. The list of the library
     * shows both and leaves it null; a picker names what it takes.
     */
    public function ofKind(MediaKind $kind): self
    {
        return new self($this->search, $this->filter, $this->tag, $this->order, $this->page, $this->images, $kind, $this->view);
    }

    /**
     * A value the screen does not offer is read as no value: an address
     * edited by hand lists the library instead of failing.
     */
    public static function fromRequest(Request $request): self
    {
        $search = trim($request->query->getString('search'));
        $tag = trim($request->query->getString('tag'));

        return new self(
            search: '' !== $search ? $search : null,
            filter: MediaFilter::tryFrom($request->query->getString('filter')),
            tag: '' !== $tag ? $tag : null,
            order: MediaOrder::tryFrom($request->query->getString('order')) ?? MediaOrder::Newest,
            page: max(1, $request->query->getInt('page', 1)),
            images: self::imageIds($request->query->getString('images')),
            view: MediaView::tryFrom($request->query->getString('view')) ?? MediaView::Grid,
        );
    }

    /**
     * @return list<int>
     */
    private static function imageIds(string $list): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $list)), static fn (int $id): bool => $id > 0)));

        return \array_slice($ids, 0, self::MAX_IMAGES);
    }

    public function isNarrowed(): bool
    {
        return null !== $this->search || null !== $this->filter || null !== $this->tag;
    }

    /**
     * The address of this list drawn in another view, the page kept: the
     * same images, only drawn otherwise.
     *
     * @return array<string, string|int>
     */
    public function viewParameters(MediaView $view): array
    {
        return (new self($this->search, $this->filter, $this->tag, $this->order, $this->page, view: $view))->addressParameters();
    }

    /**
     * The parameters of the address of this list, the page aside, as links
     * to another page of it keep them.
     *
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return array_filter([
            'search' => $this->search,
            'filter' => $this->filter?->value,
            'tag' => $this->tag,
            'order' => MediaOrder::Newest === $this->order ? null : $this->order->value,
            'view' => MediaView::Grid === $this->view ? null : $this->view->value,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * The parameters of a describe queue: those of its list, and the images
     * of a sending when it holds them only. The list itself is never
     * narrowed to them: leaving the queue goes back to the whole list.
     *
     * @return array<string, string>
     */
    public function queueParameters(): array
    {
        return $this->parameters() + ([] === $this->images ? [] : ['images' => implode(',', $this->images)]);
    }

    /**
     * The parameters of this very list, the page included: an image opened
     * from it carries them, so going back lands where the editor was.
     *
     * @return array<string, string|int>
     */
    public function addressParameters(): array
    {
        return $this->parameters() + (1 < $this->page ? ['page' => $this->page] : []);
    }
}
