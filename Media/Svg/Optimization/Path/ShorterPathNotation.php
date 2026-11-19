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

namespace TheliaCMS\Media\Svg\Optimization\Path;

/**
 * Writes each command of a path in the shorter of its absolute and relative
 * forms, and a lineto that keeps one coordinate as a horizontal or vertical
 * one. No value changes: the positions are computed exactly, the path
 * written is read back and compared with the one read, and the data is
 * replaced only when it comes out shorter. A path holding more numbers than
 * the ceiling of PathData is left as written.
 */
final readonly class ShorterPathNotation
{
    /**
     * Per command, what each argument is: an x or y coordinate, which moves
     * with the notation, or a value that does not (radius, angle, flag).
     */
    private const array ROLES = [
        'M' => 'xy', 'L' => 'xy', 'H' => 'x', 'V' => 'y', 'C' => 'xyxyxy',
        'S' => 'xyxy', 'Q' => 'xyxy', 'T' => 'xy', 'A' => 'nnnnnxy', 'Z' => '',
    ];

    // A selector on the value of an attribute could aim at a path by its data.
    private const string ATTRIBUTE_VALUE_SELECTOR = '/\[[^\]]*=/';

    public function __construct(private PathData $paths = new PathData())
    {
    }

    public function shorten(\DOMDocument $document): bool
    {
        foreach ($document->getElementsByTagName('style') as $style) {
            if (1 === preg_match(self::ATTRIBUTE_VALUE_SELECTOR, $style->textContent)) {
                return false;
            }
        }

        $changed = false;

        foreach ($document->getElementsByTagName('path') as $path) {
            $shorter = $path->hasAttribute('d') ? $this->shorterData($path->getAttribute('d')) : null;

            if (null !== $shorter) {
                $path->setAttribute('d', $shorter);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Null when the data cannot be read, comes out no shorter, or would not
     * read back as the same path.
     */
    public function shorterData(string $data): ?string
    {
        $segments = $this->paths->segments($data);

        if (null === $segments) {
            return null;
        }

        $written = $this->written($segments);

        if (null === $written || \strlen($written) >= \strlen($data)) {
            return null;
        }

        $readBack = $this->paths->segments($written);

        return null !== $readBack && $this->samePath($segments, $readBack) ? $written : null;
    }

    /**
     * The shortest data over the whole path, not command by command: the
     * notation of a command decides whether the next one needs its letter
     * and a separator, so a command one character shorter can cost two
     * further on. A first walk finds, for each command, the cheapest way to
     * reach each of its notations; a second one writes the cheapest chain.
     * Between chains of the same length, the one that changes the fewest
     * commands from how they were written wins: a change that saves nothing
     * only breaks the repetitions compression relies on.
     *
     * @param list<PathSegment> $segments
     */
    private function written(array $segments): ?string
    {
        $links = [];
        $costs = [];
        $previous = [];

        foreach ($this->notationsOf($segments) as $position => $notations) {
            if (null === $notations) {
                return null;
            }

            $reached = [];
            $from = [];

            foreach ($notations as $index => $notation) {
                $reached[$index] = null;
                $from[$index] = 0;
                $changed = 'Z' !== strtoupper($notation->command) && $notation->isRelative() !== $segments[$position]->isRelative() ? 1 : 0;

                foreach ([] === $previous ? [null] : $previous as $before => $segment) {
                    [$length, $changes] = $costs[$before] ?? [0, 0];
                    $cost = [$length + \strlen($this->paths->fragment($notation, $segment->command ?? '', null === $segment ? '' : $this->lastArgument($segment))), $changes + $changed];

                    if (null === $reached[$index] || $cost < $reached[$index]) {
                        $reached[$index] = $cost;
                        $from[$index] = $before;
                    }
                }
            }

            $links[] = $from;
            $costs = $reached;
            $previous = $notations;
        }

        if ([] === $links) {
            return null;
        }

        $chosen = array_fill(0, \count($links), 0);
        $index = array_search(min($costs), $costs, true);

        for ($position = \count($links) - 1; $position >= 0; --$position) {
            $chosen[$position] = (int) $index;
            $index = $links[$position][$index];
        }

        $data = '';
        $command = '';
        $argument = '';

        foreach ($this->notationsOf($segments) as $position => $notations) {
            $segment = $notations[$chosen[$position]] ?? null;

            if (null === $segment) {
                return null;
            }

            $data .= $this->paths->fragment($segment, $command, $argument);
            $command = $segment->command;
            $argument = $this->lastArgument($segment);
        }

        return $data;
    }

    /**
     * The notations of each command in turn (the absolute one first), or
     * null where a value cannot be held exactly.
     *
     * @param list<PathSegment> $segments
     *
     * @return \Generator<int, list<PathSegment>|null>
     */
    private function notationsOf(array $segments): \Generator
    {
        $current = [ExactDecimal::zero(), ExactDecimal::zero()];
        $start = $current;

        foreach ($segments as $segment) {
            $absolute = $this->absolute($segment, $current, $start);
            $notations = null === $absolute ? null : $this->notations($absolute[0], $absolute[1], $current);

            if (null === $absolute || null === $notations) {
                yield null;

                return;
            }

            yield $notations;

            if ('M' === $absolute[0]) {
                $start = $absolute[2];
            }

            $current = $absolute[2];
        }
    }

    private function lastArgument(PathSegment $segment): string
    {
        return [] === $segment->arguments ? '' : $segment->arguments[array_key_last($segment->arguments)];
    }

    /**
     * The segment as an absolute command (a horizontal or vertical one when
     * it keeps a coordinate) with its values, and the point it ends on: a
     * close ends on the start of its subpath.
     *
     * @param array{ExactDecimal, ExactDecimal} $current
     * @param array{ExactDecimal, ExactDecimal} $start
     *
     * @return array{string, list<ExactDecimal>, array{ExactDecimal, ExactDecimal}}|null
     */
    private function absolute(PathSegment $segment, array $current, array $start): ?array
    {
        $command = strtoupper($segment->command);

        if ('Z' === $command) {
            return ['Z', [], $start];
        }

        $values = [];

        foreach (str_split(self::ROLES[$command]) as $index => $role) {
            $value = ExactDecimal::fromText($segment->arguments[$index]);

            if (null !== $value && $segment->isRelative() && 'n' !== $role) {
                $value = $value->plus('x' === $role ? $current[0] : $current[1]);
            }

            if (null === $value) {
                return null;
            }

            $values[] = $value;
        }

        $next = match ($command) {
            'H' => [$values[0], $current[1]],
            'V' => [$current[0], $values[0]],
            default => [$values[\count($values) - 2], $values[\count($values) - 1]],
        };

        if ('L' === $command && $next[1]->equals($current[1]) && !$next[0]->equals($current[0])) {
            return ['H', [$next[0]], $next];
        }

        if ('L' === $command && $next[0]->equals($current[0]) && !$next[1]->equals($current[1])) {
            return ['V', [$next[1]], $next];
        }

        return [$command, $values, $next];
    }

    /**
     * The absolute form, then the relative one.
     *
     * @param list<ExactDecimal>                $values
     * @param array{ExactDecimal, ExactDecimal} $current
     *
     * @return list<PathSegment>|null
     */
    private function notations(string $command, array $values, array $current): ?array
    {
        if ('Z' === $command) {
            return [new PathSegment('z', [])];
        }

        $absolute = [];
        $relative = [];

        foreach (str_split(self::ROLES[$command]) as $index => $role) {
            $absolute[] = $values[$index]->toText();
            $moved = 'n' === $role ? $values[$index] : $values[$index]->minus('x' === $role ? $current[0] : $current[1]);

            if (null === $moved) {
                return null;
            }

            $relative[] = $moved->toText();
        }

        return [new PathSegment($command, $absolute), new PathSegment(strtolower($command), $relative)];
    }

    /**
     * @param list<PathSegment> $read
     * @param list<PathSegment> $written
     */
    private function samePath(array $read, array $written): bool
    {
        if (\count($read) !== \count($written)) {
            return false;
        }

        $readCurrent = $writtenCurrent = [ExactDecimal::zero(), ExactDecimal::zero()];
        $readStart = $writtenStart = $readCurrent;

        foreach ($read as $index => $segment) {
            $before = $this->absolute($segment, $readCurrent, $readStart);
            $after = $this->absolute($written[$index], $writtenCurrent, $writtenStart);

            if (null === $before || null === $after || $before[0] !== $after[0] || \count($before[1]) !== \count($after[1])) {
                return false;
            }

            foreach ($before[1] as $position => $value) {
                if (!$value->equals($after[1][$position])) {
                    return false;
                }
            }

            if ('M' === $before[0]) {
                $readStart = $before[2];
                $writtenStart = $after[2];
            }

            $readCurrent = $before[2];
            $writtenCurrent = $after[2];
        }

        return true;
    }
}
