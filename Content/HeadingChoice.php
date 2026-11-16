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

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\Theme\ThemeComponentSections;
use TheliaCMS\Partial\PartialProp;
use TheliaCMS\TheliaCMS;

/**
 * The level and the look of a heading, chosen by the editor: a heading on its
 * own (`cms:heading`) and the heading that opens a section of the module
 * (columns, a list of pages, a form) offer the same two choices.
 *
 * The page draws its title as the one `h1`, so the levels start at `h2`. The
 * look is one of the heading styles the theme declares (`heading_styles`,
 * ADR 18), whatever the level; by default, the look of the level. The headings
 * a section draws under its own (a column, a card) are one level below it.
 */
final readonly class HeadingChoice
{
    /** The levels offered, the first one by default. */
    public const array LEVELS = ['h2', 'h3', 'h4'];

    /** The style by default: the look of its level, whatever the theme declares. */
    public const string LEVEL_STYLE = 'level';

    /** The props of a section whose heading is one prop among others. */
    public const string LEVEL_PROP = 'title_level';
    public const string STYLE_PROP = 'title_style';

    public function __construct(
        private ?TranslatorInterface $translator = null,
        private ?ThemeComponentSections $theme = null,
    ) {
    }

    public function levelProp(string $name = self::LEVEL_PROP, string $label = 'Level of the heading'): Prop
    {
        return Prop::choice($name, $this->trans($label), $this->levelChoices(), self::LEVELS[0], $this->trans('The title of the page is the only heading of level 1.'));
    }

    public function styleProp(string $name = self::STYLE_PROP, string $label = 'Appearance of the heading'): Prop
    {
        return Prop::choice($name, $this->trans($label), $this->styleChoices(), self::LEVEL_STYLE, $this->trans('A heading style of the theme, whatever the level: the level orders the page, the appearance is how it looks.'));
    }

    /**
     * The same two choices for a partial, which declares its props apart.
     *
     * @return list<PartialProp>
     */
    public function partialProps(): array
    {
        return array_map(
            static fn (Prop $prop): PartialProp => PartialProp::choice($prop->name, $prop->label, $prop->choices, (string) $prop->default, $prop->help),
            [$this->levelProp(), $this->styleProp()],
        );
    }

    /**
     * A style the theme no longer declares falls back on the look of the
     * level: the page was right when it was written, and a look is not worth
     * refusing it for.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    public function forgetUndeclaredStyle(array $props, string $name = self::STYLE_PROP): array
    {
        if (isset($props[$name]) && \is_string($props[$name]) && !\array_key_exists($props[$name], $this->declaredStyles())) {
            $props[$name] = self::LEVEL_STYLE;
        }

        return $props;
    }

    public function level(mixed $level): string
    {
        return \in_array($level, self::LEVELS, true) ? $level : self::LEVELS[0];
    }

    /** The level of the headings drawn under a heading of this level. */
    public function childLevel(mixed $level): string
    {
        return 'h'.((int) substr($this->level($level), 1) + 1);
    }

    /**
     * The classes of a heading: its own, or, in a style of the theme, its own
     * with the `--styled` modifier and the classes of the style. The modifier
     * keeps the sheet of the module from sizing it by its level again.
     */
    public function classes(string $base, mixed $style): string
    {
        $class = \is_string($style) ? ($this->declaredStyles()[$style]['class'] ?? null) : null;

        return null === $class ? $base : \sprintf('%s %1$s--styled %s', $base, $class);
    }

    /**
     * What a template needs to draw the heading of a section: its tag, the
     * tag of the headings under it, and its classes past the base one.
     *
     * @param array<string, mixed> $props
     *
     * @return array{tag: string, child_tag: string, styled: bool, class: string|null}
     */
    public function forTemplate(array $props): array
    {
        $style = $props[self::STYLE_PROP] ?? null;
        $class = \is_string($style) ? ($this->declaredStyles()[$style]['class'] ?? null) : null;

        return [
            'tag' => $this->level($props[self::LEVEL_PROP] ?? null),
            'child_tag' => $this->childLevel($props[self::LEVEL_PROP] ?? null),
            'styled' => null !== $class,
            'class' => $class,
        ];
    }

    /**
     * The levels offered, by value, in the language given or the one of the
     * request.
     *
     * @return array<string, string>
     */
    public function levelChoices(?string $locale = null): array
    {
        return [
            'h2' => $this->trans('Level 2, a part of the page', $locale),
            'h3' => $this->trans('Level 3, under a heading of level 2', $locale),
            'h4' => $this->trans('Level 4, under a heading of level 3', $locale),
        ];
    }

    /**
     * The look of the level, then the styles the theme declares, by value.
     *
     * @return array<string, string>
     */
    public function styleChoices(?string $locale = null): array
    {
        $styles = [self::LEVEL_STYLE => $this->trans('Like its level', $locale)];

        foreach ($this->declaredStyles() as $value => $style) {
            $styles[$value] = $this->trans($style['label'], $locale);
        }

        return $styles;
    }

    /**
     * @return array<string, array{label: string, class: string}>
     */
    private function declaredStyles(): array
    {
        return $this->theme?->declaration()->headingStyles ?? [];
    }

    private function trans(string $message, ?string $locale = null): string
    {
        return $this->translator?->trans($message, [], TheliaCMS::DOMAIN_NAME, $locale) ?? $message;
    }
}
