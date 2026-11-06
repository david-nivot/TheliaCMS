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

namespace TheliaCMS\Twig;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Thelia\Model\ConfigQuery;
use TheliaCMS\TheliaCMS;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A script or a stylesheet of the back office of the module, served as the
 * installed module holds it: `{{ cms_asset('assets/content-editor.js') }}`.
 *
 * `module_asset()` of the core copies an asset to the public directory the
 * first time a page asks for it and, outside debug mode, never again: after
 * an update of the module, the screens kept the scripts of the version
 * before. The copy is replaced here when it no longer matches its source,
 * by size or by date: the core's copy keeps the date of its source, so a
 * copy that is up to date costs two reads of the disk and nothing more.
 */
final class BackOfficeAssetExtension extends AbstractExtension
{
    private const string SOURCE = __DIR__.'/../templates/backOffice/default-twig/';

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cms_asset', $this->asset(...), ['needs_environment' => true]),
        ];
    }

    public function asset(Environment $twig, string $path): string
    {
        $moduleAsset = $twig->getFunction('module_asset')?->getCallable();

        if (!\is_callable($moduleAsset)) {
            throw new \LogicException('cms_asset() needs module_asset() of the TwigEngine module.');
        }

        $url = (string) $moduleAsset(TheliaCMS::getModuleCode(), $path);
        $served = self::servedFile($url);

        if (null !== $served) {
            self::refresh(self::SOURCE.$path, $served);
        }

        return $url;
    }

    /**
     * Replaces the served copy by its source when they differ, through a
     * file renamed into place, so a page asked for at the same moment reads
     * the old file or the new one, never half of one. A copy that cannot be
     * written is left as it is: the page still loads, with the old asset.
     *
     * @return bool whether the copy was replaced
     */
    public static function refresh(string $source, string $served): bool
    {
        if (!is_file($source) || !is_file($served)) {
            return false;
        }

        clearstatcache(true, $served);

        if (filesize($source) === filesize($served) && filemtime($source) === filemtime($served)) {
            return false;
        }

        $filesystem = new Filesystem();
        $next = $served.'.'.bin2hex(random_bytes(4)).'.tmp';

        try {
            // The copy takes the date of its source, which the next page compares.
            $filesystem->copy($source, $next, true);
            $filesystem->rename($next, $served, true);
        } catch (IOExceptionInterface) {
            $filesystem->remove($next);

            return false;
        }

        return true;
    }

    /**
     * The file behind the address the core gave: its path from the directory
     * of the assets, under the public directory. The address may hold a host
     * (a CDN) or the folder of a shop installed below the root of its site.
     */
    private static function servedFile(string $url): ?string
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        $directory = '/'.trim((string) ConfigQuery::read('asset_dir_from_web_root', 'assets'), '/').'/';
        $start = strpos($path, $directory);

        if (false === $start || !\defined('THELIA_WEB_DIR')) {
            return null;
        }

        return rtrim((string) \constant('THELIA_WEB_DIR'), '/').'/'.ltrim(rawurldecode(substr($path, $start)), '/');
    }
}
