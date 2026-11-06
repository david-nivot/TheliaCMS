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
 * The declarations of a `style` attribute or of a `<style>` element, read and
 * rewritten in place: a value is replaced where it stands, and the rest of the
 * text (comments, quoted strings, `url()`) stays byte for byte.
 *
 * Reading goes through a blanked copy of the text, of the same length, where
 * comments, strings and `url()` bodies are spaces: a `;`, `:` or `{` inside
 * them splits nothing.
 */
final class SvgCss
{
    /**
     * @return list<array{selectors: string, start: int, length: int}> the rules, the content of their braces placed in the text
     */
    public static function blocks(string $text): array
    {
        preg_match_all('/([^{}]*)\{([^{}]*)\}/', self::blanked($text), $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $match): array => ['selectors' => trim($match[1][0]), 'start' => $match[2][1], 'length' => \strlen($match[2][0])],
            $matches,
        );
    }

    /**
     * The declarations of a list, by property; the last one of a property wins.
     *
     * @return array<string, array{value: string, start: int, length: int}> each value placed in the text, spaces around it left out
     */
    public static function declarations(string $text): array
    {
        preg_match_all('/(?<=^|[;{])\s*([a-zA-Z-]+)\s*:([^;]*)/', self::blanked($text), $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $declarations = [];

        foreach ($matches as $match) {
            $raw = substr($text, $match[2][1], \strlen($match[2][0]));
            $start = $match[2][1] + (\strlen($raw) - \strlen(ltrim($raw)));
            $value = trim($raw);
            $declarations[strtolower($match[1][0])] = ['value' => $value, 'start' => $start, 'length' => \strlen($value)];
        }

        return $declarations;
    }

    /**
     * @return array<string, string>
     */
    public static function values(string $text): array
    {
        return array_map(static fn (array $declaration): string => $declaration['value'], self::declarations($text));
    }

    /**
     * Rewrites the paints of a list of declarations. For each paint, `$target`
     * says what its value becomes, null to leave it; the opacity that goes
     * with it is lowered where it is written, or added after the paint.
     *
     * @param callable(string): ?array{string, float} $target         a paint value to its new value and its opacity
     * @param array<string, string>                   $outsideOpacity the opacities set outside the list (attributes), lowered rather than replaced
     */
    public static function rewrite(string $text, callable $target, array $outsideOpacity = []): string
    {
        $declarations = self::declarations($text);
        $edits = [];

        foreach (SvgPaints::PROPERTIES as $property => $opacityProperty) {
            if (!isset($declarations[$property])) {
                continue;
            }

            $declaration = $declarations[$property];
            $important = 1 === preg_match('/!important$/i', $declaration['value']);
            $found = $target(SvgPaints::withoutImportance($declaration['value']));

            if (null === $found) {
                continue;
            }

            [$value, $opacity] = $found;
            $edits[] = [$declaration['start'], $declaration['length'], $value.($important ? ' !important' : '')];

            if (null === $opacityProperty || $opacity >= 1.0) {
                continue;
            }

            $edits[] = isset($declarations[$opacityProperty])
                ? [$declarations[$opacityProperty]['start'], $declarations[$opacityProperty]['length'], self::lowered($declarations[$opacityProperty]['value'], $opacity)]
                : [$declaration['start'] + $declaration['length'], 0, ';'.$opacityProperty.':'.self::lowered($outsideOpacity[$opacityProperty] ?? null, $opacity)];
        }

        // From the end, so that each place still points where it did.
        usort($edits, static fn (array $one, array $other): int => $other[0] <=> $one[0]);

        foreach ($edits as [$start, $length, $replacement]) {
            $text = substr_replace($text, $replacement, $start, $length);
        }

        return $text;
    }

    /** An opacity already written is lowered by the factor rather than replaced. */
    public static function lowered(?string $current, float $factor): string
    {
        $value = SvgPaints::withoutImportance($current ?? '');
        $percent = str_ends_with($value, '%');
        $number = $percent ? substr($value, 0, -1) : $value;
        $base = is_numeric($number) ? (float) $number / ($percent ? 100 : 1) : 1.0;

        return rtrim(rtrim(number_format(max(0.0, min(1.0, $base)) * $factor, 3, '.', ''), '0'), '.');
    }

    /** The text, its comments, quoted strings and `url()` bodies turned to spaces. */
    private static function blanked(string $text): string
    {
        return preg_replace_callback(
            '~/\*.*?(?:\*/|$)|"(?:[^"\\\\]|\\\\.)*(?:"|$)|\'(?:[^\'\\\\]|\\\\.)*(?:\'|$)|url\(\s*[^)"\']*\)~is',
            static fn (array $match): string => str_repeat(' ', \strlen($match[0])),
            $text,
        ) ?? $text;
    }
}
