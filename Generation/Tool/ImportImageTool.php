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

namespace TheliaCMS\Generation\Tool;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Thelia\Domain\Ai\AiToolDefinition;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Admin\CmsMediaWriter;
use TheliaCMS\Media\Admin\MediaUploadRefusal;
use TheliaCMS\Media\CmsMediaLibrary;

/**
 * Copies a picture of the web, the address of which the brief cites, into the
 * media library, so the page uses it by its library id and never by an
 * external address.
 *
 * Not a tool of the registry GeneratorTools offers, like `read_url`: it is
 * offered when the brief cites an address and the site allowed the reading of
 * the web, and the generator runs it with what the brief cites.
 *
 * The download goes through the client of `ReadUrlTool` (the guard of the
 * core against private addresses, every redirect checked, the body cut at the
 * ceiling), `https` only. What the bytes are decides, never the extension or
 * the Content-Type: JPEG, PNG or WebP (the formats of the upload routes), up to
 * the weight and the pixels of those routes. The image is then stored by
 * `CmsMediaWriter::add()`, which tags it for the CMS library like an upload.
 *
 * Whatever goes wrong is a failure with a code in the answer, never an
 * exception: the model leaves the picture out.
 */
final readonly class ImportImageTool
{
    public const string NAME = 'import_image';

    private const int MAX_TITLE = 200;

    private const int MAX_TEXT = 500;

    /** The file extension of what the bytes are. */
    private const array EXTENSIONS = [\IMAGETYPE_JPEG => 'jpg', \IMAGETYPE_PNG => 'png', \IMAGETYPE_WEBP => 'webp'];

    public function __construct(
        private ReadUrlTool $downloader,
        private CmsMediaWriter $writer,
        private CmsMediaLibrary $library,
    ) {
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            self::NAME,
            'Copies a picture of the web the brief cites, by its address, into the media library of the site and gives its library id, which is what an image prop takes. A picture from the web is imported, never linked by its external address: no image prop holds an address.',
            [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'The https address of the picture, as the brief gives it.'],
                    'title' => ['type' => 'string', 'description' => 'The title of the picture in the library.'],
                    'alt' => ['type' => 'string', 'description' => 'What the picture shows, for the alternative text; empty when unknown.'],
                    'credit' => ['type' => 'string', 'description' => 'The author or the source to credit; empty when unknown.'],
                ],
                'required' => ['url', 'title', 'alt', 'credit'],
                'additionalProperties' => false,
            ],
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>         $cited the addresses the brief cites: the only ones imported
     *
     * @return string JSON: `{"id": …, "editor_url": "…"}` or `{"error": "<code>", "reason": "…"}`
     */
    public function import(array $input, array $cited, string $locale): string
    {
        $url = trim((string) ($input['url'] ?? ''));

        if (!\in_array($url, $cited, true)) {
            return self::failure('url_not_cited', 'Only a picture whose address the brief cites is imported.');
        }

        $parts = parse_url($url);

        if (false === $parts || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || !isset($parts['host'])) {
            return self::failure('url_not_allowed', 'Only an https address is imported.');
        }

        $downloaded = $this->downloader->download($url, [$parts['host']], CmsMediaType::MAX_IMAGE_BYTES, 'image/jpeg,image/png,image/webp');

        if (null !== $downloaded['error'] || null === $downloaded['bytes']) {
            return self::failure((string) $downloaded['error'], 'The picture could not be downloaded. Leave it out.');
        }

        $bytes = $downloaded['bytes'];

        if (\strlen($bytes) > CmsMediaType::MAX_IMAGE_BYTES) {
            return self::failure('too_large', \sprintf('The picture weighs more than %d MiB. Leave it out.', CmsMediaType::MAX_IMAGE_BYTES / 1048576));
        }

        $size = @getimagesizefromstring($bytes);

        if (false === $size || !isset(self::EXTENSIONS[$size[2]])) {
            return self::failure('unsupported_type', 'The file is not a JPEG, PNG or WebP picture. Leave it out.');
        }

        if ($size[0] * $size[1] > CmsMediaType::MAX_IMAGE_PIXELS) {
            return self::failure('too_many_pixels', 'The picture has too many pixels. Leave it out.');
        }

        $title = mb_substr(trim((string) ($input['title'] ?? '')), 0, self::MAX_TITLE);
        $alt = mb_substr(trim((string) ($input['alt'] ?? '')), 0, self::MAX_TEXT);
        $credit = mb_substr(trim((string) ($input['credit'] ?? '')), 0, self::MAX_TEXT);
        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'theliacms-import-'.bin2hex(random_bytes(6));
        $stem = trim((string) preg_replace('/[^\w.\- ]+/u', '-', '' === $title ? 'image' : $title), ' .-');
        $name = ('' === $stem ? 'image' : $stem).'.'.self::EXTENSIONS[$size[2]];
        $filesystem = new Filesystem();

        try {
            $filesystem->dumpFile($directory.\DIRECTORY_SEPARATOR.$name, $bytes);
            // The file did not come from a browser: `test` makes it valid for the writer, which checks it all the same.
            $upload = $this->writer->add([new UploadedFile($directory.\DIRECTORY_SEPARATOR.$name, $name, $size['mime'], null, true)], $locale, true);
        } catch (\Throwable) {
            return self::failure('import_failed', 'The picture could not be stored. Leave it out.');
        } finally {
            $filesystem->remove($directory);
        }

        if ([] === $upload->stored) {
            return self::failure('refused', \sprintf('The library refused the picture (%s). Leave it out.', ($upload->refused[0]['refusal'] ?? MediaUploadRefusal::Failed)->name));
        }

        $image = $upload->stored[0];

        try {
            if ('' !== $title) {
                $image->setLocale($locale)->setTitle($title);
            }

            if ('' !== $credit) {
                $image->setLocale($locale)->setCaption($credit);
            }

            $image->save();

            if ('' !== $alt) {
                $this->writer->describe($image, $locale, $alt, false);
            }
        } catch (\Throwable) {
            // The picture is stored and usable: what describes it is the editor's to complete.
        }

        return json_encode(['id' => (int) $image->getId(), 'editor_url' => $this->library->publicUrl($image)], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private static function failure(string $code, string $reason): string
    {
        return json_encode(['error' => $code, 'reason' => $reason], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
