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

/**
 * Points that enclose a shape, never fewer than what it draws: the control
 * points of a curve (a Bezier stays inside them) and, around the ends of
 * an arc, a margin of twice its radius. Null as soon as anything is not
 * a plain number or a command is unknown.
 */
final readonly class GeometryEnvelope
{
    private const string NUMBER = '[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?';

    /**
     * The longest path data or point list read. Every point is kept to find
     * the envelope, some 200 bytes each: past this length the shape counts as
     * unreadable, and a clip over it stays.
     */
    public const int MAXIMUM_DATA_BYTES = 100_000;

    public function number(string $value): ?float
    {
        $value = trim($value);

        return 1 === preg_match('/^'.self::NUMBER.'$/', $value) ? (float) $value : null;
    }

    /**
     * Points of a rect, circle, ellipse, line, polyline, polygon or path;
     * null for another element or an unreadable one. The stroke is not
     * counted.
     *
     * @return list<array{float, float}>|null
     */
    public function pointsOf(\DOMElement $element): ?array
    {
        if (\strlen($element->getAttribute('d')) > self::MAXIMUM_DATA_BYTES || \strlen($element->getAttribute('points')) > self::MAXIMUM_DATA_BYTES) {
            return null;
        }

        return match ($element->localName) {
            'path' => $this->pathPoints($element->getAttribute('d')),
            'rect' => $this->rectPoints($element),
            'circle' => $this->ellipsePoints($element, 'r', 'r'),
            'ellipse' => $this->ellipsePoints($element, 'rx', 'ry'),
            'line' => $this->linePoints($element),
            'polyline', 'polygon' => $this->listPoints($element->getAttribute('points')),
            default => null,
        };
    }

    /**
     * @return list<array{float, float}>|null
     */
    private function pathPoints(string $data): ?array
    {
        $tokens = $this->tokens($data);

        if (null === $tokens) {
            return null;
        }

        $points = [];
        $current = [0.0, 0.0];
        $start = [0.0, 0.0];
        $lastCubic = null;
        $lastQuadratic = null;
        $command = '';
        $position = 0;
        $count = \count($tokens);
        $first = true;

        while ($position < $count) {
            if (\is_string($tokens[$position])) {
                $command = $tokens[$position++];

                if ($first && 'M' !== $command && 'm' !== $command) {
                    return null;
                }
            } elseif ('' === $command || 'z' === strtolower($command)) {
                return null;
            }

            $first = false;
            $upper = strtoupper($command);

            if ('Z' === $upper) {
                $current = $start;
                $lastCubic = $lastQuadratic = null;

                continue;
            }

            $arity = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'A' => 7][$upper] ?? null;

            if (null === $arity) {
                return null;
            }

            $arguments = $this->arguments($tokens, $position, $arity, 'A' === $upper);

            if (null === $arguments) {
                return null;
            }

            $relative = $command === strtolower($command);
            [$x, $y] = $current;
            $originX = $relative ? $x : 0.0;
            $originY = $relative ? $y : 0.0;
            $newCubic = $newQuadratic = null;

            switch ($upper) {
                case 'M':
                case 'L':
                    $current = [$originX + $arguments[0], $originY + $arguments[1]];
                    $points[] = $current;
                    if ('M' === $upper) {
                        $start = $current;
                        $command = $relative ? 'l' : 'L';
                    }

                    break;
                case 'H':
                    $current = [$originX + $arguments[0], $y];
                    $points[] = $current;

                    break;
                case 'V':
                    $current = [$x, $originY + $arguments[0]];
                    $points[] = $current;

                    break;
                case 'C':
                    $points[] = [$originX + $arguments[0], $originY + $arguments[1]];
                    $newCubic = [$originX + $arguments[2], $originY + $arguments[3]];
                    $points[] = $newCubic;
                    $current = [$originX + $arguments[4], $originY + $arguments[5]];
                    $points[] = $current;

                    break;
                case 'S':
                    $points[] = null === $lastCubic ? $current : [2 * $x - $lastCubic[0], 2 * $y - $lastCubic[1]];
                    $newCubic = [$originX + $arguments[0], $originY + $arguments[1]];
                    $points[] = $newCubic;
                    $current = [$originX + $arguments[2], $originY + $arguments[3]];
                    $points[] = $current;

                    break;
                case 'Q':
                    $newQuadratic = [$originX + $arguments[0], $originY + $arguments[1]];
                    $points[] = $newQuadratic;
                    $current = [$originX + $arguments[2], $originY + $arguments[3]];
                    $points[] = $current;

                    break;
                case 'T':
                    $newQuadratic = null === $lastQuadratic ? $current : [2 * $x - $lastQuadratic[0], 2 * $y - $lastQuadratic[1]];
                    $points[] = $newQuadratic;
                    $current = [$originX + $arguments[0], $originY + $arguments[1]];
                    $points[] = $current;

                    break;
                case 'A':
                    $end = [$originX + $arguments[5], $originY + $arguments[6]];
                    $radius = $this->arcRadius($current, $end, $arguments[0], $arguments[1], $arguments[2]);
                    foreach ([$current, $end] as [$pointX, $pointY]) {
                        $points[] = [$pointX - 2 * $radius, $pointY - 2 * $radius];
                        $points[] = [$pointX + 2 * $radius, $pointY + 2 * $radius];
                    }
                    $current = $end;

                    break;
            }

            $lastCubic = $newCubic;
            $lastQuadratic = $newQuadratic;
        }

        return [] === $points ? null : $points;
    }

    /**
     * @return list<array{float, float}>|null
     */
    private function rectPoints(\DOMElement $element): ?array
    {
        $values = $this->attributes($element, ['x' => 0.0, 'y' => 0.0, 'width' => null, 'height' => null]);

        if (null === $values) {
            return null;
        }

        return [[$values['x'], $values['y']], [$values['x'] + $values['width'], $values['y'] + $values['height']]];
    }

    /**
     * @return list<array{float, float}>|null
     */
    private function ellipsePoints(\DOMElement $element, string $radiusX, string $radiusY): ?array
    {
        $values = $this->attributes($element, ['cx' => 0.0, 'cy' => 0.0, $radiusX => null, $radiusY => null]);

        if (null === $values) {
            return null;
        }

        return [
            [$values['cx'] - $values[$radiusX], $values['cy'] - $values[$radiusY]],
            [$values['cx'] + $values[$radiusX], $values['cy'] + $values[$radiusY]],
        ];
    }

    /**
     * @return list<array{float, float}>|null
     */
    private function linePoints(\DOMElement $element): ?array
    {
        $values = $this->attributes($element, ['x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 0.0]);

        if (null === $values) {
            return null;
        }

        return [[$values['x1'], $values['y1']], [$values['x2'], $values['y2']]];
    }

    /**
     * @return list<array{float, float}>|null
     */
    private function listPoints(string $list): ?array
    {
        $numbers = preg_split('/[\s,]+/', trim($list), -1, \PREG_SPLIT_NO_EMPTY);

        if (false === $numbers || [] === $numbers || 1 === \count($numbers) % 2) {
            return null;
        }

        $points = [];

        foreach (array_chunk($numbers, 2) as [$x, $y]) {
            $x = $this->number($x);
            $y = $this->number($y);

            if (null === $x || null === $y) {
                return null;
            }

            $points[] = [$x, $y];
        }

        return $points;
    }

    /**
     * @param array<string, float|null> $defaults null = required
     *
     * @return array<string, float>|null
     */
    private function attributes(\DOMElement $element, array $defaults): ?array
    {
        $values = [];

        foreach ($defaults as $name => $default) {
            if (!$element->hasAttribute($name)) {
                if (null === $default) {
                    return null;
                }

                $values[$name] = $default;

                continue;
            }

            $number = $this->number($element->getAttribute($name));

            if (null === $number) {
                return null;
            }

            $values[$name] = $number;
        }

        return $values;
    }

    /**
     * Radius of the arc as drawn: the SVG rules enlarge radii too small to
     * join the two ends.
     *
     * @param array{float, float} $from
     * @param array{float, float} $to
     */
    private function arcRadius(array $from, array $to, float $radiusX, float $radiusY, float $rotation): float
    {
        $radiusX = abs($radiusX);
        $radiusY = abs($radiusY);

        if (0.0 === $radiusX || 0.0 === $radiusY) {
            return 0.0;
        }

        $angle = deg2rad($rotation);
        $halfX = ($from[0] - $to[0]) / 2;
        $halfY = ($from[1] - $to[1]) / 2;
        $rotatedX = cos($angle) * $halfX + sin($angle) * $halfY;
        $rotatedY = -sin($angle) * $halfX + cos($angle) * $halfY;
        $ratio = ($rotatedX ** 2) / ($radiusX ** 2) + ($rotatedY ** 2) / ($radiusY ** 2);
        $scale = $ratio > 1 ? sqrt($ratio) : 1.0;

        return max($radiusX, $radiusY) * $scale;
    }

    /**
     * Numbers, or the letter of a command.
     *
     * @return list<float|string>|null
     */
    private function tokens(string $data): ?array
    {
        $tokens = [];
        $length = \strlen($data);
        $offset = 0;
        $arguments = 0;
        $command = '';

        while ($offset < $length) {
            $character = $data[$offset];

            if (ctype_space($character) || ',' === $character) {
                ++$offset;

                continue;
            }

            if (ctype_alpha($character)) {
                $command = $character;
                $tokens[] = $character;
                $arguments = 0;
                ++$offset;

                continue;
            }

            // An arc flag is one character, glued to what follows.
            if (\in_array(strtoupper($command), ['A'], true) && \in_array($arguments % 7, [3, 4], true) && ('0' === $character || '1' === $character)) {
                $tokens[] = (float) $character;
                ++$arguments;
                ++$offset;

                continue;
            }

            if (1 !== preg_match('/\G'.self::NUMBER.'/', $data, $match, 0, $offset)) {
                return null;
            }

            $tokens[] = (float) $match[0];
            ++$arguments;
            $offset += \strlen($match[0]);
        }

        return $tokens;
    }

    /**
     * @param list<float|string> $tokens
     *
     * @return list<float>|null
     */
    private function arguments(array $tokens, int &$position, int $arity, bool $arc): ?array
    {
        $arguments = [];

        for ($index = 0; $index < $arity; ++$index) {
            $token = $tokens[$position + $index] ?? null;

            if (!\is_float($token)) {
                return null;
            }

            $arguments[] = $token;
        }

        if ($arc && (!\in_array($arguments[3], [0.0, 1.0], true) || !\in_array($arguments[4], [0.0, 1.0], true))) {
            return null;
        }

        $position += $arity;

        return $arguments;
    }
}
