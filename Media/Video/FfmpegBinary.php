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

namespace TheliaCMS\Media\Video;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The ffmpeg of the server. Its path is the `THELIA_CMS_FFMPEG_PATH`
 * environment variable, `ffmpeg` in the PATH when it is not set. Without it the
 * library keeps its older rules: MP4 only, 50 MB at most, nothing converted.
 */
final class FfmpegBinary
{
    public const string DEFAULT_PATH = 'ffmpeg';

    private ?bool $available = null;

    public function __construct(
        private readonly ProcessExecutor $executor,
        #[Autowire(env: 'default::THELIA_CMS_FFMPEG_PATH')]
        private readonly ?string $configuredPath = null,
    ) {
    }

    public function path(): string
    {
        return null === $this->configuredPath || '' === trim($this->configuredPath) ? self::DEFAULT_PATH : trim($this->configuredPath);
    }

    /**
     * Whether the program answers: asked once per process.
     */
    public function isAvailable(): bool
    {
        return $this->available ??= $this->executor->run([$this->path(), '-version'], 10)->succeeded();
    }

    /**
     * The command that turns a video into a web one: H.264 and AAC, 720 pixels
     * at most on the long side (ffmpeg turns a phone video upright from its
     * rotation tag, so a portrait video stays portrait), never enlarged, a
     * constant quality bounded in rate, the index of the file moved to its start
     * (`faststart`) so playback begins before the download ends, and the
     * metadata of the phone left out, its position included.
     *
     * @return list<string>
     */
    public function conversionCommand(string $source, string $target): array
    {
        return [
            $this->path(), '-nostdin', '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $source,
            '-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn',
            '-vf', "scale='min(720,iw)':'min(720,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '26', '-maxrate', '4M', '-bufsize', '8M',
            '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            '-map_metadata', '-1',
            '-movflags', '+faststart',
            $target,
        ];
    }

    /**
     * The command that takes one frame, `$seconds` into the video, as a JPEG.
     *
     * @return list<string>
     */
    public function posterCommand(string $source, string $target, int $seconds): array
    {
        return [
            $this->path(), '-nostdin', '-y', '-hide_banner', '-loglevel', 'error',
            '-ss', (string) $seconds, '-i', $source,
            '-frames:v', '1', '-q:v', '3',
            $target,
        ];
    }
}
