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
 * Reads the data of a path (`d`) or a point list (`points`) into its
 * numbers as written, and writes segments back in the shortest notation
 * the SVG grammar allows: the letter of a repeated command left out,
 * no separator where a sign or a second decimal point ends a number, and
 * the arc flags glued. Null as soon as anything is not a plain number or a
 * command is unknown, and for data holding more numbers than the ceiling.
 */
final readonly class PathData
{
    public const string NUMBER = '[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?';

    /**
     * The most numbers a path data or point list may hold, a close counted
     * as one, counted before it is read: past it, the data counts as
     * unreadable and stays as written. A segment holds one of them at least,
     * so the ceiling bounds the segments too. At the ceiling, a level peaks
     * at some 50 MB of memory and the base at 30 MB; the longest real path
     * measured holds 37,400 numbers (8,100 segments).
     */
    public const int MAXIMUM_NUMBERS = 40_000;

    private const array ARITY = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'A' => 7, 'Z' => 0];

    private const array ARC_FLAG_POSITIONS = [3, 4];

    /**
     * @return list<PathSegment>|null
     */
    public function segments(string $data): ?array
    {
        if ($this->exceedsCeiling($data)) {
            return null;
        }

        $segments = [];
        $command = '';
        $arguments = [];
        $awaitingArguments = false;
        $length = \strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $character = $data[$offset];

            if (ctype_space($character) || ',' === $character) {
                ++$offset;

                continue;
            }

            if (ctype_alpha($character)) {
                $upper = strtoupper($character);

                if ($awaitingArguments || [] !== $arguments || !isset(self::ARITY[$upper]) || ([] === $segments && 'M' !== $upper)) {
                    return null;
                }

                ++$offset;
                $command = $character;

                if ('Z' === $upper) {
                    $segments[] = new PathSegment($character, []);

                    continue;
                }

                $awaitingArguments = true;

                continue;
            }

            if ('' === $command || 'Z' === strtoupper($command)) {
                return null;
            }

            $argument = $this->argument($data, $offset, 'A' === strtoupper($command) && \in_array(\count($arguments), self::ARC_FLAG_POSITIONS, true));

            if (null === $argument) {
                return null;
            }

            $arguments[] = $argument;

            if (\count($arguments) === self::ARITY[strtoupper($command)]) {
                $segments[] = new PathSegment($command, $arguments);
                $arguments = [];
                $awaitingArguments = false;
                $command = match ($command) {
                    'M' => 'L',
                    'm' => 'l',
                    default => $command,
                };
            }
        }

        return $awaitingArguments || [] !== $arguments || [] === $segments ? null : $segments;
    }

    /**
     * @return list<array{string, string}>|null
     */
    public function pointList(string $list): ?array
    {
        if ($this->exceedsCeiling($list)) {
            return null;
        }

        $numbers = preg_split('/[\s,]+/', trim($list), -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $numbers || [] === $numbers || 1 === \count($numbers) % 2) {
            return null;
        }

        $points = [];

        foreach (array_chunk($numbers, 2) as [$x, $y]) {
            if (!$this->isNumber($x) || !$this->isNumber($y)) {
                return null;
            }

            $points[] = [$x, $y];
        }

        return $points;
    }

    /**
     * Whether the data holds more numbers and closes than the ceiling; they
     * are counted without being kept.
     */
    public function exceedsCeiling(string $data): bool
    {
        $count = preg_match_all('/'.self::NUMBER.'|[zZ]/', $data);

        return false === $count || $count > self::MAXIMUM_NUMBERS;
    }

    public function isNumber(string $value): bool
    {
        return 1 === preg_match('/^'.self::NUMBER.'$/', $value);
    }

    /**
     * @param list<PathSegment> $segments
     */
    public function write(array $segments): string
    {
        $data = '';
        $previousCommand = '';
        $previousArgument = '';

        foreach ($segments as $segment) {
            $data .= $this->fragment($segment, $previousCommand, $previousArgument);
            $previousCommand = $segment->command;
            $previousArgument = [] === $segment->arguments ? '' : $segment->arguments[array_key_last($segment->arguments)];
        }

        return $data;
    }

    /**
     * What a segment adds to the data written so far, which ended on
     * the given command and number ('' after a letter or at the start).
     */
    public function fragment(PathSegment $segment, string $previousCommand, string $previousArgument): string
    {
        $text = '';

        if ($this->needsLetter($segment->command, $previousCommand)) {
            $text = $segment->command;
            $previousArgument = '';
        }

        $arc = 'A' === strtoupper($segment->command);

        foreach ($segment->arguments as $index => $argument) {
            // After an arc flag, nothing: a flag is read as one character.
            $text .= ($arc && \in_array($index - 1, self::ARC_FLAG_POSITIONS, true) ? '' : $this->separator($previousArgument, $argument)).$argument;
            $previousArgument = $argument;
        }

        return $text;
    }

    private function needsLetter(string $command, string $previousCommand): bool
    {
        if ('Z' === strtoupper($command)) {
            return true;
        }

        $implicit = match ($previousCommand) {
            'M' => 'L',
            'm' => 'l',
            default => $previousCommand,
        };

        return $command !== $implicit;
    }

    private function separator(string $previous, string $number): string
    {
        if ('' === $previous || str_starts_with($number, '-') || str_starts_with($number, '+')) {
            return '';
        }

        return str_starts_with($number, '.') && (str_contains($previous, '.') || str_contains(strtolower($previous), 'e')) ? '' : ' ';
    }

    private function argument(string $data, int &$offset, bool $arcFlag): ?string
    {
        if ($arcFlag && ('0' === $data[$offset] || '1' === $data[$offset])) {
            return $data[$offset++];
        }

        if (1 !== preg_match('/\G'.self::NUMBER.'/', $data, $match, 0, $offset)) {
            return null;
        }

        $offset += \strlen($match[0]);

        if (!$arcFlag) {
            return $match[0];
        }

        // A flag written as a number ("1.0") reads as the flag it equals.
        return match ((float) $match[0]) {
            0.0 => '0',
            1.0 => '1',
            default => null,
        };
    }
}
