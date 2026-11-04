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

namespace TheliaCMS\Content;

/**
 * The width an image is drawn at, as a section declares it.
 *
 * A section knows its layout; the publication, which writes `sizes` on the
 * images, only sees the final markup. The section says it on the element that
 * holds its images, with `data-cms-sizes` set to the `sizes` the browser
 * should read: `(max-width: 50rem) calc(100vw - 2rem), 48rem`. The rewriter
 * takes the value from the nearest element that carries one, and removes the
 * attribute from what it publishes. An image under no declaration keeps the
 * default: the viewport, up to the width of its file.
 *
 * The widths below follow `assets/blocks.css`: a change of the layout there is
 * a change here.
 */
final class ImageSlot
{
    public const string ATTRIBUTE = 'data-cms-sizes';

    /** The widest line of text of the content (`.cms-image`, `.cms-columns`), in rem. */
    private const int CONTENT_WIDTH = 75;

    /** The reading column (`.cms-image--narrow`), in rem. */
    private const int NARROW_WIDTH = 48;

    /** The space kept on each side of a block on a narrow screen, in rem (`100% - 2rem`). */
    private const int MARGIN = 2;

    /** The gap between two columns, in rem (`.cms-columns__grid`). */
    private const int COLUMN_GAP = 2;

    /** The width from which the columns sit side by side, in rem. */
    private const int COLUMNS_BREAKPOINT = 48;

    private const int MAX_LENGTH = 300;

    /**
     * Everything a `sizes` list is made of: lengths, `calc`, `min`, `max`,
     * `clamp`, and media conditions on the width.
     */
    private const array WORDS = ['and', 'calc', 'min', 'max', 'clamp', 'max-width', 'min-width', 'px', 'rem', 'em', 'vw', 'vh'];

    /**
     * A block as wide as the content up to `$maxRem`, a margin on each side
     * of it on a narrow screen.
     */
    public static function block(int $maxRem): string
    {
        return \sprintf('(max-width: %drem) calc(100vw - %drem), %drem', $maxRem + self::MARGIN, self::MARGIN, $maxRem);
    }

    public static function narrow(): string
    {
        return self::block(self::NARROW_WIDTH);
    }

    public static function content(): string
    {
        return self::block(self::CONTENT_WIDTH);
    }

    public static function screen(): string
    {
        return '100vw';
    }

    /**
     * The width of one of `$count` columns: the whole block while they are
     * stacked, then a share of it, less the gaps.
     */
    public static function column(int $count): string
    {
        $gaps = ($count - 1) * self::COLUMN_GAP;

        return \sprintf(
            '(min-width: %drem) %srem, (min-width: %drem) calc((100vw - %drem - %drem) / %d), calc(100vw - %drem)',
            self::CONTENT_WIDTH + self::MARGIN,
            rtrim(rtrim(number_format(ceil((self::CONTENT_WIDTH - $gaps) / $count * 100) / 100, 2, '.', ''), '0'), '.'),
            self::COLUMNS_BREAKPOINT,
            self::MARGIN,
            $gaps,
            $count,
            self::MARGIN,
        );
    }

    /**
     * Whether a value is a `sizes` list this module will write: the value of
     * an attribute is whatever an author typed, and only what a browser reads
     * as a width is passed on.
     */
    public static function isValid(string $sizes): bool
    {
        $sizes = strtolower(trim($sizes));

        if ('' === $sizes || \strlen($sizes) > self::MAX_LENGTH || 1 !== preg_match('/^[a-z0-9 .,:()+\-*\/]+$/', $sizes)) {
            return false;
        }

        preg_match_all('/[a-z][a-z-]*/', $sizes, $words);

        if ([] !== array_diff($words[0], self::WORDS)) {
            return false;
        }

        $depth = 0;

        foreach (str_split($sizes) as $character) {
            if ('(' === $character) {
                ++$depth;
            } elseif (')' === $character && --$depth < 0) {
                return false;
            }
        }

        return 0 === $depth;
    }

    /**
     * The attribute to write on the element a section draws its images in.
     */
    public static function attribute(string $sizes): string
    {
        return \sprintf(' %s="%s"', self::ATTRIBUTE, htmlspecialchars($sizes, \ENT_QUOTES | \ENT_HTML5));
    }
}
