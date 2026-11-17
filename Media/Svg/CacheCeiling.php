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

namespace TheliaCMS\Media\Svg;

/**
 * Keeps a directory of cached files under a total weight. The files of one
 * entry share the name before the first dot; the entries used least recently
 * leave first (the modification time of a file is its last use: the readers
 * refresh it), and the entry being written never does.
 *
 * A file whose name starts with a dot, or whose entry does not look like one
 * of the cache, is not ours to count or to remove.
 *
 * The directory is scanned at most once per interval: the scan lists every
 * file, and a screen writes about ten entries in a row. Between two scans a
 * write is accepted, so the directory can pass its ceiling by what is written
 * during one interval; the next scan brings it back under.
 */
final readonly class CacheCeiling
{
    private const string MARKER = '.checked';

    /**
     * @param non-empty-string $entryPattern the form of the name of an entry, before the first dot
     */
    public function __construct(
        private string $entryPattern,
        private int $scanIntervalInSeconds = 60,
    ) {
    }

    /**
     * Whether the directory is to be scanned now; saying yes starts the interval.
     */
    public function isScanDue(string $directory): bool
    {
        $marker = $directory.\DIRECTORY_SEPARATOR.self::MARKER;
        $checked = @filemtime($marker);

        if (false !== $checked && time() - $checked < $this->scanIntervalInSeconds) {
            return false;
        }

        @touch($marker);

        return true;
    }

    /**
     * Marks a file as just used, only if it is still there: `touch` alone
     * would create it, empty, when another request removed it a moment ago.
     */
    public static function refresh(string $path): void
    {
        if (is_file($path)) {
            @touch($path);
        }
    }

    /**
     * @param \Closure(string): void $forget removes every file of an entry
     *
     * @return bool whether the bytes to write now fit
     */
    public function makeRoom(string $directory, int $maximumBytes, int $incomingBytes, string $kept, \Closure $forget): bool
    {
        $entries = [];
        $total = 0;

        // A directory that vanished (a cache clear in another request) holds nothing.
        try {
            $files = is_dir($directory) ? new \FilesystemIterator($directory) : [];
        } catch (\UnexpectedValueException) {
            $files = [];
        }

        foreach ($files as $file) {
            $name = $file->getFilename();
            $entry = explode('.', $name, 2)[0];

            if (str_starts_with($name, '.') || 1 !== preg_match($this->entryPattern, $entry) || !$file->isFile()) {
                continue;
            }

            $size = @filesize($file->getPathname());

            if (false === $size) {
                continue;
            }

            $entries[$entry] ??= ['bytes' => 0, 'used' => 0];
            $entries[$entry]['bytes'] += $size;
            $entries[$entry]['used'] = max($entries[$entry]['used'], (int) @filemtime($file->getPathname()));
            $total += $size;
        }

        uasort($entries, static fn (array $a, array $b): int => $a['used'] <=> $b['used']);

        foreach ($entries as $entry => $files) {
            if ($total + $incomingBytes <= $maximumBytes) {
                break;
            }

            if ((string) $entry === $kept) {
                continue;
            }

            $forget((string) $entry);
            $total -= $files['bytes'];
        }

        return $total + $incomingBytes <= $maximumBytes;
    }
}
