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

use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\MediaFile;
use TheliaCMS\Media\MediaFilter;
use TheliaLibrary\Model\LibraryImage;

/**
 * Builds what the media screens display, so the controller only has to render.
 */
final readonly class MediaCatalog
{
    /** Width of the grid thumbnails, in pixels. */
    private const int THUMBNAIL_WIDTH = 320;

    public function __construct(
        private CmsMediaLibrary $library,
        private MediaUsageFinder $usages,
    ) {
    }

    /**
     * The page of the library a query asks for. Past the last page, the last
     * one: an address kept from a larger library still shows images.
     * "Unused" reads what uses the images whatever `$withUsage` says.
     */
    public function browse(MediaQuery $query, string $locale, bool $withUsage = true): MediaGrid
    {
        $imageIds = $this->library->selection($locale, $query->search, $query->filter, $query->tag);

        if ([] !== $imageIds && MediaFilter::Unused === $query->filter) {
            // What uses an image is read from the content of every page and
            // item, once for all of them.
            $imageIds = array_keys(array_filter($this->usages->countsFor($imageIds), static fn (int $count): bool => 0 === $count));
        }

        $total = \count($imageIds);
        $page = min($query->page, max(1, (int) ceil($total / MediaQuery::PER_PAGE)));
        $images = $this->library->sorted($imageIds, $query->order, $locale, ($page - 1) * MediaQuery::PER_PAGE, MediaQuery::PER_PAGE);

        if ([] === $images) {
            return new MediaGrid([], $total, $page, MediaQuery::PER_PAGE);
        }

        // What uses an image is read from the content of every page: only
        // when the screen says it.
        $counts = $withUsage ? $this->usages->countsFor(array_map(static fn (LibraryImage $image): int => (int) $image->getId(), $images)) : [];

        $items = [];

        foreach ($images as $image) {
            $item = $this->item($image, $locale, $counts[(int) $image->getId()] ?? 0);

            if (null !== $item) {
                $items[] = $item;
            }
        }

        return new MediaGrid($items, $total, $page, MediaQuery::PER_PAGE);
    }

    /**
     * The image of the describe queue at a position: the images of the list
     * the queue was opened from that say nothing in the language, in its
     * order. A described image leaves the queue, so the next one takes its
     * position; past the end, the last one.
     */
    public function toDescribe(MediaQuery $query, string $locale, int $position): MediaGrid
    {
        $imageIds = $this->library->selection($locale, $query->search, MediaFilter::ToDescribe, $query->tag, $query->images);
        $total = \count($imageIds);
        $position = max(0, min($position, $total - 1));
        $images = $this->library->sorted($imageIds, $query->order, $locale, $position, 1);
        $item = [] === $images ? null : $this->item($images[0], $locale);

        return new MediaGrid(null === $item ? [] : [$item], $total, $position + 1, 1);
    }

    /**
     * The size of the library, and how much of it is still to describe.
     */
    public function tally(string $locale, ?string $search = null): MediaTally
    {
        $counts = $this->library->counts($locale, $search);

        return new MediaTally(total: $counts['total'], undescribed: $counts['undescribed']);
    }

    /**
     * Where the image stands in each language of the shop: described, still
     * to describe, or decorative, which holds for every language.
     *
     * @return list<array{languageId: int, locale: string, code: string, state: 'described'|'missing'|'decorative'}>
     */
    public function descriptionsOf(LibraryImage $image, string $editedLocale): array
    {
        $descriptions = [];

        if ($image->isVideo()) {
            return [];
        }

        foreach (LangQuery::create()->filterByActive(1)->orderByPosition()->find() as $language) {
            $locale = (string) $language->getLocale();
            $alt = trim((string) $image->setLocale($locale)->getAlt());

            $descriptions[] = [
                'languageId' => (int) $language->getId(),
                'locale' => $locale,
                'code' => strtoupper((string) $language->getCode()),
                'state' => 1 === $image->getDecorative() ? 'decorative' : ('' === $alt ? 'missing' : 'described'),
            ];
        }

        $image->setLocale($editedLocale);

        return $descriptions;
    }

    public function item(LibraryImage $image, string $locale, ?int $usageCount = null): ?MediaItem
    {
        $this->library->measure($image);

        $url = $this->library->publicUrl($image);

        if (null === $url) {
            return null;
        }

        $fileName = (string) $image->getFileName();

        return new MediaItem(
            id: (int) $image->getId(),
            title: $this->titleOf($image, $locale, $fileName),
            url: $url,
            thumbnailUrl: $this->thumbnailUrl($image, $fileName),
            alt: $image->getAlt(),
            decorative: 1 === $image->getDecorative(),
            caption: $image->getCaption(),
            width: $image->getWidth(),
            height: $image->getHeight(),
            fileSize: $image->getFileSize(),
            format: '' !== pathinfo($fileName, \PATHINFO_EXTENSION) ? pathinfo($fileName, \PATHINFO_EXTENSION) : null,
            tags: $this->library->tagsOf($image),
            usageCount: $usageCount ?? $this->usages->countsFor([(int) $image->getId()])[(int) $image->getId()],
            video: $image->isVideo(),
        );
    }

    /**
     * Name to show for an image, in the language being edited.
     *
     * An image named in one language only would otherwise show as its stored
     * file name — a random prefix — in the others, so the shop default answers
     * first. Leaves the model on the requested locale: the caller reads the
     * alternative text and caption from it straight after.
     */
    private function titleOf(LibraryImage $image, string $locale, string $fileName): string
    {
        foreach ([$locale, Lang::getDefaultLanguage()->getLocale()] as $candidate) {
            $title = $image->setLocale($candidate)->getTitle();

            if (null !== $title && '' !== $title) {
                $image->setLocale($locale);

                return $title;
            }
        }

        $image->setLocale($locale);

        return $fileName;
    }

    /**
     * A thumbnail is only asked for when it would actually be smaller: the
     * image route refuses to upscale and answers 400.
     */
    private function thumbnailUrl(LibraryImage $image, string $fileName): ?string
    {
        // No thumbnail is drawn of a video: the screens play its file.
        if ($image->isVideo()) {
            return null;
        }

        $width = $image->getWidth();
        $format = pathinfo($fileName, \PATHINFO_EXTENSION);

        // An SVG is served whole and cannot be resized: its own file is the thumbnail.
        if (null === $width || $width <= self::THUMBNAIL_WIDTH || MediaFile::isVectorFormat($format)) {
            return $this->library->publicUrl($image);
        }

        return '/image-library/'.$image->getId().'/full/'.self::THUMBNAIL_WIDTH.',!/0/default.'.($format ?: 'jpg');
    }
}
