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
use TheliaCMS\Locale\TranslatableText;
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
            Prop::choice('proportions', $this->trans('Proportions of the images'), [
                ImageProportions::BAND => $this->trans('Band (logos)'),
                ImageProportions::ORIGINAL => $this->trans('Original image'),
                ...array_combine(ImageProportions::ratios(), ImageProportions::ratios()),
            ], ImageProportions::BAND, icons: [
                ImageProportions::BAND => 'bi-distribute-vertical',
                ImageProportions::ORIGINAL => 'bi-image',
                ...array_combine(ImageProportions::ratios(), array_map(static fn (string $ratio): string => 'ratio-'.str_replace(':', '-', $ratio), ImageProportions::ratios())),
            ])->forEditor(
                help: $this->trans('"Band" shows each picture whole in a strip of the same height, as a row of logos. The other choices give every picture the width of its column, in this shape.'),
                only: true,
            ),
            Prop::items('columns', $this->trans('Columns'), [
                Prop::image('image', $this->trans('Image'), help: $this->trans('An image of the media library, or the https address of a picture kept elsewhere.')),
                Prop::text('image_alt', $this->trans('Text of the image'), help: $this->trans('For a picture kept elsewhere: what it shows, for a reader who does not see it. An image of the media library is described in the library.')),
                Prop::text('title', $this->trans('Heading')),
                Prop::text('text', $this->trans('Text'), max: 500),
                Prop::integer('focus_x', $this->trans('Kept part, horizontal'), 50, 0, 100)
                    ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the left (0) to the right (100) edge.'), only: true),
                Prop::integer('focus_y', $this->trans('Kept part, vertical'), 50, 0, 100)
                    ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the top (0) to the bottom (100) edge.'), only: true),
                Prop::integer('zoom', $this->trans('Zoom'), 100, 100, 400)
                    ->forEditor(help: $this->trans('100 shows the whole frame; 200 keeps half of its width and height. By steps of 10.'), only: true),
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
        $props = $this->schema()->normalize($props);

        foreach ($props['columns'] as $index => $column) {
            if (0 !== $column['zoom'] % ImageSection::ZOOM_STEP) {
                throw InvalidDocumentException::because([TranslatableText::of('"%s" item %d: %s', 'columns', $index + 1, TranslatableText::of('"%s" must be a multiple of %d.', 'zoom', ImageSection::ZOOM_STEP))]);
            }
        }

        return $props;
    }

    public function render(array $props, string $locale): string
    {
        $columns = array_values(array_filter(\is_array($props['columns'] ?? null) ? $props['columns'] : [], 'is_array'));

        if ([] === $columns) {
            return '';
        }

        $proportions = $props['proportions'] ?? ImageProportions::BAND;
        $banded = ImageProportions::BAND === $proportions || !(ImageProportions::ORIGINAL === $proportions || ImageProportions::isRatio($proportions));

        // Only the band is held to a height: a picture on the width of its column has the height its proportions give.
        $html = \sprintf('<section class="cms-columns cms-columns--%d"%s>', \count($columns), ImageSlot::attribute(ImageSlot::column(\count($columns)), $banded ? ImageSlot::columnBand() : null));
        $title = trim((string) ($props['title'] ?? ''));

        if ('' !== $title) {
            $html .= '<h2 class="cms-columns__title">'.self::escape($title).'</h2>';
        }

        $html .= '<div class="cms-columns__grid">';

        foreach ($columns as $column) {
            $html .= '<div class="cms-columns__item">';
            $source = ImageSource::of($column['image'] ?? null);

            if (null !== $source) {
                $html .= \sprintf(
                    '<img class="cms-columns__image%s" src="%s" alt="%s" loading="lazy"%s>',
                    $banded ? '' : ' cms-columns__image--framed',
                    self::escape($source),
                    self::escape(ImageSource::alt($column['image'] ?? null, (string) ($column['image_alt'] ?? ''), $locale)),
                    $banded ? '' : self::framing($proportions, $column),
                );
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

    /**
     * The attributes of a picture on the width of its column: a ratio crops it
     * to its shape around the part the editor keeps of it; the picture as it is
     * keeps its own shape and is zoomed, for the publication to serve the zone.
     *
     * @param array<string, mixed> $column
     */
    private static function framing(string $proportions, array $column): string
    {
        $focusX = (int) ($column['focus_x'] ?? 50);
        $focusY = (int) ($column['focus_y'] ?? 50);
        $zoom = (int) ($column['zoom'] ?? 100);
        $box = ImageProportions::box($proportions);

        if (null === $box) {
            return $zoom > 100 ? ImageFrame::attribute($focusX, $focusY, $zoom, 0, 0) : '';
        }

        return \sprintf(' style="aspect-ratio:%s;object-fit:cover;object-position:%d%% %d%%"', ImageProportions::aspectRatio($proportions), $focusX, $focusY).ImageFrame::attribute($focusX, $focusY, $zoom, $box[0], $box[1]);
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
