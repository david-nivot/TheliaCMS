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

namespace TheliaCMS\Tests\Support;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use TheliaLibrary\Service\LibraryImageFileNamer;

/**
 * A QuickTime file made in memory: an `ftyp` box of the brand `qt  `, which
 * libmagic reads as `video/quicktime`, the type of a phone's MOV.
 */
final class QuickTimeVideos
{
    public static function bytes(): string
    {
        $box = static fn (string $type, string $body = ''): string => pack('N', 8 + \strlen($body)).$type.$body;

        return $box('ftyp', 'qt  '.pack('N', 0).'qt  ').$box('moov', $box('free')).$box('free');
    }

    public static function small(string $name = 'IMG_0001.MOV'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-mov-');
        file_put_contents($path, self::bytes());
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return new UploadedFile($path, $name, 'video/quicktime', null, true);
    }

    /** A QuickTime file stretched to the given weight (sparse, nothing written). */
    public static function weighing(int $bytes, string $name = 'IMG_0002.MOV'): UploadedFile
    {
        $file = self::small($name);
        $handle = fopen($file->getPathname(), 'r+');
        ftruncate($handle, $bytes);
        fclose($handle);

        return $file;
    }

    /** Whether the TheliaLibrary installed stores QuickTime files (the tests of the conversion need it). */
    public static function libraryStoresThem(): bool
    {
        return \in_array('video/quicktime', self::videoTypesOfTheLibrary(), true);
    }

    /**
     * Read through a declared type: the constant differs from one release of
     * TheliaLibrary to the next, and its value of the day is not the answer.
     *
     * @return list<string>
     */
    private static function videoTypesOfTheLibrary(): array
    {
        return LibraryImageFileNamer::VIDEO_MIME_TYPES;
    }
}
