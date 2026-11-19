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

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What the preparation of an SVG computes before the colors: the cleaning
 * changes and the base optimization of a file, and the output of each level,
 * kept by the bytes of the file sent. A change of colors then redoes the
 * colors and the control only, not the optimization that takes most of the
 * time.
 *
 * A stage is a pure function of the bytes sent, so the key is their hash and
 * no token is needed. A stage lives one hour from its last use: a read
 * refreshes it, which is also what ranks the stages for the ceiling.
 *
 * With the prepared files of `PreparedSvgStore`, which have the same ceiling
 * under their own directory, the two caches weigh no more than 200 MB.
 */
final readonly class SvgStageCache
{
    /**
     * The version of what the stages hold. Bump it whenever the base
     * optimization, a level or the output of the sanitizer changes: the stages
     * kept under the old one are then never read again, instead of serving the
     * previous algorithm for up to an hour after a deploy.
     */
    public const int VERSION = 1;

    private const int LIFETIME_IN_SECONDS = 3600;

    /** All the stages kept weigh no more than this together: half of the 200 MB of the two caches. */
    public const int MAXIMUM_TOTAL_BYTES = 100 * 1024 * 1024;

    private const string STAGE_PATTERN = '/^[a-z0-9-]{1,64}\z/';

    private const string KEY_PATTERN = '/^[a-f0-9]{64}\z/';

    private string $directory;

    private CacheCeiling $ceiling;

    /**
     * @param int $scanIntervalInSeconds how often the directory is purged and weighed, see `CacheCeiling`
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/theliacms/svg-stages')]
        string $directory,
        private int $maximumBytes = self::MAXIMUM_TOTAL_BYTES,
        int $scanIntervalInSeconds = 60,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->directory = rtrim($directory, \DIRECTORY_SEPARATOR);
        $this->ceiling = new CacheCeiling(self::KEY_PATTERN, $scanIntervalInSeconds);
    }

    public static function key(string $original): string
    {
        return hash('sha256', self::VERSION.':'.$original);
    }

    public function get(string $key, string $stage): ?string
    {
        $path = $this->path($key, $stage);

        if (null === $path || !is_file($path) || $this->hasExpired($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        // An empty read is a miss: no stage is empty, and a file emptied by a
        // concurrent removal and a late refresh must not be served.
        if (false === $content || '' === $content) {
            return null;
        }

        // A stage in use is a stage to keep: it leaves last, and lives on.
        CacheCeiling::refresh($path);

        return $content;
    }

    public function put(string $key, string $stage, string $content): void
    {
        $path = $this->path($key, $stage);

        if (null === $path) {
            return;
        }

        // A stage is only a shortcut: a disk that refuses it costs a second
        // computation, not the page.
        try {
            $filesystem = new Filesystem();
            $filesystem->mkdir($this->directory, 0o700);

            if ($this->makeRoom(\strlen($content), $key, $filesystem)) {
                $filesystem->dumpFile($path, $content);
            }
        } catch (IOException $exception) {
            $this->logger->warning('A stage of a prepared SVG could not be kept on the disk.', ['stage' => $stage, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * The directory is purged and weighed once per interval only; between two
     * scans a stage is accepted unless it alone is over the ceiling.
     */
    private function makeRoom(int $incomingBytes, string $key, Filesystem $filesystem): bool
    {
        if (!$this->ceiling->isScanDue($this->directory)) {
            return $incomingBytes <= $this->maximumBytes;
        }

        $this->purge();

        return $this->ceiling->makeRoom($this->directory, $this->maximumBytes, $incomingBytes, $key, function (string $entry) use ($filesystem): void {
            $filesystem->remove(glob($this->directory.\DIRECTORY_SEPARATOR.$entry.'.*') ?: []);
        });
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
        if (1 !== preg_match(self::KEY_PATTERN, $key) || 1 !== preg_match(self::STAGE_PATTERN, $stage)) {
            return null;
        }

        return $this->directory.\DIRECTORY_SEPARATOR.$key.'.'.$stage;
    }
}
