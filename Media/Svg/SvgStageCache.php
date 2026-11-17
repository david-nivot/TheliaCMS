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

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What the preparation of an SVG computes before the colors: the cleaning
 * changes and the base optimization of a file, and the output of each level,
 * kept by the bytes of the file sent. A change of colors then redoes the
 * colors and the control only, not the optimization that takes most of the
 * time.
 *
 * A stage is a pure function of the bytes sent, so the key is their hash and
 * no token is needed. Nothing here outlives an hour.
 */
final readonly class SvgStageCache
{
    private const int LIFETIME_IN_SECONDS = 3600;

    /** All the stages kept weigh no more than this together. */
    public const int MAXIMUM_TOTAL_BYTES = 200 * 1024 * 1024;

    private const string STAGE_PATTERN = '/^[a-z0-9-]{1,64}\z/';

    private string $directory;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/theliacms/svg-stages')]
        string $directory,
        private int $maximumBytes = self::MAXIMUM_TOTAL_BYTES,
    ) {
        $this->directory = rtrim($directory, \DIRECTORY_SEPARATOR);
    }

    public static function key(string $original): string
    {
        return hash('sha256', $original);
    }

    public function get(string $key, string $stage): ?string
    {
        $path = $this->path($key, $stage);

        if (null === $path || !is_file($path) || $this->hasExpired($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return false === $content ? null : $content;
    }

    public function put(string $key, string $stage, string $content): void
    {
        $path = $this->path($key, $stage);

        if (null === $path) {
            return;
        }

        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory, 0o700);
        $this->purge();

        // A stage is only a shortcut: one that does not fit is computed again.
        $room = (new CacheCeiling())->makeRoom($this->directory, $this->maximumBytes, \strlen($content), $key, function (string $entry) use ($filesystem): void {
            $filesystem->remove(glob($this->directory.\DIRECTORY_SEPARATOR.$entry.'.*') ?: []);
        });

        if ($room) {
            $filesystem->dumpFile($path, $content);
        }
    }

    private function purge(): void
    {
        foreach (glob($this->directory.\DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path) && $this->hasExpired($path)) {
                @unlink($path);
            }
        }
    }

    private function hasExpired(string $path): bool
    {
        $modified = @filemtime($path);

        return false === $modified || time() - $modified > self::LIFETIME_IN_SECONDS;
    }

    private function path(string $key, string $stage): ?string
    {
        if (1 !== preg_match('/^[a-f0-9]{64}\z/', $key) || 1 !== preg_match(self::STAGE_PATTERN, $stage)) {
            return null;
        }

        return $this->directory.\DIRECTORY_SEPARATOR.$key.'.'.$stage;
    }
}
