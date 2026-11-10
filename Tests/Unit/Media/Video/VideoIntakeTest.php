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

namespace TheliaCMS\Tests\Unit\Media\Video;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Admin\CmsMediaType;
use TheliaCMS\Media\Video\FfmpegBinary;
use TheliaCMS\Media\Video\VideoIntake;
use TheliaCMS\Tests\Support\FakeProcessExecutor;
use TheliaCMS\Tests\Support\QuickTimeVideos;

final class VideoIntakeTest extends TestCase
{
    public function testWithoutFfmpegTheLibraryKeepsItsOldRules(): void
    {
        $intake = new VideoIntake(new FfmpegBinary(new FakeProcessExecutor(ffmpegPresent: false)));

        self::assertFalse($intake->converts());
        self::assertSame(['video/mp4'], $intake->mimeTypes());
        self::assertSame(CmsMediaType::MAX_VIDEO_BYTES, $intake->maxBytes());
    }

    public function testWithFfmpegAPhoneVideoUpToOneHundredMegabytesIsTaken(): void
    {
        if (!QuickTimeVideos::libraryStoresThem()) {
            self::markTestSkipped('The TheliaLibrary installed stores MP4 only: it takes the branch that stores QuickTime and WebM.');
        }

        $intake = new VideoIntake(new FfmpegBinary(new FakeProcessExecutor()));

        self::assertTrue($intake->converts());
        self::assertSame(['video/mp4', 'video/quicktime', 'video/webm'], $intake->mimeTypes());
        self::assertSame(100 * 1024 * 1024, $intake->maxBytes());
    }

    public function testThePhpLimitsAreEnoughOnlyWhenTheyReachTheCeiling(): void
    {
        $intake = new VideoIntake(new FfmpegBinary(new FakeProcessExecutor(ffmpegPresent: false)));

        self::assertTrue($intake->phpLimitsAllow(CmsMediaType::MAX_VIDEO_BYTES));
        self::assertFalse($intake->phpLimitsAllow(CmsMediaType::MAX_VIDEO_BYTES - 1));
    }
}
