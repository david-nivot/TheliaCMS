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
use TheliaCMS\Media\Video\FfmpegBinary;
use TheliaCMS\Tests\Support\FakeProcessExecutor;

final class FfmpegBinaryTest extends TestCase
{
    public function testTheCommandMakesAFastStartH264Aac720PixelVideoFromTheFileAsItIs(): void
    {
        $command = (new FfmpegBinary(new FakeProcessExecutor()))->conversionCommand('/in/IMG.MOV', '/out/converted.mp4');

        self::assertSame('ffmpeg', $command[0]);
        self::assertSame('/in/IMG.MOV', $command[array_search('-i', $command, true) + 1]);
        self::assertSame('/out/converted.mp4', end($command));
        self::assertSame('libx264', $command[array_search('-c:v', $command, true) + 1]);
        self::assertSame('aac', $command[array_search('-c:a', $command, true) + 1]);
        self::assertSame('+faststart', $command[array_search('-movflags', $command, true) + 1], 'Playback starts before the download ends.');
        self::assertSame('yuv420p', $command[array_search('-pix_fmt', $command, true) + 1], 'Every browser plays it.');
        self::assertSame('-1', $command[array_search('-map_metadata', $command, true) + 1], 'The position of the phone is not published.');
        self::assertContains('0:a:0?', $command, 'A video without sound converts too.');
        self::assertStringContainsString("min(720,iw)':'min(720,ih)'", $command[array_search('-vf', $command, true) + 1], '720 pixels on the long side, whatever the orientation, never enlarged.');
        self::assertStringContainsString('force_divisible_by=2', $command[array_search('-vf', $command, true) + 1]);
        self::assertContains('-nostdin', $command, 'ffmpeg never waits for a keyboard in a worker.');
    }

    public function testThePosterCommandTakesOneJpegFrameAtTheSecondAsked(): void
    {
        $command = (new FfmpegBinary(new FakeProcessExecutor()))->posterCommand('/in.mp4', '/out/poster.jpg', 2);

        self::assertSame('2', $command[array_search('-ss', $command, true) + 1]);
        self::assertSame('1', $command[array_search('-frames:v', $command, true) + 1]);
        self::assertSame('/out/poster.jpg', end($command));
    }

    public function testThePathIsTheOneConfiguredOrFfmpegInThePath(): void
    {
        self::assertSame('ffmpeg', (new FfmpegBinary(new FakeProcessExecutor()))->path());
        self::assertSame('ffmpeg', (new FfmpegBinary(new FakeProcessExecutor(), '  '))->path());
        self::assertSame('/opt/ffmpeg/bin/ffmpeg', (new FfmpegBinary(new FakeProcessExecutor(), '/opt/ffmpeg/bin/ffmpeg'))->path());
        self::assertSame('/opt/ffmpeg/bin/ffmpeg', (new FfmpegBinary(new FakeProcessExecutor(), '/opt/ffmpeg/bin/ffmpeg'))->conversionCommand('a', 'b')[0]);
    }

    public function testItIsAvailableWhenTheProgramAnswersAndAskedOnlyOnce(): void
    {
        $present = new FakeProcessExecutor();
        $binary = new FfmpegBinary($present);

        self::assertTrue($binary->isAvailable());
        self::assertTrue($binary->isAvailable());
        self::assertCount(1, $present->commands, 'Asked once per process.');
        self::assertSame(['ffmpeg', '-version'], $present->commands[0]);

        self::assertFalse((new FfmpegBinary(new FakeProcessExecutor(ffmpegPresent: false)))->isAvailable());
    }
}
