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
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaLibrary\Model\LibraryImage;

/**
 * MP4 files made in memory, no binary fixture: the smallest file libmagic
 * reads as `video/mp4`, a `ftyp` box then a `moov` box holding a bare `mvhd`.
 * It does not play; the library only reads its type.
 */
final class Mp4Videos
{
    /** The smallest MP4 libmagic recognises. */
    public static function bytes(): string
    {
        $mvhd = pack('N', 0).pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', 0).pack('N', 0x00010000).pack('n', 0x0100).str_repeat("\0", 10)
            .pack('N9', 0x00010000, 0, 0, 0, 0x00010000, 0, 0, 0, 0x40000000).str_repeat("\0", 24).pack('N', 1);

        return self::box('ftyp', 'isom'.pack('N', 512).'isomiso2').self::box('moov', self::box('mvhd', $mvhd));
    }

    /**
     * A video of the library of the CMS as a row alone: the type is what tells
     * a video apart, so a test that only reads rows needs no file on disk.
     */
    public static function row(CmsMediaLibrary $library, string $locale, string $title = 'Clip'): LibraryImage
    {
        $video = (new LibraryImage())->setFileName(bin2hex(random_bytes(6)).'.mp4')->setMimeType('video/mp4')->setDecorative(0);
        $video->setLocale($locale)->setTitle($title);
        $video->save();
        $library->tag($video);

        return $video;
    }

    public static function small(string $name = 'clip.mp4'): UploadedFile
    {
        $path = self::temporary();
        file_put_contents($path, self::bytes());

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    /** An MP4 stretched to the given weight (sparse, nothing written). */
    public static function weighing(int $bytes, string $name = 'weighing.mp4'): UploadedFile
    {
        $path = self::temporary();
        file_put_contents($path, self::bytes());
        $handle = fopen($path, 'r+');
        ftruncate($handle, $bytes);
        fclose($handle);

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    /** Text with the name and the declared type of a video: its content says what it is. */
    public static function textNamedAsVideo(string $name = 'faux.mp4'): UploadedFile
    {
        $path = self::temporary();
        file_put_contents($path, 'this is only text');

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    private static function box(string $type, string $body): string
    {
        return pack('N', 8 + \strlen($body)).$type.$body;
    }

    private static function temporary(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-video-');
        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }
}
