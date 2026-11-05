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

namespace TheliaCMS\Tests\Unit\Media\Svg\Optimization;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevel;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevels;

final class SvgOptimizationLevelsTest extends TestCase
{
    public function testLevelsAreListedInOrderAndFoundByName(): void
    {
        $first = $this->level('precision');
        $second = $this->level('shapes');
        $levels = new SvgOptimizationLevels(new \ArrayIterator([$first, $second]));

        self::assertSame([$first, $second], $levels->all());
        self::assertSame($second, $levels->get('shapes'));
        self::assertNull($levels->get('unknown'));
    }

    public function testNoLevelIsShippedYet(): void
    {
        self::assertSame([], (new SvgOptimizationLevels([]))->all());
    }

    private function level(string $name): SvgOptimizationLevel
    {
        return new readonly class($name) implements SvgOptimizationLevel {
            public function __construct(private string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function label(): string
            {
                return 'svg.level.'.$this->name;
            }

            public function changesRendering(): bool
            {
                return false;
            }

            public function apply(string $svg): string
            {
                return $svg;
            }
        };
    }
}
