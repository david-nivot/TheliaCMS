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
 * The color setting the editor chose for a drawing: the preset, whether the
 * colors other than the main one follow the text lighter, whether a white
 * stays white, and which color is the main one. It travels in the address of
 * the screen and in the form that adds the file.
 */
final readonly class SvgColoring
{
    public const string PRESET_PARAMETER = 'colors';

    public const string OTHERS_PARAMETER = 'others';

    public const string WHITE_PARAMETER = 'white';

    public const string MAIN_PARAMETER = 'main';

    /** The opacity of the colors other than the main one, once they follow the text lighter. */
    public const float SOFTENED_OPACITY = 0.4;

    public function __construct(
        public SvgColorPreset $preset = SvgColorPreset::Keep,
        public bool $keepWhite = false,
        // Null: the color of the most elements.
        public ?string $main = null,
        // With the main color preset: the others follow the text, lighter.
        public bool $softenOthers = false,
    ) {
    }

    /**
     * @param array<string, mixed> $values the query or the form
     */
    public static function fromValues(array $values): self
    {
        $preset = SvgColorPreset::tryFrom(self::text($values[self::PRESET_PARAMETER] ?? '')) ?? SvgColorPreset::Keep;
        $main = strtolower(self::text($values[self::MAIN_PARAMETER] ?? ''));

        return new self(
            $preset,
            'keep' === self::text($values[self::WHITE_PARAMETER] ?? ''),
            1 === preg_match(SvgColor::KEY_PATTERN, $main) ? $main : null,
            'soft' === self::text($values[self::OTHERS_PARAMETER] ?? ''),
        );
    }

    public function usesMain(): bool
    {
        return SvgColorPreset::Main === $this->preset;
    }

    /** True when the others follow the text lighter: the main color preset, with its option. */
    public function softens(): bool
    {
        return $this->usesMain() && $this->softenOthers;
    }

    /** True when a white of the drawing may follow the text, so the editor says whether it does. */
    public function asksAboutWhite(): bool
    {
        return SvgColorPreset::Monochrome === $this->preset || $this->softens();
    }

    /** The same setting, the main color told: the one asked if the drawing paints with it, else its most used one. */
    public function forPalette(SvgPalette $palette): self
    {
        $main = null !== $this->main && $palette->paints($this->main) ? $this->main : $palette->main();

        return new self($this->preset, $this->keepWhite, $main, $this->softenOthers);
    }

    /**
     * The setting carried to the next files of a sending: the main color is
     * the one of each drawing, so it is not carried.
     */
    public function withoutMain(): self
    {
        return new self($this->preset, $this->keepWhite, null, $this->softenOthers);
    }

    public function changesNothing(): bool
    {
        return SvgColorPreset::Keep === $this->preset;
    }

    /**
     * @return array<string, string> the values of the address, none for what is left at its default
     */
    public function values(): array
    {
        if ($this->changesNothing()) {
            return [];
        }

        return array_filter([
            self::PRESET_PARAMETER => $this->preset->value,
            self::OTHERS_PARAMETER => $this->softens() ? 'soft' : '',
            self::WHITE_PARAMETER => $this->keepWhite && $this->asksAboutWhite() ? 'keep' : '',
            self::MAIN_PARAMETER => $this->usesMain() ? (string) $this->main : '',
        ], static fn (string $value): bool => '' !== $value);
    }

    /**
     * The part of the key a prepared result is kept under, [a-z0-9-] only;
     * empty when the colors are kept.
     */
    public function key(): string
    {
        if ($this->changesNothing()) {
            return '';
        }

        $key = $this->preset->value;

        if ($this->softens()) {
            $key .= '-soft';
        }

        if ($this->keepWhite && $this->asksAboutWhite()) {
            $key .= '-white';
        }

        if ($this->usesMain() && null !== $this->main) {
            $key .= '-'.(str_starts_with($this->main, '#') ? 'h'.substr($this->main, 1) : 'n'.$this->main);
        }

        return $key;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
