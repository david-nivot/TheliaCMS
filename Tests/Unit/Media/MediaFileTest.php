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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\MediaFile;

final class MediaFileTest extends TestCase
{
    #[DataProvider('formats')]
    public function testOnlyAnSvgIsVector(string $format, bool $vector): void
    {
        self::assertSame($vector, (new MediaFile(id: 1, format: $format))->isVector());
        self::assertSame($vector, MediaFile::isVectorFormat($format));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function formats(): iterable
    {
        yield 'svg' => ['svg', true];
        yield 'uppercase' => ['SVG', true];
        yield 'jpg' => ['jpg', false];
        yield 'png' => ['png', false];
        yield 'webp' => ['webp', false];
    }
}
