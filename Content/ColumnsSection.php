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
 * Two to four columns side by side, each an image over a short text: logos
 * of partners, the three promises of a shop, the steps of a service.
 *
 * Drawn by the module rather than the theme, with the grid of its stylesheet:
 * no theme ships a component that takes any number of columns, and free text
 * cannot lay one out. The columns stack on a narrow screen.
 */
final readonly class ColumnsSection implements SectionDefinitionInterface
{
    public const string NAME = 'cms:columns';

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
        return $this->trans('Columns');
    }

    public function description(): string
    {
        return $this->trans('Two to four columns side by side, each an image (a logo, an icon, a photo) over an optional heading and a short text: partners, promises, steps. As many columns as items.');
    }

    public function schema(): PropSchema
    {
        return new PropSchema([
            Prop::text('title', $this->trans('Heading'), help: $this->trans('Above the columns; none when empty.')),
            Prop::items('columns', $this->trans('Columns'), [
                Prop::image('image', $this->trans('Image'), help: $this->trans('An image of the media library, or the https address of a picture kept elsewhere.')),
                Prop::text('image_alt', $this->trans('Text of the image'), help: $this->trans('For a picture kept elsewhere: what it shows, for a reader who does not see it. An image of the media library is described in the library.')),
                Prop::text('title', $this->trans('Heading')),
                Prop::text('text', $this->trans('Text'), max: 500),
            ], min: 2, max: 4),
        ]);
    }

    public function sample(): array
    {
        $column = ['image' => null, 'image_alt' => '', 'title' => $this->trans('A heading'), 'text' => $this->trans('A sentence or two on what this column is about.')];

        return ['title' => '', 'columns' => [$column, $column, $column]];
    }

    public function normalizeProps(array $props): array
    {
        return $this->schema()->normalize($props);
    }

    public function render(array $props, string $locale): string
    {
        $columns = array_values(array_filter(\is_array($props['columns'] ?? null) ? $props['columns'] : [], 'is_array'));

        if ([] === $columns) {
            return '';
        }

        $html = \sprintf('<section class="cms-columns cms-columns--%d"%s>', \count($columns), ImageSlot::attribute(ImageSlot::column(\count($columns))));
        $title = trim((string) ($props['title'] ?? ''));

        if ('' !== $title) {
            $html .= '<h2 class="cms-columns__title">'.self::escape($title).'</h2>';
        }

        $html .= '<div class="cms-columns__grid">';

        foreach ($columns as $column) {
            $html .= '<div class="cms-columns__item">';
            $source = ImageSource::of($column['image'] ?? null);

            if (null !== $source) {
                $html .= \sprintf('<img class="cms-columns__image" src="%s" alt="%s" loading="lazy">', self::escape($source), self::escape(ImageSource::alt($column['image'] ?? null, (string) ($column['image_alt'] ?? ''), $locale)));
            }

            if ('' !== $heading = trim((string) ($column['title'] ?? ''))) {
                $html .= '<h3 class="cms-columns__heading">'.self::escape($heading).'</h3>';
            }

            if ('' !== $text = trim((string) ($column['text'] ?? ''))) {
                $html .= '<p class="cms-columns__text">'.self::escape($text).'</p>';
            }

            $html .= '</div>';
        }

        return $html.'</div></section>';
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
