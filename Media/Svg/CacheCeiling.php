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
 * leave first, and the entry being written never does.
 */
final readonly class CacheCeiling
{
    /**
     * @param \Closure(string): void $forget removes every file of an entry
     *
     * @return bool whether the bytes to write now fit
     */
    public function makeRoom(string $directory, int $maximumBytes, int $incomingBytes, string $kept, \Closure $forget): bool
    {
        $entries = [];
        $total = 0;

        foreach (glob($directory.\DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            $size = @filesize($path);

            if (!is_file($path) || false === $size) {
                continue;
            }

            $entry = explode('.', basename($path), 2)[0];
            $entries[$entry] ??= ['bytes' => 0, 'used' => 0];
            $entries[$entry]['bytes'] += $size;
            $entries[$entry]['used'] = max($entries[$entry]['used'], (int) @filemtime($path));
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
