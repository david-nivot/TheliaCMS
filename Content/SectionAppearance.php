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
 */
final readonly class SectionAppearance
{
    public const array PADDINGS = ['small' => '1.5rem', 'medium' => '3rem', 'large' => '5rem'];

    public const array ALIGNMENTS = ['left', 'center', 'right'];

    private const string COLOR_PATTERN = '/^#[0-9a-f]{6}$/';

    public function __construct(
        public ?string $background = null,
        public ?string $text = null,
        public ?string $padding = null,
        public ?string $align = null,
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
            if (!\in_array($key, ['background', 'text', 'padding', 'align'], true)) {
                $problems[] = TranslatableText::of('The appearance has no setting "%s": only background, text, padding and align.', $key);
            }
        }

        $background = self::color($values['background'] ?? null, 'background', $problems);
        $text = self::color($values['text'] ?? null, 'text', $problems);
        $padding = self::oneOf($values['padding'] ?? null, 'padding', array_keys(self::PADDINGS), $problems);
        $align = self::oneOf($values['align'] ?? null, 'align', self::ALIGNMENTS, $problems);

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return new self($background, $text, $padding, $align);
    }

    public function isEmpty(): bool
    {
        return [] === $this->toArray();
    }

    /**
     * @return array{background?: string, text?: string, padding?: string, align?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'background' => $this->background,
            'text' => $this->text,
            'padding' => $this->padding,
            'align' => $this->align,
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
            $declarations[] = 'padding-block:'.self::PADDINGS[$this->padding];
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
