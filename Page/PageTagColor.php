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

namespace TheliaCMS\Page;

/**
 * The colors of a tag: a background, free, and the color of its text, chosen
 * or left to the contrast. Both are `#rrggbb` codes, what `cms_tag.color` and
 * `cms_tag.text_color` store and what a theme reads.
 *
 * Up to 2.6, a tag took one of six names; a name stored then, or carried by a
 * site file written then, still reads as the code of the badge it gave.
 */
final readonly class PageTagColor
{
    /** The badge of a tag with no color, as every tag was shown before. */
    public const string NO_COLOR_BADGE = 'text-bg-secondary';

    /**
     * The names of the palette of 2.5 and 2.6, each with the background and
     * the text of the back office badge it gave (Bootstrap, Thelia orange).
     */
    public const array FORMER_PALETTE = [
        'red' => ['#dc3545', '#ffffff'],
        'orange' => ['#f26041', '#000000'],
        'yellow' => ['#ffc107', '#000000'],
        'green' => ['#198754', '#ffffff'],
        'cyan' => ['#0dcaf0', '#000000'],
        'dark' => ['#212529', '#ffffff'],
    ];

    private const string DARK_TEXT = '#000000';
    private const string LIGHT_TEXT = '#ffffff';

    /**
     * @param string      $background the `#rrggbb` code of the background
     * @param string|null $text       the `#rrggbb` code of the text, null to leave it to the contrast
     */
    private function __construct(
        public string $background,
        public ?string $text,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when a code is not one of a color
     */
    public static function of(string $background, ?string $text = null): self
    {
        $normalized = self::normalize($background);

        if (null === $normalized) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not the code of a color.', $background));
        }

        $normalizedText = null === $text ? null : self::normalize($text);

        if (null !== $text && null === $normalizedText) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not the code of a color.', $text));
        }

        return new self($normalized, $normalizedText);
    }

    /**
     * What the table holds, or a site file carries: a code, or a name of the
     * former palette. Anything else, written by hand, reads as no color; a text
     * color that is not a code is left to the contrast.
     */
    public static function fromStored(?string $background, ?string $text = null): ?self
    {
        if (null === $background) {
            return null;
        }

        if (isset(self::FORMER_PALETTE[$background])) {
            [$formerBackground, $formerText] = self::FORMER_PALETTE[$background];

            return new self($formerBackground, null === $text ? $formerText : (self::normalize($text) ?? $formerText));
        }

        $normalized = self::normalize($background);

        return null === $normalized ? null : new self($normalized, null === $text ? null : self::normalize($text));
    }

    /**
     * The lowercase `#rrggbb` code of a `#rgb` or `#rrggbb` one, null for
     * anything else.
     */
    public static function normalize(string $code): ?string
    {
        $code = strtolower(trim($code));

        if (1 === preg_match('/^#[0-9a-f]{3}$/', $code)) {
            return '#'.$code[1].$code[1].$code[2].$code[2].$code[3].$code[3];
        }

        return 1 === preg_match('/^#[0-9a-f]{6}$/', $code) ? $code : null;
    }

    public static function badgeClassOf(?self $color): string
    {
        return null === $color ? self::NO_COLOR_BADGE : '';
    }

    public static function badgeStyleOf(?self $color): string
    {
        return $color?->badgeStyle() ?? '';
    }

    /**
     * The color the text is drawn in: the one chosen, or black or white,
     * whichever contrasts more with the background (WCAG 2 relative luminance).
     */
    public function textColor(): string
    {
        if (null !== $this->text) {
            return $this->text;
        }

        $luminance = self::luminance($this->background);

        // Contrast with black (L + 0.05) / 0.05 against white 1.05 / (L + 0.05).
        return ($luminance + 0.05) ** 2 >= 0.0525 ? self::DARK_TEXT : self::LIGHT_TEXT;
    }

    /**
     * The inline style of the badge: the codes are checked, so the style
     * carries nothing but them.
     */
    public function badgeStyle(): string
    {
        return \sprintf('background-color: %s; color: %s;', $this->background, $this->textColor());
    }

    public function equals(?self $other): bool
    {
        return null !== $other && $other->background === $this->background && $other->text === $this->text;
    }

    public function describe(): string
    {
        return null === $this->text ? $this->background : $this->background.' with text '.$this->text;
    }

    private static function luminance(string $code): float
    {
        $channels = array_map(
            static function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($code, 1), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
