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
use TheliaCMS\TheliaCMS;

/**
 * A heading on its own between two sections, at the level the editor
 * chooses: the page draws its title as the one `h1`, so the levels offered
 * start at `h2`, and the heading check of the publication reads it like any
 * other heading.
 *
 * Its look is chosen apart from its level, among the heading styles the theme
 * declares (`heading_styles`, ADR 18): an `h3` may look like the largest
 * heading of the theme. By default it looks like the headings of the free
 * text at its level.
 */
final readonly class HeadingSection implements SectionDefinitionInterface
{
    public const string NAME = 'cms:heading';

    /** The levels offered, the first one by default. */
    public const array LEVELS = ['h2', 'h3', 'h4'];

    /** The style by default: the look of its level, whatever the theme declares. */
    public const string LEVEL_STYLE = 'level';

    public function __construct(
        private ?TranslatorInterface $translator = null,
        private ?ThemeComponentSections $theme = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return $this->trans('Heading');
    }

    public function description(): string
    {
        return $this->trans('A heading on its own, between two sections: it opens a part of the page. Level 2 under the title of the page, level 3 under a heading of level 2, never a level skipped.');
    }

    public function schema(): PropSchema
    {
        $styles = [self::LEVEL_STYLE => $this->trans('Like its level')];

        foreach ($this->styles() as $value => $style) {
            $styles[$value] = $this->trans($style['label']);
        }

        return new PropSchema([
            Prop::text('text', $this->trans('Heading'), required: true, max: 120),
            Prop::choice('level', $this->trans('Level'), [
                'h2' => $this->trans('Level 2, a part of the page'),
                'h3' => $this->trans('Level 3, under a heading of level 2'),
                'h4' => $this->trans('Level 4, under a heading of level 3'),
            ], self::LEVELS[0], $this->trans('The title of the page is the only heading of level 1.')),
            Prop::choice('style', $this->trans('Appearance'), $styles, self::LEVEL_STYLE, $this->trans('A heading style of the theme, whatever the level: the level orders the page, the appearance is how it looks.')),
        ]);
    }

    public function sample(): array
    {
        return ['text' => $this->trans('A heading'), 'level' => self::LEVELS[0], 'style' => self::LEVEL_STYLE];
    }

    /**
     * A style the theme no longer declares falls back on the look of the
     * level: the page was right when it was written, and a look is not worth
     * refusing it for.
     */
    public function normalizeProps(array $props): array
    {
        if (isset($props['style']) && \is_string($props['style']) && !\array_key_exists($props['style'], $this->styles())) {
            $props['style'] = self::LEVEL_STYLE;
        }

        return $this->schema()->normalize($props);
    }

    public function render(array $props, string $locale): string
    {
        $text = trim((string) ($props['text'] ?? ''));

        if ('' === $text) {
            return '';
        }

        $level = \in_array($props['level'] ?? null, self::LEVELS, true) ? $props['level'] : self::LEVELS[0];
        $style = $this->styles()[(string) ($props['style'] ?? '')] ?? null;

        if (null !== $style) {
            // The classes of the style alone: the free text the theme styles
            // would size it by its level again.
            return \sprintf(
                '<div class="cms-heading"><%s class="cms-heading__text cms-heading__text--styled %s">%s</%1$s></div>',
                $level,
                htmlspecialchars($style['class'], \ENT_QUOTES | \ENT_HTML5),
                htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5),
            );
        }

        // The class the theme styles its free text under: a heading of the
        // page looks like the headings of its text.
        $themeClass = $this->theme?->declaration()->richTextClass;
        $class = null === $themeClass ? 'cms-heading' : 'cms-heading '.$themeClass;

        return \sprintf(
            '<div class="%s"><%s class="cms-heading__text">%s</%2$s></div>',
            htmlspecialchars($class, \ENT_QUOTES | \ENT_HTML5),
            $level,
            htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5),
        );
    }

    /**
     * @return array<string, array{label: string, class: string}>
     */
    private function styles(): array
    {
        return $this->theme?->declaration()->headingStyles ?? [];
    }

    private function trans(string $message): string
    {
        return $this->translator?->trans($message, [], TheliaCMS::DOMAIN_NAME) ?? $message;
    }
}
