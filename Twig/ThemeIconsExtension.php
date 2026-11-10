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

use TheliaCMS\Media\Icon\IconName;
use TheliaCMS\Media\Icon\IconNames;
use TheliaCMS\Media\Icon\ThemeIcons;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cms_library_icons()`: the names, prefixed, of the icons of the media
 * library. `cms_theme_icons()`: the names of the icons the active theme draws with
 * `ux_icon()`, for a screen or a template that offers them. Not in the sandbox
 * of the components of the site.
 */
final class ThemeIconsExtension extends AbstractExtension
{
    public function __construct(
        private readonly ThemeIcons $icons,
        private readonly IconNames $library,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cms_theme_icons', $this->icons->names(...)),
            new TwigFunction('cms_library_icons', fn (): array => array_map(IconName::prefixed(...), $this->library->names())),
        ];
    }
}
