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
 * The points along an elliptical arc as a renderer draws it, from its ends
 * and arguments (SVG 1.1, appendix F.6.5 and F.6.6): radii too short to
 * join the ends are enlarged, a zero radius draws a straight line, and an
 * arc whose ends meet draws nothing.
 *
 * Two arcs are compared point by point at the same share of their sweep: a
 * rounded radius can move the centre, and with it the whole arc, much
 * further than its ends.
 *
 * A radius, or a distance between the ends, so small that its square is
 * zero for a float cannot be measured: renderers read it as zero or not
 * depending on their precision. Such an arc never stays within, and its path
 * is left as written.
 *
 * @phpstan-type Point array{float, float}
 * An arc is held as its radii, its angle in degrees, and its flags: large
 * arc and sweep.
 * @phpstan-type Arc array{float, float, float, bool, bool}
 */
final readonly class ArcGeometry implements ArcComparison
{
    private const int STEPS = 32;

    /**
     * Whether the second arc stays, on each axis, within the tolerance of the
     * first at every share of their sweeps.
     *
     * @param Point $from
     * @param Arc   $arc
     * @param Point $to
     * @param Point $otherFrom
     * @param Arc   $otherArc
     * @param Point $otherTo
     */
    public function staysWithin(array $from, array $arc, array $to, array $otherFrom, array $otherArc, array $otherTo, float $tolerance): bool
    {
        $points = $this->points($from, $arc, $to);
        $otherPoints = $this->points($otherFrom, $otherArc, $otherTo);

        if (null === $points || null === $otherPoints) {
            return false;
        }

        foreach ($points as $index => [$x, $y]) {
            // Written so that a value that is not a number fails.
            if (!(abs($x - $otherPoints[$index][0]) <= $tolerance && abs($y - $otherPoints[$index][1]) <= $tolerance)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Point $from
     * @param Arc   $arc
     * @param Point $to
     *
     * @return list<Point>|null STEPS + 1 points, from one end to the other; null when the arc cannot be measured
     */
    private function points(array $from, array $arc, array $to): ?array
    {
        [$radiusX, $radiusY, $angle, $large, $sweep] = $arc;
        $cos = cos(deg2rad($angle));
        $sin = sin(deg2rad($angle));
        $chordX = ($from[0] - $to[0]) / 2;
        $chordY = ($from[1] - $to[1]) / 2;
        // The half of the chord, in the axes of the ellipse.
        $halfX = $cos * $chordX + $sin * $chordY;
        $halfY = -$sin * $chordX + $cos * $chordY;
        $radiusX = abs($radiusX);
        $radiusY = abs($radiusY);

        if ($from === $to || 0.0 === $radiusX || 0.0 === $radiusY) {
            return $this->line($from, $to);
        }

        $centred = $this->centred($radiusX, $radiusY, $halfX, $halfY, $large !== $sweep);

        if (null === $centred) {
            return null;
        }

        [$radiusX, $radiusY, $centerX, $centerY] = $centred;
        $start = atan2(($halfY - $centerY) / $radiusY, ($halfX - $centerX) / $radiusX);
        $sweepAngle = atan2((-$halfY - $centerY) / $radiusY, (-$halfX - $centerX) / $radiusX) - $start;

        if ($sweep && $sweepAngle < 0) {
            $sweepAngle += 2 * \M_PI;
        } elseif (!$sweep && $sweepAngle > 0) {
            $sweepAngle -= 2 * \M_PI;
        }

        $middleX = ($from[0] + $to[0]) / 2;
        $middleY = ($from[1] + $to[1]) / 2;
        $points = [];

        for ($step = 0; $step <= self::STEPS; ++$step) {
            $theta = $start + $sweepAngle * $step / self::STEPS;
            $x = $radiusX * cos($theta);
            $y = $radiusY * sin($theta);
            $points[] = [$cos * ($x + $centerX) - $sin * ($y + $centerY) + $middleX, $sin * ($x + $centerX) + $cos * ($y + $centerY) + $middleY];
        }

        return $points;
    }

    /**
     * The radii, enlarged when too short to join the ends, and the centre,
     * in the axes of the ellipse from the middle of the chord; null when a
     * radius or the chord is too small for its square to be a float.
     *
     * @return array{float, float, float, float}|null
     */
    private function centred(float $radiusX, float $radiusY, float $halfX, float $halfY, bool $positive): ?array
    {
        if (0.0 === $radiusX ** 2 || 0.0 === $radiusY ** 2) {
            return null;
        }

        $ratio = $halfX ** 2 / $radiusX ** 2 + $halfY ** 2 / $radiusY ** 2;

        if ($ratio > 1) {
            $radiusX *= sqrt($ratio);
            $radiusY *= sqrt($ratio);
        }

        $numerator = $radiusX ** 2 * $radiusY ** 2 - $radiusX ** 2 * $halfY ** 2 - $radiusY ** 2 * $halfX ** 2;
        $denominator = $radiusX ** 2 * $halfY ** 2 + $radiusY ** 2 * $halfX ** 2;

        if (0.0 === $denominator) {
            return null;
        }

        $factor = ($positive ? 1 : -1) * sqrt(max(0.0, $numerator / $denominator));

        return [$radiusX, $radiusY, $factor * $radiusX * $halfY / $radiusY, -$factor * $radiusY * $halfX / $radiusX];
    }

    /**
     * @param Point $from
     * @param Point $to
     *
     * @return list<Point>
     */
    private function line(array $from, array $to): array
    {
        $points = [];

        for ($step = 0; $step <= self::STEPS; ++$step) {
            $points[] = [$from[0] + ($to[0] - $from[0]) * $step / self::STEPS, $from[1] + ($to[1] - $from[1]) * $step / self::STEPS];
        }

        return $points;
    }
}
