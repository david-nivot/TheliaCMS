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

use TheliaCMS\Media\Svg\Optimization\Path\PathSegment;

/**
 * Rounds the segments of a path, every position absolute. A relative move
 * starts from the point before as rounded, so no error adds up along a
 * path; a line brought down to nothing can leave.
 *
 * An arc is rounded by ArcRounding from where the segment before it ends
 * once rounded. When even its own values stray from there, the end of that
 * segment keeps its value and the rounding resumes from that segment, with
 * the state saved before it: the rounding takes a time that grows with the
 * path, each segment resumed from at most once.
 *
 * @phpstan-type Point array{float, float}
 * @phpstan-type Drawn array{command: string, values: list<float|string>, end: Point, controls: list<Point>}
 */
final readonly class SegmentRounding
{
    public const array ROLES = [
        'M' => 'xy', 'L' => 'xy', 'H' => 'x', 'V' => 'y', 'C' => 'xyxyxy',
        'S' => 'xyxy', 'Q' => 'xyxy', 'T' => 'xy', 'A' => 'rrnnnxy', 'Z' => '',
    ];

    public function __construct(
        private ArcRounding $arcs = new ArcRounding(),
        private DecimalRounding $numbers = new DecimalRounding(),
    ) {
    }

    /**
     * The segments rounded; null when an arc strays even from where the
     * segment before it ends as drawn.
     *
     * @param list<Drawn> $drawn
     * @param bool        $dropsEmptyLines whether a line rounded to nothing leaves
     *
     * @return list<PathSegment>|null
     */
    public function rounded(array $drawn, int $decimals, float $tolerance, bool $dropsEmptyLines): ?array
    {
        $walk = new RoundingWalk();
        // The segments whose end keeps its value, an arc after them straying
        // from where they end once rounded.
        $keptEnds = [];

        for ($position = 0, $count = \count($drawn); $position < $count; ++$position) {
            $walk->saveBefore($position, $drawn[$position]['command'], $drawn[$position + 1]['command'] ?? '');
            $endDecimals = isset($keptEnds[$position]) ? max($decimals, DecimalRounding::KEPT_DECIMALS) : $decimals;

            if ($this->step($walk, $drawn, $position, $decimals, $endDecimals, $tolerance, $dropsEmptyLines)) {
                continue;
            }

            $resumeAt = $walk->before;

            if (isset($keptEnds[$resumeAt]) || !$walk->resumeAt($resumeAt)) {
                return null;
            }

            $keptEnds[$resumeAt] = true;
            $position = $resumeAt - 1;
        }

        return $walk->written;
    }

    /**
     * Rounds one segment onto the walk; false, the walk untouched, for an
     * arc that strays from where it now starts.
     *
     * @param list<Drawn> $drawn
     */
    private function step(RoundingWalk $walk, array $drawn, int $position, int $decimals, int $endDecimals, float $tolerance, bool $dropsEmptyLines): bool
    {
        $segment = $drawn[$position];
        $arguments = 'A' === $segment['command']
            ? $this->arcs->arguments($segment, $walk->origin, $walk->current, $decimals, $endDecimals, $tolerance)
            : $this->arguments($segment, $decimals, $endDecimals);

        if (null === $arguments) {
            return false;
        }

        [$command, $arguments] = $this->withMirroredPoint($walk, $segment, $arguments, $decimals, $tolerance);
        $end = match ($command) {
            'Z' => $walk->subpathStart,
            'H' => [(float) $arguments[0], $walk->current[1]],
            'V' => [$walk->current[0], (float) $arguments[0]],
            default => [(float) $arguments[\count($arguments) - 2], (float) $arguments[\count($arguments) - 1]],
        };
        $walk->origin = $segment['end'];
        $walk->before = 'Z' === $command ? $walk->subpathPosition : $position;

        if ('M' === $command) {
            $walk->subpathStart = $end;
            $walk->subpathPosition = $position;
        }

        // A line that the rounding brought down to nothing leaves, unless
        // a smooth curve follows: it would then mirror the curve before.
        $next = $drawn[$position + 1]['command'] ?? '';

        if ($dropsEmptyLines && \in_array($segment['command'], ['L', 'H', 'V'], true) && $end === $walk->current && !\in_array($next, ['S', 'T'], true)) {
            return true;
        }

        $walk->written[] = new PathSegment('Z' === $command ? 'z' : $command, $arguments);
        $walk->current = $end;

        return true;
    }

    /**
     * The values of a segment other than an arc, rounded; its end to its own
     * decimals.
     *
     * @param Drawn $segment
     *
     * @return list<string>
     */
    private function arguments(array $segment, int $decimals, int $endDecimals): array
    {
        $roles = str_split(self::ROLES[$segment['command']]);
        $endFrom = \count($roles) - ('H' === $segment['command'] || 'V' === $segment['command'] ? 1 : 2);
        $arguments = [];

        foreach ($segment['values'] as $index => $value) {
            $arguments[] = $this->numbers->rounded((float) $value, $index >= $endFrom ? $endDecimals : $decimals);
        }

        return $arguments;
    }

    /**
     * The command and arguments of the segment, and the control point the
     * next smooth curve mirrors. A smooth curve mirrors a control point it
     * does not write: once rounded, its neighbours can move that point out
     * of the tolerance, and along a chain of them further. It is then
     * written with that point, from where the chain starts again.
     *
     * @param Drawn        $segment
     * @param list<string> $arguments
     *
     * @return array{string, list<string>}
     */
    private function withMirroredPoint(RoundingWalk $walk, array $segment, array $arguments, int $decimals, float $tolerance): array
    {
        $command = $segment['command'];
        [$origin, $current, $mirrored] = [$walk->origin, $walk->current, $walk->mirrored];
        $control = $writtenControl = $segment['end'];

        if ('S' === $command || 'T' === $command) {
            $follows = null !== $mirrored && \in_array($mirrored['after'], 'S' === $command ? ['C', 'S'] : ['Q', 'T'], true);
            $implicit = $follows ? [2 * $origin[0] - $mirrored['drawn'][0], 2 * $origin[1] - $mirrored['drawn'][1]] : $origin;
            $writtenImplicit = $follows ? [2 * $current[0] - $mirrored['written'][0], 2 * $current[1] - $mirrored['written'][1]] : $current;

            if (max(abs($implicit[0] - $writtenImplicit[0]), abs($implicit[1] - $writtenImplicit[1])) > $tolerance) {
                $command = 'S' === $command ? 'C' : 'Q';
                array_unshift($arguments, $this->numbers->rounded($implicit[0], $decimals), $this->numbers->rounded($implicit[1], $decimals));
                $writtenImplicit = [(float) $arguments[0], (float) $arguments[1]];
            }

            $control = 'S' === $segment['command'] ? [(float) $segment['values'][0], (float) $segment['values'][1]] : $implicit;
            $writtenControl = 'S' === $segment['command'] ? [(float) $arguments[\count($arguments) - 4], (float) $arguments[\count($arguments) - 3]] : $writtenImplicit;
        } elseif ('C' === $command || 'Q' === $command) {
            $at = 'C' === $command ? 2 : 0;
            $control = [(float) $segment['values'][$at], (float) $segment['values'][$at + 1]];
            $writtenControl = [(float) $arguments[$at], (float) $arguments[$at + 1]];
        }

        $walk->mirrored = \in_array($command, ['C', 'S', 'Q', 'T'], true) ? ['after' => $segment['command'], 'drawn' => $control, 'written' => $writtenControl] : null;

        return [$command, $arguments];
    }
}
