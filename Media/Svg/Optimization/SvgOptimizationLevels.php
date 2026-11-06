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

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SvgOptimizationLevels
{
    /** @var list<SvgOptimizationLevel> */
    private array $levels;

    /**
     * @param iterable<SvgOptimizationLevel> $levels
     */
    public function __construct(#[AutowireIterator(SvgOptimizationLevel::TAG)] iterable $levels)
    {
        $this->levels = array_values([...$levels]);
    }

    /**
     * @return list<SvgOptimizationLevel>
     */
    public function all(): array
    {
        return $this->levels;
    }

    public function get(string $name): ?SvgOptimizationLevel
    {
        foreach ($this->levels as $level) {
            if ($level->name() === $name) {
                return $level;
            }
        }

        return null;
    }
}
