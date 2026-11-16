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
 * The strongest rounding: points within 1/400 of the diagonal of the drawing, the lightest file; a finely drawn image loses some detail.
 */
#[AsTaggedItem(priority: 10)]
final readonly class VisibleLossLevel implements SvgOptimizationLevel
{
    public const float TOLERANCE = 1 / 400;

    public function __construct(private PathRounding $rounding)
    {
    }

    public function name(): string
    {
        return 'round-coarse';
    }

    public function label(): string
    {
        return 'Visible loss of detail';
    }

    public function changesRendering(): bool
    {
        return true;
    }

    public function apply(string $svg): string
    {
        return $this->rounding->round($svg, self::TOLERANCE);
    }
}
