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

/**
 * Where the drawing of an icon of the module is kept.
 */
interface IconFileFinder
{
    /**
     * Path of the SVG file an icon name points at; null when no live image carries the name.
     */
    public function fileOf(string $name): ?string;

    /**
     * @return list<string> the names of the live icons, alphabetical, without the prefix
     */
    public function names(): array;
}
