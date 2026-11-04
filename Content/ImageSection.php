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
 * One picture on its own, with an optional caption under it: the photo of a
 * dish, of a workshop, of a product in use.
 *
 * Free text holds no image, and no theme ships a component that shows one
 * picture at the width of the page: without it, a brief asking for "a photo
 * of the tart out of the oven" had nowhere to put it. An image section with
 * no image draws nothing.
 */
final readonly class ImageSection implements SectionDefinitionInterface
{
    public const string NAME = 'cms:image';

    public const array WIDTHS = ['narrow', 'column', 'full'];

    /** The width the editor sets in pixels, the one value of `width` the generator is never offered. */
    public const string CUSTOM = 'custom';

    /** Width of the box, in pixels, when it is set: a smaller one is a thumbnail, a larger one no screen shows. */
    public const int MIN_WIDTH = 160;

    public const int MAX_WIDTH = 2400;

    public const int MIN_HEIGHT = 80;

    public const int MAX_HEIGHT = 2000;

    /** The most stretched box, width over height: 4:1 and 1:4. */
    public const int MAX_RATIO = 4;

    /** Zoom, in percent, moves by steps of this many: a few distinct zones per image, not thousands. */
    public const int ZOOM_STEP = 10;

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
        return $this->trans('Image');
    }

    public function description(): string
    {
        return $this->trans('One picture on its own (a photo, an illustration) with an optional caption under it. Only with an image of the media library or the https address of a picture the brief gives: with neither, leave the section out.');
    }

    public function schema(): PropSchema
    {
        return new PropSchema([
            Prop::image('image', $this->trans('Image'), help: $this->trans('An image of the media library, or the https address of a picture kept elsewhere.'))
                ->forEditor(help: $this->trans('The picture shown on the page: one of the media library, or the address of a picture kept elsewhere.')),
            Prop::choice('width', $this->trans('Width'), [
                'narrow' => $this->trans('Narrow, as a column of text'),
                'column' => $this->trans('As wide as the content'),
                'full' => $this->trans('The whole width of the screen'),
                self::CUSTOM => $this->trans('A size in pixels'),
            ], 'narrow', $this->trans('Narrow suits a photo or an illustration; wider only when the brief asks for a large picture.'), [
                'narrow' => 'width-narrow',
                'column' => 'width-column',
                'full' => 'width-full',
                self::CUSTOM => 'bi-rulers',
            ])->forEditor(
                help: $this->trans('How wide the picture is on the page. Narrow suits most photos; "A size in pixels" sets the width, and the height if you want a frame.'),
                onlyChoices: [self::CUSTOM],
            ),
            Prop::integer('width_px', $this->trans('Width in pixels'), 0, 0, self::MAX_WIDTH)
                ->forEditor(help: $this->trans('Between 160 and 2400. On a narrow screen the picture shrinks and keeps its proportions.'), only: true),
            Prop::integer('height_px', $this->trans('Height in pixels'), 0, 0, self::MAX_HEIGHT)
                ->forEditor(help: $this->trans('Between 80 and 2000. Leave it at 0 to keep the height the picture has.'), only: true),
            Prop::choice('fit', $this->trans('Framing'), [
                'cover' => $this->trans('Fill the frame, crop the rest'),
                'contain' => $this->trans('Show the whole picture'),
            ], 'cover', icons: ['cover' => 'bi-crop', 'contain' => 'bi-aspect-ratio'])
                ->forEditor(help: $this->trans('Used when both the width and the height are set. "Fill" crops the picture to the frame; "Show the whole picture" leaves empty bands if the proportions differ.'), only: true),
            Prop::integer('focus_x', $this->trans('Kept part, horizontal'), 50, 0, 100)
                ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the left (0) to the right (100) edge.'), only: true),
            Prop::integer('focus_y', $this->trans('Kept part, vertical'), 50, 0, 100)
                ->forEditor(help: $this->trans('Where the part of the picture to keep sits, from the top (0) to the bottom (100) edge.'), only: true),
            Prop::integer('zoom', $this->trans('Zoom'), 100, 100, 400)
                ->forEditor(help: $this->trans('100 shows the whole frame; 200 keeps half of its width and height. By steps of 10.'), only: true),
            Prop::text('caption', $this->trans('Caption'), help: $this->trans('Under the image; none when empty.'))
                ->forEditor(help: $this->trans('A line of text under the picture. Leave it empty for none.')),
            Prop::text('alt', $this->trans('Text of the image'), help: $this->trans('For a picture kept elsewhere: what it shows, for a reader who does not see it. An image of the media library is described in the library.'))
                ->forEditor(help: $this->trans('What the picture shows, for people who cannot see it. Only needed for a picture kept elsewhere: a picture of the media library is described in the library.')),
        ]);
    }

    public function sample(): array
    {
        return ['image' => null, 'alt' => '', 'caption' => $this->trans('A caption under the picture.'), 'width' => 'narrow'];
    }

    public function normalizeProps(array $props): array
    {
        $props = $this->schema()->normalize($props);
        $problems = [];

        foreach (['width_px' => [self::MIN_WIDTH, self::MAX_WIDTH], 'height_px' => [self::MIN_HEIGHT, self::MAX_HEIGHT]] as $name => [$min, $max]) {
            if (0 !== $props[$name] && ($props[$name] < $min || $props[$name] > $max)) {
                $problems[] = TranslatableText::of('"%s" must be 0 or between %d and %d.', $name, $min, $max);
            }
        }

        if (0 !== $props['zoom'] % self::ZOOM_STEP) {
            $problems[] = TranslatableText::of('"%s" must be a multiple of %d.', 'zoom', self::ZOOM_STEP);
        }

        if ([] === $problems && $props['width_px'] > 0 && $props['height_px'] > 0 && !self::hasAllowedRatio($props['width_px'], $props['height_px'])) {
            $problems[] = TranslatableText::of('The width and the height must keep a ratio between %d:%d and %d:%d.', 1, self::MAX_RATIO, self::MAX_RATIO, 1);
        }

        if ([] !== $problems) {
            throw InvalidDocumentException::because($problems);
        }

        return $props;
    }

    public function render(array $props, string $locale): string
    {
        $source = ImageSource::of($props['image'] ?? null);

        if (null === $source) {
            return '';
        }

        $width = \in_array($props['width'] ?? null, self::WIDTHS, true) ? $props['width'] : 'narrow';
        $widthPixels = (int) ($props['width_px'] ?? 0);

        if (self::CUSTOM === ($props['width'] ?? null) && $widthPixels > 0) {
            return $this->renderBox($props, $source, $widthPixels, $locale);
        }

        $html = \sprintf(
            '<figure class="cms-image cms-image--%s"%s><img class="cms-image__img" src="%s" alt="%s" loading="lazy">',
            $width,
            ImageSlot::attribute(self::slot($width)),
            self::escape($source),
            self::escape(ImageSource::alt($props['image'] ?? null, (string) ($props['alt'] ?? ''), $locale)),
        );

        return $html.$this->caption($props).'</figure>';
    }

    /**
     * A picture whose width, and perhaps height, the editor set. With both, it
     * sits in a box of those proportions, at every width of the screen: the
     * box keeps its ratio as it shrinks, and the picture either fills it
     * (cropped around the kept part) or is shown whole in it.
     *
     * @param array<string, mixed> $props
     */
    private function renderBox(array $props, string $source, int $widthPixels, string $locale): string
    {
        $heightPixels = (int) ($props['height_px'] ?? 0);
        $framed = $heightPixels > 0;
        $contain = $framed && 'contain' === ($props['fit'] ?? 'cover');
        $imageClass = 'cms-image__img';
        $imageStyle = '';

        if ($framed) {
            $imageClass .= $contain ? ' cms-image__img--contain' : ' cms-image__img--cover';
            $imageStyle = \sprintf(' style="aspect-ratio:%d/%d;object-fit:%s%s"', $widthPixels, $heightPixels, $contain ? 'contain' : 'cover', $contain ? '' : \sprintf(';object-position:%d%% %d%%', (int) ($props['focus_x'] ?? 50), (int) ($props['focus_y'] ?? 50)));
        }

        $html = \sprintf(
            '<figure class="cms-image cms-image--custom" style="width:min(%dpx, 100%% - 2rem)"%s><img class="%s" src="%s" alt="%s" loading="lazy"%s>',
            $widthPixels,
            ImageSlot::attribute(ImageSlot::box($widthPixels), $contain ? $heightPixels.'px' : null),
            $imageClass,
            self::escape($source),
            self::escape(ImageSource::alt($props['image'] ?? null, (string) ($props['alt'] ?? ''), $locale)),
            $imageStyle,
        );

        return $html.$this->caption($props).'</figure>';
    }

    /**
     * @param array<string, mixed> $props
     */
    private function caption(array $props): string
    {
        $caption = trim((string) ($props['caption'] ?? ''));

        return '' === $caption ? '' : '<figcaption class="cms-image__caption">'.self::escape($caption).'</figcaption>';
    }

    /**
     * Whether a box is no more stretched than 4:1 or 1:4: beyond that it is a
     * strip nobody asked a picture for.
     */
    private static function hasAllowedRatio(int $width, int $height): bool
    {
        return $width <= $height * self::MAX_RATIO && $height <= $width * self::MAX_RATIO;
    }

    /**
     * The width the picture is drawn at, which `blocks.css` gives each width
     * of the section.
     */
    private static function slot(string $width): string
    {
        return match ($width) {
            'full' => ImageSlot::screen(),
            'column' => ImageSlot::content(),
            default => ImageSlot::narrow(),
        };
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
