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

namespace TheliaCMS\Media\Svg\Color;

/**
 * A color as written in an SVG, brought to one key so that `#FFF`, `white`
 * and `rgb(255, 255, 255)` count as the same color.
 *
 * The key is `#rrggbb` (`#rrggbbaa` with a transparency), or the name itself
 * for a named color this table does not know: it still groups and is still
 * replaced, it is only not merged with its hexadecimal spelling.
 */
final class SvgColor
{
    public const string WHITE = '#ffffff';

    public const string BLACK = '#000000';

    /** The opacity a key carries: 1 for an opaque color, its alpha for `#rrggbbaa`. */
    public static function alpha(string $key): float
    {
        return 9 === \strlen($key) && str_starts_with($key, '#') ? hexdec(substr($key, 7, 2)) / 255 : 1.0;
    }

    /** What a key looks like, in an address as well. */
    public const string KEY_PATTERN = '/^(#[0-9a-f]{6}([0-9a-f]{2})?|[a-z]{3,20})\z/';

    private const array NAMED = [
        'black' => '#000000',
        'white' => '#ffffff',
        'silver' => '#c0c0c0',
        'gray' => '#808080',
        'grey' => '#808080',
        'darkgray' => '#a9a9a9',
        'darkgrey' => '#a9a9a9',
        'lightgray' => '#d3d3d3',
        'lightgrey' => '#d3d3d3',
        'dimgray' => '#696969',
        'dimgrey' => '#696969',
        'maroon' => '#800000',
        'red' => '#ff0000',
        'purple' => '#800080',
        'fuchsia' => '#ff00ff',
        'magenta' => '#ff00ff',
        'green' => '#008000',
        'lime' => '#00ff00',
        'olive' => '#808000',
        'yellow' => '#ffff00',
        'navy' => '#000080',
        'blue' => '#0000ff',
        'teal' => '#008080',
        'aqua' => '#00ffff',
        'cyan' => '#00ffff',
        'orange' => '#ffa500',
    ];

    /** Words that are no color: no paint, an inherited one, or a paint server. */
    private const array NOT_A_COLOR = ['none', 'transparent', 'inherit', 'initial', 'unset', 'currentcolor', 'context-fill', 'context-stroke'];

    /**
     * The key of a paint, or null when the paint is no plain color: `none`,
     * `currentColor`, a gradient `url(#…)`, or a value this reader cannot read.
     */
    public static function key(string $value): ?string
    {
        $value = strtolower(trim($value));

        if ('' === $value || \in_array($value, self::NOT_A_COLOR, true) || str_starts_with($value, 'url(')) {
            return null;
        }

        if (1 === preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $match)) {
            return self::hexadecimal($match[1]);
        }

        if (1 === preg_match('/^(rgba?|hsla?)\((.+)\)$/', $value, $match)) {
            return self::functional($match[1], $match[2]);
        }

        if (isset(self::NAMED[$value])) {
            return self::NAMED[$value];
        }

        return 1 === preg_match('/^[a-z]{3,20}$/', $value) ? $value : null;
    }

    private static function hexadecimal(string $digits): string
    {
        if (\strlen($digits) <= 4) {
            $digits = implode('', array_map(static fn (string $digit): string => $digit.$digit, str_split($digits)));
        }

        // An opaque alpha says nothing: the color is the one without it.
        return '#'.(8 === \strlen($digits) && str_ends_with($digits, 'ff') ? substr($digits, 0, 6) : $digits);
    }

    private static function functional(string $function, string $arguments): ?string
    {
        $parts = preg_split('/[\s,\/]+/', trim($arguments), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        if (\count($parts) < 3 || \count($parts) > 4) {
            return null;
        }

        $channels = str_starts_with($function, 'rgb')
            ? array_map(static fn (string $part): ?float => self::channel($part, 255.0), \array_slice($parts, 0, 3))
            : self::fromHsl($parts[0], $parts[1], $parts[2]);

        if (null === $channels || \in_array(null, $channels, true)) {
            return null;
        }

        $alpha = isset($parts[3]) ? self::channel($parts[3], 1.0) : 1.0;

        if (null === $alpha) {
            return null;
        }

        $hexadecimal = implode('', array_map(static fn (float $channel): string => \sprintf('%02x', (int) round(max(0.0, min(255.0, $channel)))), $channels));

        return self::hexadecimal($hexadecimal.\sprintf('%02x', (int) round(max(0.0, min(1.0, $alpha)) * 255)));
    }

    /** A number, or a percentage of `$full`. */
    private static function channel(string $part, float $full): ?float
    {
        if (str_ends_with($part, '%')) {
            $part = substr($part, 0, -1);

            return is_numeric($part) ? (float) $part * $full / 100 : null;
        }

        return is_numeric($part) ? (float) $part : null;
    }

    /**
     * @return list<float>|null
     */
    private static function fromHsl(string $hue, string $saturation, string $lightness): ?array
    {
        $hue = preg_replace('/deg$/', '', $hue) ?? $hue;

        if (!is_numeric($hue) || !str_ends_with($saturation, '%') || !str_ends_with($lightness, '%')) {
            return null;
        }

        $saturationValue = self::channel($saturation, 1.0);
        $lightnessValue = self::channel($lightness, 1.0);

        if (null === $saturationValue || null === $lightnessValue) {
            return null;
        }

        $hueValue = fmod(fmod((float) $hue, 360.0) + 360.0, 360.0) / 360.0;
        $q = $lightnessValue < 0.5 ? $lightnessValue * (1 + $saturationValue) : $lightnessValue + $saturationValue - $lightnessValue * $saturationValue;
        $p = 2 * $lightnessValue - $q;

        return array_map(
            static fn (float $offset): float => 255 * self::hueToChannel($p, $q, $hueValue + $offset),
            [1 / 3, 0.0, -1 / 3],
        );
    }

    private static function hueToChannel(float $p, float $q, float $hue): float
    {
        $hue = $hue < 0 ? $hue + 1 : ($hue > 1 ? $hue - 1 : $hue);

        return match (true) {
            $hue < 1 / 6 => $p + ($q - $p) * 6 * $hue,
            $hue < 1 / 2 => $q,
            $hue < 2 / 3 => $p + ($q - $p) * (2 / 3 - $hue) * 6,
            default => $p,
        };
    }
}
