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

namespace TheliaCMS\Content\Pipeline;

/**
 * Keeps of a stylesheet, or of a `style` attribute, what is known not to load
 * anything nor run anything, and drops the rest.
 *
 * A list of what is forbidden always missed a spelling: `image-set("…")`
 * fetches without `url(`, an escape spells `url(` in a way no pattern reads, a
 * URL with a scheme and no slashes still leaves the site. The stylesheet is
 * therefore read as CSS reads it, in tokens, and every function a value calls
 * must be one of a list of functions that draw: colours, arithmetic, custom
 * properties, gradients, transforms, filters, easing, grid and counters.
 * `url()` is kept for a path of this site and a raster picture written in a
 * data URL. Nothing written is rewritten: a declaration that does not pass is
 * dropped whole, and the rest is kept as written, comments included.
 *
 * A backslash is never kept: an escape is how a name hides from a reader, and
 * no style a page needs is written with one.
 */
final readonly class CssAllowList
{
    /**
     * The functions a value may call, in lowercase.
     */
    private const array FUNCTIONS = [
        // colours
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'lab', 'lch', 'oklab', 'oklch', 'color', 'color-mix', 'light-dark',
        // arithmetic and custom properties
        'calc', 'min', 'max', 'clamp', 'round', 'mod', 'rem', 'abs', 'sign',
        'sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'atan2', 'pow', 'sqrt', 'hypot', 'log', 'exp', 'var',
        // gradients
        'linear-gradient', 'radial-gradient', 'conic-gradient',
        'repeating-linear-gradient', 'repeating-radial-gradient', 'repeating-conic-gradient',
        // transforms
        'translate', 'translatex', 'translatey', 'translatez', 'translate3d',
        'rotate', 'rotatex', 'rotatey', 'rotatez', 'rotate3d',
        'scale', 'scalex', 'scaley', 'scalez', 'scale3d',
        'skew', 'skewx', 'skewy', 'matrix', 'matrix3d', 'perspective',
        // filters
        'blur', 'brightness', 'contrast', 'drop-shadow', 'grayscale', 'hue-rotate', 'invert', 'opacity', 'saturate', 'sepia',
        // easing
        'cubic-bezier', 'steps', 'linear',
        // grid, counters and shapes
        'repeat', 'minmax', 'fit-content', 'counter', 'counters', 'inset', 'circle', 'ellipse', 'polygon', 'rect', 'xywh',
    ];

    /**
     * Properties that bind script to an element, whatever their value.
     */
    private const array PROPERTIES = ['behavior', '-ms-behavior', '-moz-binding'];

    /**
     * At-rules kept, with the rules inside them. Every other one (`@import`,
     * `@font-face`, `@namespace`…) is dropped with its block.
     */
    private const array AT_RULES = ['media', 'supports', 'container', 'layer', 'keyframes', '-webkit-keyframes'];

    /**
     * What a URL may point at: a path of this site, or a raster picture
     * written in the stylesheet itself. No SVG: one carries script.
     */
    private const string URL = '#^(?:/(?![/\\\\])|data:image/(?:png|jpe?g|gif|webp|avif)[;,])#i';

    /**
     * What a piece of CSS may never hold: an escape, a `<` (which would close
     * the `style` element it is printed in), control characters, and a byte
     * order mark past the start, which no reader shows.
     */
    private const string UNSAFE = '/[\\\\<\x00-\x08\x0B\x0E-\x1F\x7F]|\xEF\xBB\xBF/';

    public function stylesheet(string $css): string
    {
        return $this->rules(self::withoutByteOrderMark($css), true);
    }

    /**
     * The declarations of a `style` attribute: no rule may open in one.
     */
    public function declarations(string $css): string
    {
        return $this->block(self::withoutByteOrderMark($css), false);
    }

    private static function withoutByteOrderMark(string $css): string
    {
        return str_starts_with($css, "\u{FEFF}") ? substr($css, 3) : $css;
    }

    /**
     * A list of rules: a stylesheet, or what a kept at-rule holds.
     */
    private function rules(string $css, bool $nesting): string
    {
        $kept = '';
        $length = \strlen($css);
        $position = 0;

        while ($position < $length) {
            $position = $this->copyBlank($css, $position, $kept);

            if ($position >= $length) {
                break;
            }

            $end = self::itemEnd($css, $position);

            if ($end >= $length || '{' !== $css[$end]) {
                // A statement: only `@layer a, b;` is kept.
                $statement = substr($css, $position, $end - $position);

                if ('layer' === self::atRuleName($statement) && !self::unsafe($statement) && !self::opensWhatItLeaves($statement) && $end < $length && ';' === $css[$end]) {
                    $kept .= $statement.';';
                }

                $position = $end + 1;

                continue;
            }

            $blockEnd = self::blockEnd($css, $end);
            $kept .= $this->rule(substr($css, $position, $end - $position), substr($css, $end + 1, $blockEnd - $end - 1), $nesting);
            $position = $blockEnd + 1;
        }

        return $kept;
    }

    /**
     * A rule or an at-rule with its block, or nothing when it does not pass.
     * A block left open is closed: stylesheets are printed one after the
     * other, and one must not hold the rules of the next.
     */
    private function rule(string $prelude, string $body, bool $nesting): string
    {
        if (self::unsafe($prelude) || self::opensWhatItLeaves($prelude)) {
            return '';
        }

        $atRule = self::atRuleName($prelude);

        if (null === $atRule) {
            return $prelude.'{'.$this->block($body, $nesting).'}';
        }

        if (!\in_array($atRule, self::AT_RULES, true)) {
            return '';
        }

        return $prelude.'{'.$this->rules($body, $nesting).'}';
    }

    /**
     * The declarations of a rule, and the rules nested in it when nesting is
     * allowed.
     */
    private function block(string $css, bool $nesting): string
    {
        $kept = '';
        $length = \strlen($css);
        $position = 0;

        while ($position < $length) {
            $position = $this->copyBlank($css, $position, $kept);

            if ($position >= $length) {
                break;
            }

            $end = self::itemEnd($css, $position);

            if ($end < $length && '{' === $css[$end]) {
                $blockEnd = self::blockEnd($css, $end);

                if ($nesting) {
                    $prelude = substr($css, $position, $end - $position);
                    $body = substr($css, $end + 1, $blockEnd - $end - 1);
                    $kept .= null === self::atRuleName($prelude)
                        ? $this->rule($prelude, $body, true)
                        : $this->nestedAtRule($prelude, $body);
                }

                $position = $blockEnd + 1;

                continue;
            }

            $declaration = substr($css, $position, $end - $position);

            if ($end < $length && '}' !== $css[$end] && $this->allowed($declaration)) {
                $kept .= $declaration.';';
            } elseif ($end >= $length && $this->allowed($declaration)) {
                $kept .= $declaration;
            }

            $position = $end + 1;
        }

        return $kept;
    }

    /**
     * An at-rule inside a rule holds declarations, as the rule does.
     */
    private function nestedAtRule(string $prelude, string $body): string
    {
        if (self::unsafe($prelude) || self::opensWhatItLeaves($prelude) || !\in_array(self::atRuleName($prelude), self::AT_RULES, true)) {
            return '';
        }

        return $prelude.'{'.$this->block($body, true).'}';
    }

    private function allowed(string $declaration): bool
    {
        if (self::unsafe($declaration)) {
            return false;
        }

        $colon = strpos($declaration, ':');

        if (false === $colon) {
            return false;
        }

        $property = trim(substr($declaration, 0, $colon));

        if (1 !== preg_match('/^(?:--[A-Za-z0-9_-]+|-?[A-Za-z][A-Za-z0-9-]*)$/', $property)
            || \in_array(strtolower($property), self::PROPERTIES, true)) {
            return false;
        }

        return $this->value(substr($declaration, $colon + 1));
    }

    /**
     * Whether every function the value calls is on the list, every URL it
     * names stays on this site, and everything it opens is closed: a browser
     * carries an open parenthesis, string or comment on into the rules that
     * follow.
     */
    private function value(string $value): bool
    {
        $length = \strlen($value);
        $position = 0;
        $depth = 0;

        while ($position < $length) {
            $character = $value[$position];

            if ('"' === $character || "'" === $character) {
                $position = self::stringEnd($value, $position);

                if (-1 === $position) {
                    return false;
                }

                continue;
            }

            if ('/' === $character && '*' === ($value[$position + 1] ?? '')) {
                if (false === strpos($value, '*/', $position + 2)) {
                    return false;
                }

                $position = self::commentEnd($value, $position);

                continue;
            }

            if ('(' === $character || '[' === $character) {
                ++$depth;
                ++$position;

                continue;
            }

            if (')' === $character || ']' === $character) {
                if (0 === $depth--) {
                    return false;
                }

                ++$position;

                continue;
            }

            if (1 !== preg_match('/\G-*[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF-]*/', $value, $match, 0, $position)) {
                ++$position;

                continue;
            }

            $position += \strlen($match[0]);

            if ('(' !== ($value[$position] ?? '')) {
                continue;
            }

            $function = strtolower($match[0]);

            if ('url' === $function) {
                $position = self::urlEnd($value, $position + 1);

                if (-1 === $position) {
                    return false;
                }

                continue;
            }

            if (!\in_array($function, self::FUNCTIONS, true)) {
                return false;
            }

            ++$depth;
            ++$position;
        }

        return 0 === $depth;
    }

    /**
     * Where the argument of `url(` ends, past its `)`; -1 when what it points
     * at is not allowed or the call is not closed.
     */
    private static function urlEnd(string $value, int $position): int
    {
        $length = \strlen($value);
        $position += strspn($value, " \t\n\r\f", $position);

        if ($position < $length && ('"' === $value[$position] || "'" === $value[$position])) {
            $end = self::stringEnd($value, $position);

            if (-1 === $end) {
                return -1;
            }

            $target = substr($value, $position + 1, $end - $position - 2);
            $position = $end;
        } else {
            $span = strcspn($value, " \t\n\r\f)\"'(", $position);
            $target = substr($value, $position, $span);
            $position += $span;
        }

        $position += strspn($value, " \t\n\r\f", $position);

        if (')' !== ($value[$position] ?? '') || 1 !== preg_match(self::URL, $target)) {
            return -1;
        }

        return $position + 1;
    }

    private static function unsafe(string $css): bool
    {
        return 1 === preg_match(self::UNSAFE, $css);
    }

    /**
     * Whether the prelude of a rule leaves a string, a parenthesis or a
     * bracket open: a browser would carry it on into the block, and read the
     * rules that follow otherwise than this list did.
     */
    private static function opensWhatItLeaves(string $css): bool
    {
        $length = \strlen($css);
        $closing = [];

        for ($position = 0; $position < $length; ++$position) {
            $character = $css[$position];

            if ('"' === $character || "'" === $character) {
                $end = self::stringEnd($css, $position);

                if (-1 === $end) {
                    return true;
                }

                $position = $end - 1;
            } elseif ('(' === $character) {
                $closing[] = ')';
            } elseif ('[' === $character) {
                $closing[] = ']';
            } elseif ((')' === $character || ']' === $character) && array_pop($closing) !== $character) {
                return true;
            }
        }

        return [] !== $closing;
    }

    /**
     * The name of the at-rule the text opens, in lowercase; null for a rule.
     */
    private static function atRuleName(string $prelude): ?string
    {
        return 1 === preg_match('/^\s*@([A-Za-z-]+)/', $prelude, $match) ? strtolower($match[1]) : null;
    }

    /**
     * Copies the blanks and comments that start at the position, and returns
     * where they end. A comment that holds a `<`, or is never closed, is
     * left out.
     */
    private function copyBlank(string $css, int $position, string &$kept): int
    {
        $length = \strlen($css);

        while ($position < $length) {
            $blank = strspn($css, " \t\n\r\f", $position);

            if ($blank > 0) {
                $kept .= substr($css, $position, $blank);
                $position += $blank;

                continue;
            }

            if ('/' === $css[$position] && '*' === ($css[$position + 1] ?? '')) {
                $end = self::commentEnd($css, $position);
                $comment = substr($css, $position, $end - $position);

                if (!str_contains($comment, '<') && str_ends_with($comment, '*/') && \strlen($comment) >= 4) {
                    $kept .= $comment;
                }

                $position = $end;

                continue;
            }

            // A stray separator is dropped.
            if (';' === $css[$position] || '}' === $css[$position]) {
                ++$position;

                continue;
            }

            break;
        }

        return $position;
    }

    /**
     * Where the item that starts at the position ends: the first `{`, `}` or
     * `;` outside a string, a comment and parentheses, or the end of the text.
     */
    private static function itemEnd(string $css, int $position): int
    {
        $length = \strlen($css);
        $depth = 0;

        while ($position < $length) {
            $character = $css[$position];

            if ('\\' === $character) {
                $position += 2;

                continue;
            }

            if ('"' === $character || "'" === $character) {
                $end = self::stringEnd($css, $position);
                $position = -1 === $end ? self::lineEnd($css, $position) : $end;

                continue;
            }

            if ('/' === $character && '*' === ($css[$position + 1] ?? '')) {
                $position = self::commentEnd($css, $position);

                continue;
            }

            if ('{' === $character || '}' === $character || (';' === $character && 0 === $depth)) {
                return $position;
            }

            if ('(' === $character || '[' === $character) {
                ++$depth;
            } elseif ((')' === $character || ']' === $character) && $depth > 0) {
                --$depth;
            }

            ++$position;
        }

        return $length;
    }

    /**
     * The position of the `}` that closes the block opened at the position,
     * or the end of the text when it is never closed.
     */
    private static function blockEnd(string $css, int $open): int
    {
        $length = \strlen($css);
        $depth = 0;
        $position = $open;

        while ($position < $length) {
            $character = $css[$position];

            if ('\\' === $character) {
                $position += 2;

                continue;
            }

            if ('"' === $character || "'" === $character) {
                $end = self::stringEnd($css, $position);
                $position = -1 === $end ? self::lineEnd($css, $position) : $end;

                continue;
            }

            if ('/' === $character && '*' === ($css[$position + 1] ?? '')) {
                $position = self::commentEnd($css, $position);

                continue;
            }

            if ('{' === $character) {
                ++$depth;
            } elseif ('}' === $character && 0 === --$depth) {
                return $position;
            }

            ++$position;
        }

        return $length;
    }

    /**
     * Where the string opened at the position ends, past its closing quote;
     * -1 when a line or the text ends first, which CSS reads as a bad string.
     */
    private static function stringEnd(string $css, int $open): int
    {
        $quote = $css[$open];
        $length = \strlen($css);

        for ($position = $open + 1; $position < $length; ++$position) {
            $character = $css[$position];

            if ('\\' === $character) {
                ++$position;
            } elseif ($character === $quote) {
                return $position + 1;
            } elseif ("\n" === $character || "\r" === $character || "\f" === $character) {
                return -1;
            }
        }

        return -1;
    }

    private static function lineEnd(string $css, int $position): int
    {
        $span = strcspn($css, "\n\r\f", $position);

        return $position + max(1, $span);
    }

    private static function commentEnd(string $css, int $open): int
    {
        $close = strpos($css, '*/', $open + 2);

        return false === $close ? \strlen($css) : $close + 2;
    }
}
