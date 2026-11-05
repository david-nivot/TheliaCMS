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

namespace TheliaCMS\Media\Svg\Optimization;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * An optimization the editor may offer on top of the base one. Nothing
 * implements it yet: the tag is the extension point.
 */
#[AutoconfigureTag(SvgOptimizationLevel::TAG)]
interface SvgOptimizationLevel
{
    public const string TAG = 'theliacms.svg_optimization_level';

    /**
     * Stable key, [a-z0-9-]+.
     */
    public function name(): string;

    /**
     * Translation key of the theliacms domain.
     */
    public function label(): string;

    /**
     * True when the level may change how the image renders.
     */
    public function changesRendering(): bool;

    /**
     * Applied after the base optimization.
     */
    public function apply(string $svg): string;
}
