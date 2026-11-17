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

namespace TheliaCMS\Media\Svg\Optimization\Rounding;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevel;

/**
 * The lightest rounding: points within 1/4000 of the diagonal of the drawing.
 */
#[AsTaggedItem(priority: 30)]
final readonly class NoVisibleDifferenceLevel implements SvgOptimizationLevel
{
    public const float TOLERANCE = 1 / 4000;

    public function __construct(private PathRounding $rounding)
    {
    }

    public function name(): string
    {
        return 'round-fine';
    }

    public function label(): string
    {
        return 'No visible difference';
    }

    public function changesRendering(): bool
    {
        return true;
    }

    public function apply(string $svg): string
    {
        return $this->rounding->simplify($svg, self::TOLERANCE);
    }
}
