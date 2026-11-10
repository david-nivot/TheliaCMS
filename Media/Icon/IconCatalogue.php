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

use TheliaCMS\Media\Svg\Color\SvgPreviewTint;

/**
 * What an editor picks an icon from: the icons of the theme and those of the
 * media library, each with the small picture the picker draws.
 *
 * The picture is a `data:` address of the SVG, for an `<img>`: an SVG shown
 * that way runs nothing, whatever it holds. A file too heavy to be sent along
 * a list of a hundred has no picture.
 */
final readonly class IconCatalogue
{
    public const string GROUP_THEME = 'theme';

    public const string GROUP_CMS = 'cms';

    /** Above this, the picture is left out of the list. */
    private const int MAXIMUM_PREVIEW_BYTES = 51200;

    public function __construct(
        private ThemeIcons $theme,
        private IconNames $library,
    ) {
    }

    /**
     * @return list<array{name: string, label: string, group: string, set: string, preview: string}>
     */
    public function rows(): array
    {
        $rows = [];

        // An icon of a sub-directory of the theme (`set:name`) is listed with
        // its set, as the icons story of the toolkit groups them.
        foreach ($this->theme->files() as $name => $file) {
            // The set is all but the last segment: `a:b:x` is `x` of the set `a:b`.
            $set = str_contains($name, ':') ? substr($name, 0, (int) strrpos($name, ':')) : '';
            $rows[] = self::row($name, '' === $set ? $name : substr($name, \strlen($set) + 1), self::GROUP_THEME, $set, $file);
        }

        foreach ($this->library->filesByName() as $name => $file) {
            $rows[] = self::row(IconName::prefixed($name), $name, self::GROUP_CMS, '', $file);
        }

        usort($rows, static fn (array $first, array $second): int => [$first['group'], $first['set'], $first['name']] <=> [$second['group'], $second['set'], $second['name']]);

        return $rows;
    }

    /**
     * A color that follows the text is drawn dark: an `<img>` receives no
     * color of the page.
     *
     * @return array{name: string, label: string, group: string, set: string, preview: string}
     */
    private static function row(string $name, string $slug, string $group, string $set, string $file): array
    {
        $svg = self::svg($file);

        return [
            'name' => $name,
            'label' => ucfirst(str_replace('-', ' ', $slug)),
            'group' => $group,
            'set' => $set,
            'preview' => self::picture($svg, SvgPreviewTint::Light),
        ];
    }

    private static function svg(string $file): string
    {
        $size = @filesize($file);

        if (false === $size || $size > self::MAXIMUM_PREVIEW_BYTES) {
            return '';
        }

        $svg = @file_get_contents($file);

        return false === $svg ? '' : $svg;
    }

    /** A picture for an `<img>`, never markup: an SVG runs nothing there. */
    private static function picture(string $svg, SvgPreviewTint $tint): string
    {
        return '' === $svg ? '' : 'data:image/svg+xml;base64,'.base64_encode($tint->apply($svg));
    }
}
