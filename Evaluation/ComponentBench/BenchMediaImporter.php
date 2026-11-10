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

namespace TheliaCMS\Evaluation\ComponentBench;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\Service\LibraryImageService;

/**
 * Puts the files of the bench into the media library, through the service
 * of TheliaLibrary that the upload screen uses, so each picture is served
 * and resized like any other.
 *
 * The manifest of the folder lists them:
 * `{"media": [{"file": "a.jpg", "kind": "image", "title": "…", "alt": "…", "width": 1600, "height": 900}]}`.
 * A file is imported once: the library holding already a media of the same
 * title, or of the name of the file as its title, is left as it is.
 */
final readonly class BenchMediaImporter
{
    public const string MANIFEST = 'manifest.json';

    public function __construct(
        private LibraryImageService $images,
        private CmsMediaLibrary $library,
    ) {
    }

    public static function defaultDirectory(): string
    {
        return \dirname(__DIR__, 2).'/docs/evaluation/components/media';
    }

    /**
     * @return list<array{file: string, kind: string, title: string, alt: string}>
     *
     * @throws \InvalidArgumentException when the manifest is missing, unreadable, or names a file that is not there
     */
    public function manifest(string $directory): array
    {
        $path = rtrim($directory, '/').'/'.self::MANIFEST;

        if (!is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('No %s in %s.', self::MANIFEST, $directory));
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(\sprintf('%s is not JSON: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($decoded) || !\is_array($decoded['media'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('%s holds no "media" list.', $path));
        }

        $entries = [];

        foreach ($decoded['media'] as $position => $entry) {
            $file = \is_array($entry) && \is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $kind = \is_array($entry) ? ($entry['kind'] ?? 'image') : '';

            if ('' === $file || $file !== basename($file) || !\in_array($kind, ['image', 'video'], true)) {
                throw new \InvalidArgumentException(\sprintf('%s: entry %d needs a "file" (a name, no path) and a "kind" of image or video.', $path, $position));
            }

            if (!is_file(rtrim($directory, '/').'/'.$file)) {
                throw new \InvalidArgumentException(\sprintf('%s: %s is not in the folder.', $path, $file));
            }

            $entries[] = [
                'file' => $file,
                'kind' => $kind,
                'title' => trim((string) ($entry['title'] ?? '')) ?: $file,
                'alt' => trim((string) ($entry['alt'] ?? '')),
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{file: string, id: ?int, status: 'imported'|'already'|'would-import'}> `id` is null for a dry run of a new file
     *
     * @throws \InvalidArgumentException see manifest()
     */
    public function import(string $directory, bool $dryRun = false): array
    {
        $entries = $this->manifest($directory);
        $locale = $this->library->locale();
        $known = $this->knownByTitle($locale);
        $report = [];

        foreach ($entries as $entry) {
            $existing = $known[$entry['title']] ?? $known[$entry['file']] ?? null;

            if (null !== $existing) {
                $report[] = ['file' => $entry['file'], 'id' => $existing, 'status' => 'already'];

                continue;
            }

            if ($dryRun) {
                $report[] = ['file' => $entry['file'], 'id' => null, 'status' => 'would-import'];

                continue;
            }

            $image = $this->create(rtrim($directory, '/').'/'.$entry['file'], $entry, $locale);
            $known[$entry['title']] = (int) $image->getId();
            $report[] = ['file' => $entry['file'], 'id' => (int) $image->getId(), 'status' => 'imported'];
        }

        return $report;
    }

    /**
     * @param array{file: string, kind: string, title: string, alt: string} $entry
     */
    private function create(string $path, array $entry, string $locale): LibraryImage
    {
        // The library moves the file it is given into its own directory: it
        // gets a copy, and the folder of the bench keeps its file.
        $copy = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'theliacms-bench-'.bin2hex(random_bytes(6)).'-'.$entry['file'];
        (new Filesystem())->copy($path, $copy);

        try {
            $image = $this->images->createImage(new File($copy), $entry['title'], $locale);
        } finally {
            (new Filesystem())->remove($copy);
        }

        $image->setLocale($locale);

        if ('image' === $entry['kind'] && '' !== $entry['alt']) {
            $image->setAlt($entry['alt']);
        }

        $image->save();
        $this->library->retag($image, []);

        return $image;
    }

    /**
     * The id of each media of the library by its title in the default language.
     *
     * @return array<string, int>
     */
    private function knownByTitle(string $locale): array
    {
        $known = [];

        foreach ($this->library->images(limit: \PHP_INT_MAX) as $image) {
            $title = trim((string) $image->setLocale($locale)->getTitle());

            if ('' !== $title) {
                $known[$title] ??= (int) $image->getId();
            }
        }

        return $known;
    }
}
