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

namespace TheliaCMS\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Tests\Support\CeilingImages;

/**
 * Replacing an image goes through this form: the ceiling is held on its file
 * field, as on the image of a page.
 */
final class CmsMediaTypeTest extends TestCase
{
    public function testAnImageUnderTheCeilingIsAccepted(): void
    {
        self::assertSame('', $this->errorOf(CeilingImages::small()));
    }

    public function testAnImageDeclaringTooManyPixelsIsRefused(): void
    {
        self::assertStringContainsString('too many pixels', $this->errorOf(CeilingImages::declaringTooManyPixels()));
    }

    public function testAnImageHeavierThanTheCeilingIsRefused(): void
    {
        self::assertStringContainsString('too heavy', $this->errorOf(CeilingImages::heavy()));
    }

    public function testTheCeilingIsTenMegabytesAsTheScreenCountsThem(): void
    {
        self::assertSame(10_485_760, CmsMediaType::MAX_IMAGE_BYTES);
        self::assertSame('', $this->errorOf(CeilingImages::weighing(10_000_001)), 'A file over ten million bytes is under ten MB.');
        self::assertSame('', $this->errorOf(CeilingImages::weighing(CmsMediaType::MAX_IMAGE_BYTES)), 'The ceiling itself is accepted.');
        self::assertStringContainsString('(12 MiB). It may weigh 10 MiB', $this->errorOf(CeilingImages::weighing(12 * 1024 * 1024)), 'The validator counts in MiB, like the ceiling.');
    }

    private function errorOf(UploadedFile $file): string
    {
        return CeilingImages::errorOf(CmsMediaType::class, 'file', $file);
    }
}
