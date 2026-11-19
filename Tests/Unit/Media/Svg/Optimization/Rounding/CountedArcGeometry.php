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

namespace TheliaCMS\Tests\Unit\Media\Svg\Optimization\Rounding;

use TheliaCMS\Media\Svg\Optimization\Rounding\ArcComparison;
use TheliaCMS\Media\Svg\Optimization\Rounding\ArcGeometry;

/**
 * The geometry of the arcs, counting how many times an arc is checked.
 */
final class CountedArcGeometry implements ArcComparison
{
    public int $checks = 0;

    private ArcGeometry $geometry;

    public function __construct()
    {
        $this->geometry = new ArcGeometry();
    }

    public function staysWithin(array $from, array $arc, array $to, array $otherFrom, array $otherArc, array $otherTo, float $tolerance): bool
    {
        ++$this->checks;

        return $this->geometry->staysWithin($from, $arc, $to, $otherFrom, $otherArc, $otherTo, $tolerance);
    }
}
