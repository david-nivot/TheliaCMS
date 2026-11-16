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
    public const array LEVELS = HeadingChoice::LEVELS;

    /** The style by default: the look of its level, whatever the theme declares. */
    public const string LEVEL_STYLE = HeadingChoice::LEVEL_STYLE;

    private HeadingChoice $heading;

    public function __construct(
        private ?TranslatorInterface $translator = null,
        private ?ThemeComponentSections $theme = null,
    ) {
        $this->heading = new HeadingChoice($translator, $theme);
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
        return new PropSchema([
            Prop::text('text', $this->trans('Heading'), required: true, max: 120),
            $this->heading->levelProp('level', 'Level'),
            $this->heading->styleProp('style', 'Appearance'),
        ]);
    }

    public function sample(): array
    {
        return ['text' => $this->trans('A heading'), 'level' => self::LEVELS[0], 'style' => self::LEVEL_STYLE];
    }

    /** A style the theme no longer declares falls back on the look of the level. */
    public function normalizeProps(array $props): array
    {
        return $this->schema()->normalize($this->heading->forgetUndeclaredStyle($props, 'style'));
    }

    public function render(array $props, string $locale): string
    {
        $text = trim((string) ($props['text'] ?? ''));

        if ('' === $text) {
            return '';
        }

        $level = $this->heading->level($props['level'] ?? null);
        $classes = $this->heading->classes('cms-heading__text', $props['style'] ?? null);

        if ('cms-heading__text' !== $classes) {
            // The classes of the style alone: the free text the theme styles
            // would size it by its level again.
            return \sprintf(
                '<div class="cms-heading"><%s class="%s">%s</%1$s></div>',
                $level,
                htmlspecialchars($classes, \ENT_QUOTES | \ENT_HTML5),
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

    private function trans(string $message): string
    {
        return $this->translator?->trans($message, [], TheliaCMS::DOMAIN_NAME) ?? $message;
    }
}
