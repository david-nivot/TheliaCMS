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

/**
 * How many distances from a point to a line the removal of points may
 * still measure in one drawing. Ramer-Douglas-Peucker measures, at worst, a
 * number that grows with the square of the points of a run; once the budget
 * is spent, no further point leaves and the drawing is only rounded.
 */
final class DistanceBudget
{
    private int $spent = 0;

    public function __construct(private readonly int $maximum)
    {
    }

    /**
     * Whether that many distances may still be measured, counted if so. A
     * refusal spends the rest: nothing is measured afterwards.
     */
    public function spend(int $distances): bool
    {
        if ($this->spent + $distances > $this->maximum) {
            $this->spent = $this->maximum;

            return false;
        }

        $this->spent += $distances;

        return true;
    }

    public function spent(): int
    {
        return $this->spent;
    }
}
