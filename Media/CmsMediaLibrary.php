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

namespace TheliaCMS\Media;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Model\Lang;
use TheliaCMS\Page\Admin\EditLanguage;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Model\LibraryImageQuery;
use TheliaLibrary\Model\LibraryImageTagQuery;
use TheliaLibrary\Model\LibraryTag;
use TheliaLibrary\Model\LibraryTagQuery;
use TheliaLibrary\Service\ImageService;
use TheliaLibrary\Service\LibraryImageTagService;
use TheliaLibrary\TheliaLibrary;

/**
 * The slice of the image library that belongs to the CMS.
 *
 * Images uploaded from the page builder are stored in TheliaLibrary like any
 * other, and marked with a tag: the library is shared with the rest of the
 * shop, but the editor only offers what was uploaded for content pages.
 */
final readonly class CmsMediaLibrary implements MediaResolver, MediaDescriptions
{
    /** Title of the tag every image uploaded from the builder carries. */
    public const string TAG = 'cms';

    private const string TAG_COLOUR = '#1d4ed8';

    public function __construct(
        private RequestStack $requests,
        private EditLanguage $languages,
        private LibraryImageTagService $imageTags,
        private ImageService $imageService,
    ) {
    }

    /**
     * Locale the image title is written in.
     *
     * Always resolved here and passed explicitly: TheliaLibrary falls back to
     * the admin session, which does not exist on the command line.
     */
    public function locale(): string
    {
        $request = $this->requests->getCurrentRequest();

        return null !== $request
            ? $this->languages->resolve($request)->getLocale()
            : Lang::getDefaultLanguage()->getLocale();
    }

    public function tag(LibraryImage $image): void
    {
        $this->imageTags->associateImage((string) $image->getId(), (string) $this->tagId());
    }

    /**
     * Public URL of an image at its stored size.
     *
     * Resized variants are not built here: the image route reads its size as
     * `width,height` and squares the image unless the ratio-preserving `!` form
     * is used, so the widths of a srcset are composed by the ImageRewriter.
     */
    public function publicUrl(LibraryImage $image): ?string
    {
        $fileName = $image->getFileName();

        if (null === $fileName) {
            return null;
        }

        // A video has its own route: the image ones answer 404 for it.
        if ($image->isVideo()) {
            return $this->imageService->getUrlForVideo((int) $image->getId());
        }

        return $this->imageService->getUrlForImage(
            $image->getId(),
            pathinfo($fileName, \PATHINFO_EXTENSION),
            'full',
            'max',
        );
    }

    /**
     * The library image an editor URL points at, with the dimensions of the
     * stored file. Returns null for anything the library does not own.
     */
    public function fromUrl(string $url): ?MediaFile
    {
        if (1 !== preg_match('#^/image-library/(\d+)/#', $url, $matches)) {
            return null;
        }

        $image = LibraryImageQuery::create()->findPk((int) $matches[1]);

        // The image route answers 404 for a video: an address that names one is no image.
        if (!$image instanceof LibraryImage || $image->isVideo()) {
            return null;
        }

        $fileName = $image->getFileName();

        if (null === $fileName) {
            return null;
        }

        $this->measure($image);

        return new MediaFile(
            id: (int) $image->getId(),
            format: pathinfo($fileName, \PATHINFO_EXTENSION),
            width: $image->getWidth(),
            height: $image->getHeight(),
        );
    }

    /**
     * The state of every library image the addresses point at, in one
     * statement: a page of thirty images costs the same as a page of one. A
     * video has no state: it is never read as an image to describe.
     */
    public function statesOf(array $urls, string $locale): array
    {
        $idsByUrl = [];

        foreach ($urls as $url) {
            if (1 === preg_match('#^/image-library/(\d+)/#', $url, $matches)) {
                $idsByUrl[$url] = (int) $matches[1];
            }
        }

        if ([] === $idsByUrl) {
            return [];
        }

        $statement = Propel::getConnection('TheliaMain')->prepare(\sprintf(
            "SELECT li.id, li.decorative,
                EXISTS (SELECT 1 FROM library_image_i18n i WHERE i.id = li.id AND i.locale = :locale AND TRIM(COALESCE(i.alt, '')) <> '') AS described
             FROM library_image li
             WHERE li.id IN (%s) AND li.file_name IS NOT NULL AND %s",
            implode(',', array_unique($idsByUrl)),
            MediaKind::Image->condition(),
        ));
        $statement->execute(['locale' => $locale]);

        $stateById = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $stateById[(int) $row['id']] = match (true) {
                (bool) $row['decorative'] => ImageState::Decorative,
                (bool) $row['described'] => ImageState::Described,
                default => ImageState::Undescribed,
            };
        }

        $states = [];

        foreach ($idsByUrl as $url => $id) {
            if (isset($stateById[$id])) {
                $states[$url] = $stateById[$id];
            }
        }

        return $states;
    }

    /**
     * The text of every library image the addresses point at, in one
     * statement, as `statesOf()` reads their state: a decorative image is
     * drawn with an empty text whatever it holds, a described one with its
     * text in the language.
     */
    public function alternativeTextsOf(array $urls, string $locale): array
    {
        $idsByUrl = [];

        foreach ($urls as $url) {
            if (1 === preg_match('#^/image-library/(\d+)/#', $url, $matches)) {
                $idsByUrl[$url] = (int) $matches[1];
            }
        }

        if ([] === $idsByUrl) {
            return [];
        }

        $statement = Propel::getConnection('TheliaMain')->prepare(\sprintf(
            "SELECT li.id, li.decorative, TRIM(COALESCE(i.alt, '')) AS alt
             FROM library_image li
             LEFT JOIN library_image_i18n i ON i.id = li.id AND i.locale = :locale
             WHERE li.id IN (%s) AND li.file_name IS NOT NULL AND %s",
            implode(',', array_unique($idsByUrl)),
            MediaKind::Image->condition(),
        ));
        $statement->execute(['locale' => $locale]);

        $textById = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // Trimmed as `statesOf()` trims it: what it calls described is what is written.
            $text = (string) $row['alt'];

            if ((bool) $row['decorative']) {
                $textById[(int) $row['id']] = '';
            } elseif ('' !== $text) {
                $textById[(int) $row['id']] = $text;
            }
        }

        $texts = [];

        foreach ($idsByUrl as $url => $id) {
            if (isset($textById[$id])) {
                $texts[$url] = $textById[$id];
            }
        }

        return $texts;
    }

    /**
     * Reads the size of an image the library has not measured, and records it.
     *
     * Uploads have carried their dimensions since TheliaLibrary 1.4.0; images
     * stored before that have none, and a `<picture>` without width and height
     * makes the page shift as it loads. Measuring once on the way past saves a
     * migration command for what is a handful of files.
     */
    public function measure(LibraryImage $image): void
    {
        $fileName = $image->getFileName();

        // A video has no dimensions to read: getimagesize() would say nothing.
        if (null === $fileName || $image->isVideo() || (null !== $image->getWidth() && null !== $image->getHeight())) {
            return;
        }

        $path = TheliaLibrary::getImageDirectory().$fileName;
        $dimensions = @getimagesize($path);

        if (false === $dimensions) {
            return;
        }

        $image
            ->setWidth($dimensions[0])
            ->setHeight($dimensions[1])
            ->setFileSize(filesize($path) ?: null)
            ->setMimeType($dimensions['mime'] ?? null)
            ->save();
    }

    /**
     * Images of the library the CMS owns, most recent first.
     *
     * TheliaLibrary ships no listing service, so the query lives here. A search
     * term matches a title, an alternative text, a caption or one of the image
     * tags. Every kind of file is listed unless `$kind` narrows it.
     *
     * @return list<LibraryImage>
     */
    public function images(?string $search = null, int $limit = 200, ?MediaKind $kind = null): array
    {
        $imageIds = $this->ofKind($this->taggedImageIds($this->tagId()), $kind);

        if ([] === $imageIds) {
            return [];
        }

        if (null !== $search && '' !== $search) {
            $imageIds = $this->matching($imageIds, $search);

            if ([] === $imageIds) {
                return [];
            }
        }

        return array_values(iterator_to_array(
            LibraryImageQuery::create()
                ->filterById($imageIds, Criteria::IN)
                ->orderById(Criteria::DESC)
                ->limit($limit)
                ->find(),
            false,
        ));
    }

    /**
     * How many images the library holds, and how many carry no description.
     *
     * Counted in the database rather than over the rows the grid loaded: the
     * grid is capped, so counting it announces the size of a page as the size
     * of the library.
     *
     * @return array{total: int, undescribed: int}
     */
    public function counts(string $locale, ?string $search = null, ?MediaKind $kind = null): array
    {
        $imageIds = $this->ofKind($this->taggedImageIds($this->tagId()), $kind);

        if (null !== $search && '' !== $search) {
            $imageIds = $this->matching($imageIds, $search);
        }

        if ([] === $imageIds) {
            return ['total' => 0, 'undescribed' => 0];
        }

        return ['total' => \count($imageIds), 'undescribed' => \count($this->undescribed($imageIds, $locale))];
    }

    /**
     * The images of the CMS a search, a tag and a filter leave, in no order.
     *
     * "Unused" is let through: what uses an image is read from the content of
     * the pages, which the media screens do, not the library.
     *
     * @param list<int>  $only named images, the others left out
     * @param ?MediaKind $kind only the images or only the videos; both when null
     *
     * @return list<int>
     */
    public function selection(string $locale, ?string $search = null, ?MediaFilter $filter = null, ?string $tag = null, array $only = [], ?MediaKind $kind = null): array
    {
        $imageIds = $this->ofKind($this->taggedImageIds($this->tagId()), $kind);

        if ([] !== $only) {
            $imageIds = array_values(array_intersect($imageIds, $only));
        }

        if ([] !== $imageIds && null !== $search && '' !== $search) {
            $imageIds = $this->matching($imageIds, $search);
        }

        if ([] !== $imageIds && null !== $tag && '' !== $tag) {
            $tagId = $this->existingTagId($tag);
            $imageIds = null === $tagId ? [] : array_values(array_intersect($imageIds, $this->taggedImageIds($tagId)));
        }

        if ([] === $imageIds) {
            return [];
        }

        return match ($filter) {
            MediaFilter::ToDescribe => $this->undescribed($imageIds, $locale),
            MediaFilter::Decorative => array_map('intval', LibraryImageQuery::create()->filterById($imageIds, Criteria::IN)->filterByDecorative(1)->select(['Id'])->find()->toArray()),
            MediaFilter::Unused, null => $imageIds,
        };
    }

    /**
     * One page of these images in the order asked. A title is read in the
     * language edited, then in the default one, then the file name stands
     * for it, as the screens show it.
     *
     * @param list<int> $imageIds
     *
     * @return list<LibraryImage>
     */
    public function sorted(array $imageIds, MediaOrder $order, string $locale, int $offset, int $limit): array
    {
        if ([] === $imageIds || $limit <= 0) {
            return [];
        }

        $orderBy = match ($order) {
            MediaOrder::Newest => 'li.id DESC',
            MediaOrder::Oldest => 'li.id ASC',
            MediaOrder::Weight => 'li.file_size DESC, li.id DESC',
            MediaOrder::Title => "COALESCE(NULLIF(TRIM(edited.title), ''), NULLIF(TRIM(fallback.title), ''), li.file_name) ASC, li.id ASC",
        };

        $statement = Propel::getConnection('TheliaMain')->prepare(\sprintf(
            'SELECT li.id FROM library_image li
             LEFT JOIN library_image_i18n edited ON edited.id = li.id AND edited.locale = :locale
             LEFT JOIN library_image_i18n fallback ON fallback.id = li.id AND fallback.locale = :fallback
             WHERE li.id IN (%s) ORDER BY %s LIMIT %d OFFSET %d',
            implode(',', array_map('intval', $imageIds)),
            $orderBy,
            $limit,
            max(0, $offset),
        ));
        $statement->execute(['locale' => $locale, 'fallback' => Lang::getDefaultLanguage()->getLocale()]);
        $pageIds = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));

        $images = [];

        foreach (LibraryImageQuery::create()->filterById($pageIds, Criteria::IN)->find() as $image) {
            $images[(int) $image->getId()] = $image;
        }

        return array_values(array_filter(array_map(static fn (int $id): ?LibraryImage => $images[$id] ?? null, $pageIds)));
    }

    /**
     * The tags the images of the CMS carry, the CMS one aside, by title, with
     * how many images carry each.
     *
     * @return array<string, int>
     */
    public function tagCounts(?MediaKind $kind = null): array
    {
        $imageIds = $this->ofKind($this->taggedImageIds($this->tagId()), $kind);

        if ([] === $imageIds) {
            return [];
        }

        $perTag = [];

        foreach (LibraryImageTagQuery::create()->filterByImageId($imageIds, Criteria::IN)->filterByTagId($this->tagId(), Criteria::NOT_EQUAL)->select(['TagId'])->find()->toArray() as $tagId) {
            $perTag[(int) $tagId] = ($perTag[(int) $tagId] ?? 0) + 1;
        }

        $counts = [];
        $locale = $this->locale();

        foreach (LibraryTagQuery::create()->filterById(array_keys($perTag), Criteria::IN)->find() as $tag) {
            $title = $tag->setLocale($locale)->getTitle() ?? $tag->setLocale(Lang::getDefaultLanguage()->getLocale())->getTitle();

            if (null !== $title && '' !== $title) {
                $counts[$title] = ($counts[$title] ?? 0) + $perTag[(int) $tag->getId()];
            }
        }

        ksort($counts, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $counts;
    }

    /**
     * The images among these that say nothing in the language: neither a
     * non-blank alternative text there nor decorative. An image with no row
     * at all in the language says nothing in it either. A video is never
     * among them: it has no alternative text to give.
     *
     * @param list<int> $imageIds
     *
     * @return list<int>
     */
    private function undescribed(array $imageIds, string $locale): array
    {
        if ([] === $imageIds) {
            return [];
        }

        $statement = Propel::getConnection('TheliaMain')->prepare(\sprintf(
            "SELECT li.id FROM library_image li
             WHERE li.id IN (%s) AND li.decorative = 0
             AND %s
             AND NOT EXISTS (SELECT 1 FROM library_image_i18n i WHERE i.id = li.id AND i.locale = :locale AND TRIM(COALESCE(i.alt, '')) <> '')",
            implode(',', array_map('intval', $imageIds)),
            MediaKind::Image->condition(),
        ));
        $statement->execute(['locale' => $locale]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * These identifiers narrowed to one kind of file; all of them when none is asked.
     *
     * @param list<int> $imageIds
     *
     * @return list<int>
     */
    private function ofKind(array $imageIds, ?MediaKind $kind): array
    {
        if (null === $kind || [] === $imageIds) {
            return $imageIds;
        }

        $statement = Propel::getConnection('TheliaMain')->query(\sprintf(
            'SELECT li.id FROM library_image li WHERE li.id IN (%s) AND %s',
            implode(',', array_map('intval', $imageIds)),
            $kind->condition(),
        ));

        return array_values(array_intersect($imageIds, array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN))));
    }

    private function existingTagId(string $title): ?int
    {
        $tag = LibraryTagQuery::create()->useLibraryTagI18nQuery()->filterByTitle($title)->endUse()->findOne();

        return $tag instanceof LibraryTag ? (int) $tag->getId() : null;
    }

    /**
     * The image with that identifier, if the CMS owns it.
     *
     * Guards the back-office screens against reaching an image uploaded for a
     * product: the library is shared, the CMS section is not. A `$kind` asked
     * for answers null for a file of the other: a video is no image.
     */
    public function ownedImage(int $imageId, ?MediaKind $kind = null): ?LibraryImage
    {
        if (!\in_array($imageId, $this->taggedImageIds($this->tagId()), true)) {
            return null;
        }

        $image = LibraryImageQuery::create()->findPk($imageId);

        return null !== $kind && $image instanceof LibraryImage && MediaKind::of($image) !== $kind ? null : $image;
    }

    /**
     * Tags an image carries, other than the one marking it as CMS content.
     *
     * @return list<string>
     */
    public function tagsOf(LibraryImage $image): array
    {
        $tagIds = LibraryImageTagQuery::create()
            ->filterByImageId($image->getId())
            ->select(['TagId'])
            ->find()
            ->toArray();

        $titles = [];
        $locale = $this->locale();

        foreach (LibraryTagQuery::create()->filterById($tagIds, Criteria::IN)->find() as $tag) {
            if ((int) $tag->getId() === $this->tagId()) {
                continue;
            }

            $title = $tag->setLocale($locale)->getTitle() ?? $tag->setLocale(Lang::getDefaultLanguage()->getLocale())->getTitle();

            if (null !== $title && '' !== $title) {
                $titles[] = $title;
            }
        }

        sort($titles);

        return $titles;
    }

    /**
     * Gives an image one more tag, created on first use; a tag it carries
     * already is left as it is.
     */
    public function addTag(LibraryImage $image, string $title): void
    {
        $title = trim($title);

        if ('' === $title) {
            return;
        }

        $tagId = $this->tagIdFor($title);

        if (0 === LibraryImageTagQuery::create()->filterByImageId($image->getId())->filterByTagId($tagId)->count()) {
            $this->imageTags->associateImage((string) $image->getId(), (string) $tagId);
        }
    }

    /**
     * Takes a tag off an image. The tag that marks it as CMS content stays.
     */
    public function removeTag(LibraryImage $image, string $title): void
    {
        $tagId = $this->existingTagId(trim($title));

        if (null === $tagId || $tagId === $this->tagId()) {
            return;
        }

        LibraryImageTagQuery::create()->filterByImageId($image->getId())->filterByTagId($tagId)->delete();
    }

    /**
     * Replaces the tags of an image, keeping the one that marks it as CMS
     * content. Tags are created on first use.
     *
     * @param list<string> $titles
     */
    public function retag(LibraryImage $image, array $titles): void
    {
        $keep = [$this->tagId()];

        foreach ($titles as $title) {
            $title = trim($title);

            if ('' === $title) {
                continue;
            }

            $keep[] = $this->tagIdFor($title);
        }

        $keep = array_unique($keep);

        foreach (LibraryImageTagQuery::create()->filterByImageId($image->getId())->find() as $association) {
            if (\in_array((int) $association->getTagId(), $keep, true)) {
                $keep = array_diff($keep, [(int) $association->getTagId()]);

                continue;
            }

            $association->delete();
        }

        foreach ($keep as $tagId) {
            $this->imageTags->associateImage((string) $image->getId(), (string) $tagId);
        }
    }

    /**
     * @param list<int> $imageIds
     *
     * @return list<int>
     */
    private function matching(array $imageIds, string $search): array
    {
        // What an editor remembers of an image: its name, what it shows or its
        // caption. The stored file name is random; the uploaded one is the
        // first title.
        $byText = LibraryImageQuery::create()
            ->filterById($imageIds, Criteria::IN)
            ->useLibraryImageI18nQuery()
                ->filterByTitle('%'.$search.'%', Criteria::LIKE)
                ->_or()
                ->filterByAlt('%'.$search.'%', Criteria::LIKE)
                ->_or()
                ->filterByCaption('%'.$search.'%', Criteria::LIKE)
            ->endUse()
            ->select(['Id'])
            ->find()
            ->toArray();

        $tagIds = LibraryTagQuery::create()
            ->useLibraryTagI18nQuery()
                ->filterByTitle('%'.$search.'%', Criteria::LIKE)
            ->endUse()
            ->select(['Id'])
            ->find()
            ->toArray();

        $byTag = [];

        foreach ($tagIds as $tagId) {
            $byTag = array_merge($byTag, $this->taggedImageIds((int) $tagId));
        }

        return array_values(array_intersect(
            array_map('intval', $imageIds),
            array_unique(array_merge(array_map('intval', $byText), $byTag)),
        ));
    }

    /**
     * @return list<int>
     */
    private function taggedImageIds(int $tagId): array
    {
        return array_map('intval', LibraryImageTagQuery::create()
            ->filterByTagId($tagId)
            ->select(['ImageId'])
            ->find()
            ->toArray());
    }

    private function tagId(): int
    {
        return $this->tagIdFor(self::TAG);
    }

    private function tagIdFor(string $title): int
    {
        $tag = LibraryTagQuery::create()->useLibraryTagI18nQuery()->filterByTitle($title)->endUse()->findOne();

        if (!$tag instanceof LibraryTag) {
            $tag = (new LibraryTag())
                ->setLocale($this->locale())
                ->setTitle($title)
                ->setColorCode(self::TAG_COLOUR);
            $tag->save();
        }

        return (int) $tag->getId();
    }
}
