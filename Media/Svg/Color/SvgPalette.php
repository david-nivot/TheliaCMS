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
 * The colors of a drawing, the most used first.
 */
final readonly class SvgPalette
{
    /** How many colors the grid of the screen shows before folding the others. */
    public const int SHOWN = 12;

    /**
     * @param list<PaletteColor> $colors
     * @param int                $gradients   the gradients drawn outside a mask
     * @param int                $followsText the fills and strokes already written `currentColor`
     * @param bool               $readable    false for a drawing too complex to read, whose colors stay as they are
     */
    public function __construct(
        public array $colors = [],
        public int $gradients = 0,
        public int $followsText = 0,
        public bool $readable = true,
    ) {
    }

    /**
     * The color of the most elements, the one a preset makes follow the
     * text; null when no element is painted with a plain color.
     */
    public function main(): ?string
    {
        foreach ($this->colors as $color) {
            if ($color->painted() > 0) {
                return $color->key;
            }
        }

        return null;
    }

    /** True when the color paints an element, and so may be the main one. */
    public function paints(string $key): bool
    {
        foreach ($this->colors as $color) {
            if ($color->key === $key) {
                return $color->painted() > 0;
            }
        }

        return false;
    }

    /**
     * The colors that paint an element, the ones that may be the main color,
     * the most used first.
     *
     * @return list<PaletteColor>
     */
    public function candidates(): array
    {
        return array_values(array_filter($this->colors, static fn (PaletteColor $color): bool => $color->painted() > 0));
    }

    public function hasWhite(): bool
    {
        foreach ($this->colors as $color) {
            if ($color->isWhite()) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return [] === $this->colors;
    }
}
