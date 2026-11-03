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

use Symfony\Component\HttpFoundation\File\UploadedFile;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaLibrary\Exception\UnsupportedLibraryImageException;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Service\LibraryImageService;

/**
 * Every write the media screens perform. Nothing is persisted in a controller.
 */
final readonly class CmsMediaWriter
{
    public function __construct(
        private LibraryImageService $images,
        private CmsMediaLibrary $library,
    ) {
    }

    /**
     * Stores uploaded files and marks them as CMS content, each on its own:
     * a file of another format, too large or not an image is refused and
     * the others are stored.
     *
     * The formats are the ones of the module, not all the ones the library
     * would take: the screens say JPEG, PNG and WebP.
     *
     * @param list<UploadedFile> $files
     */
    public function add(array $files, string $locale): MediaUpload
    {
        $stored = [];
        $refused = [];

        foreach ($files as $file) {
            $refusal = $this->refusalOf($file);

            if (null === $refusal) {
                try {
                    $image = $this->images->createImage($file, $file->getClientOriginalName(), $locale);
                    $this->library->tag($image);
                    $stored[] = $image;

                    continue;
                } catch (UnsupportedLibraryImageException) {
                    $refusal = MediaUploadRefusal::Unreadable;
                }
            }

            $refused[] = ['name' => $file->getClientOriginalName(), 'refusal' => $refusal];
        }

        return new MediaUpload($stored, $refused);
    }

    /**
     * @param array{title: ?string, alt: ?string, caption: ?string, decorative: ?bool, tags: ?string, file: ?UploadedFile} $data
     */
    public function save(LibraryImage $image, string $locale, array $data): void
    {
        if ($data['file'] instanceof UploadedFile) {
            // Replacing goes through the library so the old file is removed and
            // the new one is measured; the row, and therefore the URL every
            // published page points at, is kept.
            $this->images->updateImage($image->getId(), $data['file'], null, $locale);
            $image->reload();
        }

        $image->setDecorative(true === $data['decorative'] ? 1 : 0);

        $image->setLocale($locale)
            ->setAlt($data['alt'])
            ->setCaption($data['caption']);

        $title = null === $data['title'] ? '' : trim($data['title']);

        if ('' !== $title) {
            $image->setTitle($title);
        }

        $image->save();

        $this->library->retag($image, $this->splitTags($data['tags']));
    }

    /**
     * What the image shows in a language, or that it is decorative: the two
     * answers of the describe queue.
     */
    public function describe(LibraryImage $image, string $locale, ?string $alt, bool $decorative): void
    {
        $image->setDecorative($decorative ? 1 : 0);
        $image->setLocale($locale)->setAlt($decorative ? null : $alt);
        $image->save();
    }

    /**
     * Decorative or not, for every language at once; the texts the image has
     * are kept, for the day it carries information again.
     */
    public function setDecorative(LibraryImage $image, bool $decorative): void
    {
        $image->setDecorative($decorative ? 1 : 0)->save();
    }

    public function delete(LibraryImage $image): void
    {
        $this->images->deleteImage($image->getId());
    }

    private function refusalOf(UploadedFile $file): ?MediaUploadRefusal
    {
        if (!$file->isValid()) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? MediaUploadRefusal::TooLarge
                : MediaUploadRefusal::Failed;
        }

        if (!\in_array($file->getMimeType(), CmsMediaType::ACCEPTED_MIME_TYPES, true)) {
            return MediaUploadRefusal::Format;
        }

        if ($file->getSize() > CmsMediaType::MAX_IMAGE_BYTES) {
            return MediaUploadRefusal::TooHeavy;
        }

        // The header says how large the picture is without decoding it; a file
        // whose header cannot be read is left to the library, which refuses it.
        $dimensions = @getimagesize($file->getPathname());

        return false !== $dimensions && $dimensions[0] * $dimensions[1] > CmsMediaType::MAX_IMAGE_PIXELS ? MediaUploadRefusal::TooManyPixels : null;
    }

    /**
     * @return list<string>
     */
    private function splitTags(?string $tags): array
    {
        if (null === $tags || '' === trim($tags)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $tags)), static fn (string $tag): bool => '' !== $tag));
    }
}
