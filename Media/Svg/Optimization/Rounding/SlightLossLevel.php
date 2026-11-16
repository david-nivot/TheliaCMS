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
 * Points within 1/1000 of the diagonal of the drawing: a fine detail may move slightly.
 */
#[AsTaggedItem(priority: 20)]
final readonly class SlightLossLevel implements SvgOptimizationLevel
{
    public const float TOLERANCE = 1 / 1000;

    public function __construct(private PathRounding $rounding)
    {
    }

    public function name(): string
    {
        return 'round-medium';
    }

    public function label(): string
    {
        return 'Slight loss of detail';
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
