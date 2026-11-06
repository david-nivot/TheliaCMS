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
 * Where an SVG waits between its sending and the editor's answer: the bytes as
 * they were sent and the name of the file, behind a token, and the result of
 * its preparation once computed, which the preview and the answer read again
 * instead of preparing the file twice more.
 *
 * Nothing here is a stored image. A file lives one hour; a token that does not
 * look like one never reaches the disk, so an address cannot name another file.
 */
final readonly class PreparedSvgStore
{
    public const int LIFETIME_IN_SECONDS = 3600;

    private const string TOKEN_PATTERN = '/^[a-f0-9]{32}\z/';

    /** The setting a result was prepared with: a level name, or `base`. */
    private const string SETTING_PATTERN = '/^[a-z0-9-]{1,64}\z/';

    private string $directory;

    /**
     * @param string $directory where the files wait: under the var directory of
     *                          the install, its own, rather than the temporary
     *                          directory every account and shop of the server shares
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/theliacms/prepared-svg')]
        string $directory,
    ) {
        $this->directory = rtrim($directory, \DIRECTORY_SEPARATOR);
    }

    public static function isToken(string $token): bool
    {
        return 1 === preg_match(self::TOKEN_PATTERN, $token);
    }

    /**
     * @return string the token to find the file again
     */
    public function put(string $content, string $name): string
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->directory, 0o700);
        $this->purge();

        $token = bin2hex(random_bytes(16));
        $filesystem->dumpFile($this->contentPath($token), $content);
        $filesystem->dumpFile($this->namePath($token), $name);

        return $token;
    }

    /**
     * Null when the token is not one, names no file, or names one that is too old.
     */
    public function find(string $token): ?PreparedSvgFile
    {
        if (!self::isToken($token) || !is_file($path = $this->contentPath($token))) {
            return null;
        }

        if ($this->hasExpired($path)) {
            $this->forget($token);

            return null;
        }

        $content = @file_get_contents($path);
        $name = @file_get_contents($this->namePath($token));

        return false === $content ? null : new PreparedSvgFile($token, false === $name ? '' : $name, $content);
    }

    /**
     * Keeps the prepared result of a waiting file, for the given setting.
     */
    public function remember(string $token, string $setting, string $result): void
    {
        if (!self::isToken($token) || 1 !== preg_match(self::SETTING_PATTERN, $setting) || !is_file($this->contentPath($token))) {
            return;
        }

        (new Filesystem())->dumpFile($this->resultPath($token, $setting), $result);
    }

    /**
     * The result kept for a waiting file and a setting, null when none is.
     */
    public function result(string $token, string $setting): ?string
    {
        if (!self::isToken($token) || 1 !== preg_match(self::SETTING_PATTERN, $setting) || !$this->find($token) instanceof PreparedSvgFile) {
            return null;
        }

        $result = @file_get_contents($this->resultPath($token, $setting));

        return false === $result ? null : $result;
    }

    /**
     * Takes a waiting file for an answer: the first caller gets it, a second
     * one, a double click for instance, gets null. The file is left for
     * forget() once the answer is given.
     */
    public function claim(string $token): ?PreparedSvgFile
    {
        $file = $this->find($token);

        if (!$file instanceof PreparedSvgFile || !@rename($this->contentPath($token), $this->directory.\DIRECTORY_SEPARATOR.$token.'.claimed')) {
            return null;
        }

        return $file;
    }

    public function forget(string $token): void
    {
        if (!self::isToken($token)) {
            return;
        }

        (new Filesystem())->remove(glob($this->directory.\DIRECTORY_SEPARATOR.$token.'.*') ?: []);
    }

    /**
     * Run on every write: what nobody came back for does not stay on the disk,
     * a name or a result left without its file included.
     */
    private function purge(): void
    {
        foreach (glob($this->directory.\DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (!is_file($path) || !$this->hasExpired($path)) {
                continue;
            }

            // An expired file takes its name and its results along; anything
            // else that old is left from a write that stopped halfway.
            str_ends_with($path, '.svg') ? $this->forget(basename($path, '.svg')) : @unlink($path);
        }
    }

    private function hasExpired(string $path): bool
    {
        $modified = @filemtime($path);

        return false === $modified || time() - $modified > self::LIFETIME_IN_SECONDS;
    }

    private function resultPath(string $token, string $setting): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$token.'.'.$setting.'.result';
    }

    private function contentPath(string $token): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$token.'.svg';
    }

    private function namePath(string $token): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$token.'.name';
    }
}
