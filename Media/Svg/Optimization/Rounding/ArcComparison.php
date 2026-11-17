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
 * Compares an arc as written with the arc it was drawn as.
 *
 * @phpstan-type Point array{float, float}
 * An arc is held as its radii, its angle in degrees, and its flags: large
 * arc and sweep.
 * @phpstan-type Arc array{float, float, float, bool, bool}
 */
interface ArcComparison
{
    /**
     * Whether the second arc stays, on each axis, within the tolerance of the
     * first all along it.
     *
     * @param Point $from
     * @param Arc   $arc
     * @param Point $to
     * @param Point $otherFrom
     * @param Arc   $otherArc
     * @param Point $otherTo
     */
    public function staysWithin(array $from, array $arc, array $to, array $otherFrom, array $otherArc, array $otherTo, float $tolerance): bool;
}
