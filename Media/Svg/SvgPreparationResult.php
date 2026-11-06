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

use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\Color\SvgPalette;
use TheliaLibrary\Service\Svg\SvgChange;

/**
 * An SVG as the library will store it, and what led there.
 */
final readonly class SvgPreparationResult
{
    /**
     * @param list<SvgChange> $changes what the cleaning took out of the original
     */
    public function __construct(
        public string $content,
        public array $changes,
        public int $originalBytes,
        public int $resultBytes,
        public float $width,
        public float $height,
        // The colors of the drawing before the preset, and the setting
        // applied, its main color told.
        public SvgPalette $palette = new SvgPalette(),
        public SvgColoring $coloring = new SvgColoring(),
    ) {
    }

    /** Bytes saved; negative when the result is heavier than the original. */
    public function savedBytes(): int
    {
        return $this->originalBytes - $this->resultBytes;
    }

    /** The share of the original that left, in percent; negative when the result is heavier. */
    public function savedPercent(): int
    {
        return $this->originalBytes > 0 ? (int) round($this->savedBytes() * 100 / $this->originalBytes) : 0;
    }
}
