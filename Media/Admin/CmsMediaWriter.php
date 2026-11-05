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

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Media\Svg\PreparedSvgStore;
use TheliaCMS\Media\Svg\SvgUpload;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Exception\UnsupportedLibraryImageException;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Service\LibraryImageService;
use TheliaLibrary\Service\Svg\SvgSanitizer;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;
use TheliaLibrary\Service\Svg\UnreadableSvgReason;

/**
 * Every write the media screens perform. Nothing is persisted in a controller.
 */
final readonly class CmsMediaWriter
{
    public function __construct(
        private LibraryImageService $images,
        private CmsMediaLibrary $library,
        private SvgSanitizerInterface $svgSanitizer,
        private PreparedSvgStore $preparedSvgs,
    ) {
    }

    /**
     * Stores uploaded files and marks them as CMS content, each on its own:
     * a file of another format, too large or not an image is refused and
     * the others are stored.
     *
     * The formats are the ones of the module, not all the ones the library
     * would take: the screens say JPEG, PNG, WebP and SVG. An SVG is not
     * stored here: it is cleaned once, so a file that cannot be read is
     * refused at once, then kept apart for the preparation screen, where the
     * editor sees what the cleaning removed before it enters the library.
     *
     * @param list<UploadedFile> $files
     */
    public function add(array $files, string $locale): MediaUpload
    {
        $stored = [];
        $refused = [];
        $prepared = [];

        foreach ($files as $file) {
            if ($file->isValid() && SvgUpload::isSvg($file)) {
                try {
                    $token = $this->prepareSvg($file);

                    if (null === $token) {
                        $refused[] = ['name' => $file->getClientOriginalName(), 'refusal' => MediaUploadRefusal::Failed];
                    } else {
                        $prepared[] = ['token' => $token, 'name' => $file->getClientOriginalName()];
                    }
                } catch (UnreadableSvgException $exception) {
                    $refused[] = ['name' => $file->getClientOriginalName(), 'refusal' => MediaUploadRefusal::forUnreadableSvg($exception->reason)];
                }

                continue;
            }

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

        return new MediaUpload($stored, $refused, $prepared);
    }

    /**
     * Cleans an SVG once, to refuse what cannot be read, and keeps the bytes as
     * they were sent: the preparation screen starts again from them.
     *
     * @return string|null the token of the file on the preparation screen; null when the file cannot be read, which did not arrive whole
     *
     * @throws UnreadableSvgException
     */
    public function prepareSvg(UploadedFile $file): ?string
    {
        // Read nothing that the library would refuse for its weight.
        if ($file->getSize() > SvgSanitizer::MAXIMUM_BYTES) {
            throw new UnreadableSvgException(UnreadableSvgReason::TooLarge);
        }

        // A file that cannot be read did not arrive whole: not a broken XML.
        $content = @file_get_contents($file->getPathname());

        if (false === $content) {
            return null;
        }

        $this->svgSanitizer->sanitize($content);

        return $this->preparedSvgs->put($content, $file->getClientOriginalName());
    }

    /**
     * Stores an SVG the editor has seen, under the name of the file it came in.
     *
     * @throws UnreadableSvgException
     */
    public function addPreparedSvg(string $svg, string $name, string $locale): LibraryImage
    {
        $image = $this->withTemporarySvg($svg, $name, fn (File $file): LibraryImage => $this->images->createImage($file, $name, $locale));
        $this->library->tag($image);

        return $image;
    }

    /**
     * Puts an SVG the editor has seen in the place of the file of an image,
     * which keeps its row and therefore its address.
     *
     * @throws UnreadableSvgException
     */
    public function replaceWithPreparedSvg(LibraryImage $image, string $svg, string $name, string $locale): void
    {
        $this->withTemporarySvg($svg, $name, fn (File $file): LibraryImage => $this->images->updateImage($image->getId(), $file, null, $locale));
    }

    /**
     * The file handed to the library bears the name the editor sent: the
     * library titles an image after it where the language has no title yet,
     * as it does for a file sent straight from the sheet.
     *
     * @param \Closure(File): LibraryImage $store
     */
    private function withTemporarySvg(string $svg, string $name, \Closure $store): LibraryImage
    {
        // The library moves the file it is given into its own directory.
        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'theliacms-svg-'.bin2hex(random_bytes(6));
        $stem = trim((string) preg_replace('/[^\w.\- ]+/u', '-', pathinfo($name, \PATHINFO_FILENAME)), ' .-');
        $path = $directory.\DIRECTORY_SEPARATOR.('' === $stem ? 'image' : $stem).'.svg';
        $filesystem = new Filesystem();
        $filesystem->dumpFile($path, $svg);

        try {
            return $store(new File($path));
        } finally {
            // Moved once stored; left behind when refused.
            $filesystem->remove($directory);
        }
    }

    /**
     * @param array{title: ?string, alt: ?string, caption: ?string, decorative: ?bool, tags: ?string, file: ?UploadedFile} $data
     */
    public function save(LibraryImage $image, string $locale, array $data): void
    {
        // An SVG does not replace the file here: it goes through the
        // preparation screen first, so the editor sees what is stored.
        if ($data['file'] instanceof UploadedFile && !SvgUpload::isSvg($data['file'])) {
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
