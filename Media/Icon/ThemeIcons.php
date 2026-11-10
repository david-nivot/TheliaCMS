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

namespace TheliaCMS\Media\Icon;

use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\UX\Icons\IconRegistryInterface;
use Thelia\Core\Template\TemplateDefinition;
use Thelia\Core\Template\TemplateHelperInterface;

/**
 * The icons the active front theme draws with `ux_icon('name')`: the SVGs of
 * the `assets/icons` directory of the theme and of each theme it inherits from.
 *
 * ux-icons cannot list its local sources (its registry and its finder are
 * internal and read one directory each), so the directories are read here, the
 * way ux-icons itself finds the icons of its own: SVG files at the root of the
 * directory, named without the prefix. The nearest theme answers first, as in
 * the chain of registries a theme such as Flexy declares: an icon a child
 * theme redraws replaces the one of its parent.
 *
 * Neither Iconify nor the `cms:` set is listed. Without ux-icons, or with a
 * theme that has no icons, the list is empty.
 *
 * The directories are read once and kept: `isKnown()` is asked for every icon
 * of every page drawn, and walking the disk each time would cost more than the
 * drawing. The memory lives as long as the service: one request under PHP-FPM,
 * and until the next `reset()` in a worker, which the container calls between
 * two messages, so a theme changed or an icon added is seen by the next one.
 */
final class ThemeIcons implements ResetInterface
{
    private const string DIRECTORY = '/assets/icons';

    /** @var array<string, string>|null */
    private ?array $read = null;

    /** @var list<string>|null */
    private ?array $sorted = null;

    public function __construct(private readonly TemplateHelperInterface $templates)
    {
    }

    public function has(string $name): bool
    {
        return isset($this->files()[$name]);
    }

    public function reset(): void
    {
        $this->read = null;
        $this->sorted = null;
    }

    /**
     * @return list<string> the names, alphabetical
     */
    public function names(): array
    {
        if (null === $this->sorted) {
            $names = array_keys($this->files());
            sort($names);
            $this->sorted = $names;
        }

        return $this->sorted;
    }

    /**
     * @return array<string, string> the file of each icon, by name
     */
    public function files(): array
    {
        return $this->read ??= $this->readDirectories();
    }

    /**
     * @return array<string, string>
     */
    private function readDirectories(): array
    {
        if (!interface_exists(IconRegistryInterface::class)) {
            return [];
        }

        $files = [];

        foreach ($this->directories() as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            // A sub-directory is a set, at any depth: ux-icons draws
            // `set/flag.svg` as `set:flag` and `a/b/x.svg` as `a:b:x`.
            foreach ((new Finder())->files()->in($directory)->name('*.svg') as $file) {
                $set = str_replace(['\\', '/'], ':', $file->getRelativePath());
                $name = ('' === $set ? '' : $set.':').$file->getBasename('.svg');

                if (IconName::isReference($name) && !isset($files[$name])) {
                    $files[$name] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * @return list<string> the icon directory of the active theme, then of its parents
     */
    private function directories(): array
    {
        try {
            $theme = $this->templates->getActiveFrontTemplate();
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            static fn (TemplateDefinition $template): string => $template->getAbsolutePath().self::DIRECTORY,
            [$theme, ...array_values($theme->getParentList() ?? [])],
        );
    }
}
