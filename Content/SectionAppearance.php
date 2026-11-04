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

use TheliaCMS\Locale\TranslatableText;

/**
 * How a section stands out from the page, beyond what its component draws:
 * a background, a colour of text, more room around it, an alignment. A
 * closed list of settings, each value checked, so what reaches the page is
 * a style the module wrote and never one a brief or a model typed.
 *
 * Nothing set is the section as its component draws it.
 *
 * Room is said in two ways: a preset (`padding`: small, medium, large, above
 * and below together) or a length written like `64px` or `2rem`. A length is
 * checked against a strict pattern and a bound per unit, as it is the one
 * thing standing between a typed value and the style attribute. The inner
 * room (`padding_*`, the background goes with it) and the outer room
 * (`margin_*`, it keeps sections apart) have a top and a bottom of their own;
 * a side that is set wins over `padding` for that side.
 */
final readonly class SectionAppearance
{
    public const array PADDINGS = ['small' => '1.5rem', 'medium' => '3rem', 'large' => '5rem'];

    public const array ALIGNMENTS = ['left', 'center', 'right'];

    /** The largest length by unit, in that unit. */
    public const array MAX_LENGTHS = ['px' => 320, 'rem' => 20];

    /** The settings that take a length, and the declaration each writes. */
    public const array SIDES = [
        'padding_top' => 'padding-top',
        'padding_bottom' => 'padding-bottom',
        'margin_top' => 'margin-top',
        'margin_bottom' => 'margin-bottom',
    ];

    private const array SETTINGS = ['background', 'text', 'padding', 'align', 'padding_top', 'padding_bottom', 'margin_top', 'margin_bottom'];

    private const string COLOR_PATTERN = '/^#[0-9a-f]{6}$/';

    private const string LENGTH_PATTERN = '/^((?:0|[1-9]\d*)(?:\.\d{1,2})?)(px|rem)$/D';

    public function __construct(
        public ?string $background = null,
        public ?string $text = null,
        public ?string $padding = null,
        public ?string $align = null,
        public ?string $padding_top = null,
        public ?string $padding_bottom = null,
        public ?string $margin_top = null,
        public ?string $margin_bottom = null,
    ) {
    }

    /**
     * @param array<mixed> $values
     *
     * @throws InvalidDocumentException listing every value that is not one of the settings
     */
    public static function fromArray(array $values): self
    {
        $problems = [];

        foreach (array_keys($values) as $key) {
            if (!\in_array($key, self::SETTINGS, true)) {
                $problems[] = TranslatableText::of('The appearance has no setting "%s": only %s.', $key, implode(', ', self::SETTINGS));
            }
        }

        $background = self::color($values['background'] ?? null, 'background', $problems);
        $text = self::color($values['text'] ?? null, 'text', $problems);
        $padding = self::preset($values['padding'] ?? null, $problems);
        $align = self::oneOf($values['align'] ?? null, 'align', self::ALIGNMENTS, $problems);
        $sides = [];

        foreach (array_keys(self::SIDES) as $side) {
            $sides[$side] = self::length($values[$side] ?? null, $side, $problems);
        }

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return new self($background, $text, $padding, $align, $sides['padding_top'], $sides['padding_bottom'], $sides['margin_top'], $sides['margin_bottom']);
    }

    public function isEmpty(): bool
    {
        return [] === $this->toArray();
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'background' => $this->background,
            'text' => $this->text,
            'padding' => $this->padding,
            'align' => $this->align,
            'padding_top' => $this->padding_top,
            'padding_bottom' => $this->padding_bottom,
            'margin_top' => $this->margin_top,
            'margin_bottom' => $this->margin_bottom,
        ], static fn (?string $value): bool => null !== $value);
    }

    /**
     * The declarations of the style attribute, built from the checked
     * values only.
     */
    public function style(): string
    {
        $declarations = [];

        if (null !== $this->background) {
            $declarations[] = 'background-color:'.$this->background;
        }

        if (null !== $this->text) {
            $declarations[] = 'color:'.$this->text;
        }

        if (null !== $this->padding) {
            $declarations[] = 'padding-block:'.(self::PADDINGS[$this->padding] ?? $this->padding);
        }

        foreach (self::SIDES as $side => $declaration) {
            if (null !== $this->{$side}) {
                $declarations[] = $declaration.':'.$this->{$side};
            }
        }

        if (null !== $this->align) {
            $declarations[] = 'text-align:'.$this->align;
        }

        return implode(';', $declarations);
    }

    /**
     * @param list<TranslatableText> $problems
     */
    private static function color(mixed $value, string $setting, array &$problems): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $color = \is_string($value) ? strtolower(trim($value)) : '';

        if (1 !== preg_match(self::COLOR_PATTERN, $color)) {
            $problems[] = TranslatableText::of('The %s of the appearance is not a colour written #rrggbb.', $setting);

            return null;
        }

        return $color;
    }

    /**
     * A preset, or a length for both sides.
     *
     * @param list<TranslatableText> $problems
     */
    private static function preset(mixed $value, array &$problems): ?string
    {
        if (\is_string($value) && \array_key_exists($value, self::PADDINGS)) {
            return $value;
        }

        return self::length($value, 'padding', $problems, array_keys(self::PADDINGS));
    }

    /**
     * A length written as a number of two decimals at most and `px` or
     * `rem`, within the bound of its unit. Anything else (`calc()`, `var()`,
     * a negative number, another unit) is refused.
     *
     * @param list<TranslatableText> $problems
     * @param list<string>           $alsoAllowed the presets, named in the message
     */
    private static function length(mixed $value, string $setting, array &$problems, array $alsoAllowed = []): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $length = \is_string($value) ? strtolower(trim($value)) : '';

        if (1 === preg_match(self::LENGTH_PATTERN, $length, $parts) && (float) $parts[1] <= self::MAX_LENGTHS[$parts[2]]) {
            return $length;
        }

        $problems[] = [] === $alsoAllowed
            ? TranslatableText::of('The %s of the appearance is not a length: write a number and px (0 to %d) or rem (0 to %d), such as 64px or 2rem.', $setting, self::MAX_LENGTHS['px'], self::MAX_LENGTHS['rem'])
            : TranslatableText::of('The %s of the appearance is none of %s, nor a length: write a number and px (0 to %d) or rem (0 to %d), such as 64px or 2rem.', $setting, implode(', ', $alsoAllowed), self::MAX_LENGTHS['px'], self::MAX_LENGTHS['rem']);

        return null;
    }

    /**
     * @param list<string>           $allowed
     * @param list<TranslatableText> $problems
     */
    private static function oneOf(mixed $value, string $setting, array $allowed, array &$problems): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            $problems[] = TranslatableText::of('The %s of the appearance is none of %s.', $setting, implode(', ', $allowed));

            return null;
        }

        return $value;
    }
}
