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

use TheliaCMS\Media\Svg\SvgPreparationResult;

/**
 * An SVG prepared for the usage asked: the file as it would be stored, and the
 * drawing the icon usage is judged on, which is the file before the color
 * preset of the usage, so that the preset never makes fit what was not.
 */
final readonly class IconUsageDraft
{
    public function __construct(
        public SvgPreparationResult $result,
        public string $judged,
    ) {
    }
}
