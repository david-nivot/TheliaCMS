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

use TheliaCMS\Media\Svg\Optimization\Path\ExactDecimal;

/**
 * Rounds an arc so that it stays, all along it, within the tolerance of
 * where it was drawn, from where it now starts: its radii rounded to the
 * decimals of the points, then to more, then kept as they were, its end
 * rounded; then its end kept too. Its flags and angle stay as written.
 *
 * @phpstan-type Point array{float, float}
 * @phpstan-type Drawn array{command: string, values: list<float|string>, end: Point, controls: list<Point>}
 */
final readonly class ArcRounding
{
    // Decimals a rounded radius may take beyond those of the points.
    private const int EXTRA_RADIUS_DECIMALS = 3;

    public function __construct(
        private ArcComparison $geometry = new ArcGeometry(),
        private DecimalRounding $numbers = new DecimalRounding(),
    ) {
    }

    /**
     * The arguments of the arc, the first candidate that holds; null when
     * none does.
     *
     * @param Drawn $segment
     * @param Point $origin  where it starts, as drawn
     * @param Point $current where it starts, as written
     *
     * @return list<string>|null
     */
    public function arguments(array $segment, array $origin, array $current, int $decimals, int $endDecimals, float $tolerance): ?array
    {
        [$radiusX, $radiusY, $angle, $large, $sweep, $x, $y] = $segment['values'];
        $drawnArc = [(float) $radiusX, (float) $radiusY, (float) $angle, '1' === $large, '1' === $sweep];
        $kept = max($decimals, DecimalRounding::KEPT_DECIMALS);
        $candidates = [];

        for ($extra = 0; $extra <= self::EXTRA_RADIUS_DECIMALS; ++$extra) {
            $candidates[] = [$this->roundedRadius((float) $radiusX, $decimals + $extra), $this->roundedRadius((float) $radiusY, $decimals + $extra), $endDecimals];
        }

        $candidates[] = [$this->numbers->rounded((float) $radiusX, $kept), $this->numbers->rounded((float) $radiusY, $kept), $endDecimals];
        $candidates[] = [$this->numbers->rounded((float) $radiusX, $kept), $this->numbers->rounded((float) $radiusY, $kept), $kept];

        foreach ($candidates as [$writtenX, $writtenY, $endAt]) {
            $end = [$this->numbers->rounded((float) $x, $endAt), $this->numbers->rounded((float) $y, $endAt)];
            $writtenArc = [(float) $writtenX, (float) $writtenY, (float) $angle, '1' === $large, '1' === $sweep];

            if ($this->geometry->staysWithin($origin, $drawnArc, $segment['end'], $current, $writtenArc, [(float) $end[0], (float) $end[1]], $tolerance)) {
                return [$writtenX, $writtenY, (string) $angle, (string) $large, (string) $sweep, $end[0], $end[1]];
            }
        }

        return null;
    }

    // A radius never rounds to zero: a zero radius draws a straight line,
    // where a radius too short to join the ends is enlarged by the renderer.
    private function roundedRadius(float $radius, int $decimals): string
    {
        $rounded = $this->numbers->rounded($radius, $decimals);

        if ('0' !== $rounded || 0.0 === $radius) {
            return $rounded;
        }

        return ExactDecimal::fromText(($radius < 0 ? '-' : '').'1e'.(-$decimals))?->toText() ?? $rounded;
    }
}
