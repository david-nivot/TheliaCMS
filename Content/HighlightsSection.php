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
use TheliaCMS\TheliaCMS;

/**
 * A band of one to three highlighted blocks, each a picture under a title, a
 * catchphrase and a button: what a shop puts under its header to push an
 * offer, a collection, a service.
 *
 * Drawn by the module with the grid of its stylesheet, edge to edge, in the structure of
 * the HeaderHighlights module (a large first block, shorter ones next to it,
 * a picture of its own for a narrow screen). A block may carry a second
 * picture for a screen narrower than 1024px; without one, the picture of the
 * computer is drawn everywhere.
 */
final readonly class HighlightsSection implements SectionDefinitionInterface
{
    public const string NAME = 'cms:highlights';

    /** The width under which the picture of a mobile is drawn, in pixels (HeaderHighlights draws its own at the same width). */
    public const int MOBILE_BREAKPOINT = 1024;

    public function __construct(
        private ?TranslatorInterface $translator = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return $this->trans('Highlights');
    }

    public function description(): string
    {
        return $this->trans('A band of one to three highlighted blocks, each a picture with a title, a catchphrase and a button: an offer, a collection, a service. The first block is the large one. Meant for the top of a home page, not for a list of arguments (use columns for that).');
    }

    public function schema(): PropSchema
    {
        return new PropSchema([
            Prop::items('blocks', $this->trans('Blocks'), [
                Prop::text('title', $this->trans('Title'), max: 80),
                Prop::text('text', $this->trans('Catchphrase'), max: 160, help: $this->trans('One short line under the title; none when empty.')),
                Prop::text('button_label', $this->trans('Button'), max: 40, help: $this->trans('The words of the button, such as "Discover". The button is drawn only with its address; none when empty.')),
                Prop::url('button_url', $this->trans('Address of the button'), help: $this->trans('A path of the site, such as /collections, or an https address. Empty: no button.')),
                Prop::image('image', $this->trans('Image'), help: $this->trans('The picture of the block, for a wide screen: one of the media library, or the https address of a picture kept elsewhere. The text is written over it: choose a picture whose lower part is calm.')),
                Prop::text('image_alt', $this->trans('Text of the image'), help: $this->trans('For a picture kept elsewhere: what it shows, for a reader who does not see it. An image of the media library is described in the library.')),
                Prop::image('image_mobile', $this->trans('Image for a narrow screen'), help: $this->trans('Optional: a picture drawn instead of the first one on a screen narrower than 1024px, such as a tighter crop of it. Empty: the first picture is drawn on every screen.')),
                Prop::integer('focus_x', $this->trans('Kept part, horizontal'), 50, 0, 100)
                    ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the left (0) to the right (100) edge.'), only: true),
                Prop::integer('focus_y', $this->trans('Kept part, vertical'), 50, 0, 100)
                    ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the top (0) to the bottom (100) edge.'), only: true),
            ], min: 1, max: 3, help: $this->trans('From one to three blocks. The first one is the large one: put the main offer there.')),
        ]);
    }

    public function sample(): array
    {
        return ['blocks' => [[
            'title' => $this->trans('A title'),
            'text' => $this->trans('A catchphrase.'),
            'button_label' => $this->trans('Discover'),
            'button_url' => '/',
            'image' => null,
            'image_alt' => '',
            'image_mobile' => null,
        ]]];
    }

    public function normalizeProps(array $props): array
    {
        return $this->schema()->normalize($props);
    }

    public function render(array $props, string $locale): string
    {
        $blocks = array_values(array_filter(\is_array($props['blocks'] ?? null) ? $props['blocks'] : [], 'is_array'));

        if ([] === $blocks) {
            return '';
        }

        // Edge to edge: a lone block takes the screen, two or three share it by thirds and the large one, the widest, takes two of them.
        $sizes = 1 === \count($blocks) ? ImageSlot::screen() : '(min-width: 48rem) 67vw, 100vw';
        $html = \sprintf('<ul class="cms-highlights cms-highlights--%d"%s>', \count($blocks), ImageSlot::attribute($sizes));

        foreach ($blocks as $position => $block) {
            $html .= \sprintf('<li class="cms-highlights__item%s">', 0 === $position ? ' cms-highlights__item--lead' : '').self::picture($block, $locale);

            $content = '';

            if ('' !== $title = trim((string) ($block['title'] ?? ''))) {
                $content .= '<p class="cms-highlights__title">'.self::escape($title).'</p>';
            }

            if ('' !== $text = trim((string) ($block['text'] ?? ''))) {
                $content .= '<p class="cms-highlights__text">'.self::escape($text).'</p>';
            }

            $label = trim((string) ($block['button_label'] ?? ''));
            $url = trim((string) ($block['button_url'] ?? ''));

            if ('' !== $label && '' !== $url) {
                $content .= \sprintf('<a class="cms-highlights__button" href="%s">%s</a>', self::escape($url), self::escape($label));
            }

            if ('' !== $content) {
                $html .= '<div class="cms-highlights__content">'.$content.'</div>';
            }

            $html .= '</li>';
        }

        return $html.'</ul>';
    }

    /**
     * The picture of a block: the one of the computer, and above it, for a
     * narrow screen, the one the editor chose for it. The `<picture>` is only
     * written when there is a second one: a lone `<img>` is what the
     * publication wraps in its own `<picture>` with a WebP alternative.
     *
     * @param array<string, mixed> $block
     */
    private static function picture(array $block, string $locale): string
    {
        $source = ImageSource::of($block['image'] ?? null);
        $mobile = ImageSource::of($block['image_mobile'] ?? null);

        if (null === $source && null === $mobile) {
            return '';
        }

        // A block with a picture for a narrow screen only still draws it, everywhere.
        $source ??= $mobile;
        $focusX = max(0, min(100, (int) ($block['focus_x'] ?? 50)));
        $focusY = max(0, min(100, (int) ($block['focus_y'] ?? 50)));

        $image = \sprintf(
            '<img class="cms-highlights__image" src="%s" alt="%s" loading="lazy"%s>',
            self::escape($source),
            self::escape(ImageSource::alt($block['image'] ?? $block['image_mobile'] ?? null, (string) ($block['image_alt'] ?? ''), $locale)),
            50 === $focusX && 50 === $focusY ? '' : \sprintf(' style="object-position:%d%% %d%%"', $focusX, $focusY),
        );

        if (null === $mobile || null === ImageSource::of($block['image'] ?? null) || $mobile === $source) {
            return $image;
        }

        return \sprintf(
            '<picture class="cms-highlights__picture"><source media="(max-width: %dpx)" srcset="%s">%s</picture>',
            self::MOBILE_BREAKPOINT - 1,
            self::escape($mobile),
            $image,
        );
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5);
    }

    private function trans(string $message): string
    {
        return $this->translator?->trans($message, [], TheliaCMS::DOMAIN_NAME) ?? $message;
    }
}
