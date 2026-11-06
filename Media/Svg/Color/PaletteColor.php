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

namespace TheliaCMS\Media\Svg\Color;

/**
 * One color of a drawing, and how many times it is used.
 */
final readonly class PaletteColor
{
    public function __construct(
        public string $key,
        public int $fills,
        public int $strokes,
        public int $stops,
    ) {
    }

    /** The elements painted with it, a fill and a stroke counting apart. */
    public function painted(): int
    {
        return $this->fills + $this->strokes;
    }

    public function isWhite(): bool
    {
        return SvgColor::WHITE === $this->key;
    }
}
