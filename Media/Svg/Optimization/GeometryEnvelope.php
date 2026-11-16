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

use TheliaCMS\Media\Svg\Optimization\Path\PathData;

/**
 * Points that enclose a shape, never fewer than what it draws: the control
 * points of a curve (a Bezier stays inside them) and, around the ends of
 * an arc, a margin of twice its radius. Null as soon as anything is not
 * a plain number or a command is unknown.
 */
final readonly class GeometryEnvelope
{
    public function __construct(private PathData $paths = new PathData())
    {
    }

    /**
     * The longest path data or point list read. Every point is kept to find
     * the envelope, some 200 bytes each: past this length the shape counts as
     * unreadable, and a clip over it stays.
     */
    public const int MAXIMUM_DATA_BYTES = 100_000;

    public function number(string $value): ?float
    {
        $value = trim($value);

        return $this->paths->isNumber($value) ? (float) $value : null;
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
        $segments = $this->paths->segments($data);

        if (null === $segments) {
            return null;
        }

        $points = [];
        $current = [0.0, 0.0];
        $start = [0.0, 0.0];
        $lastCubic = null;
        $lastQuadratic = null;

        foreach ($segments as $segment) {
            $upper = strtoupper($segment->command);

            if ('Z' === $upper) {
                $current = $start;
                $lastCubic = $lastQuadratic = null;

                continue;
            }

            $arguments = array_map(floatval(...), $segment->arguments);
            $relative = $segment->isRelative();
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
        $points = $this->paths->pointList($list);

        return null === $points ? null : array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $points);
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
}
