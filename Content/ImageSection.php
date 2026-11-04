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
            ], 'narrow', $this->trans('Narrow suits a photo or an illustration; wider only when the brief asks for a large picture.'), [
                'narrow' => 'width-narrow',
                'column' => 'width-column',
                'full' => 'width-full',
            ])->forEditor(help: $this->trans('How wide the picture is on the page. Narrow suits most photos.')),
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
        return $this->schema()->normalize($props);
    }

    public function render(array $props, string $locale): string
    {
        $source = ImageSource::of($props['image'] ?? null);

        if (null === $source) {
            return '';
        }

        $width = \in_array($props['width'] ?? null, self::WIDTHS, true) ? $props['width'] : 'narrow';
        $html = \sprintf(
            '<figure class="cms-image cms-image--%s"%s><img class="cms-image__img" src="%s" alt="%s" loading="lazy">',
            $width,
            ImageSlot::attribute(self::slot($width)),
            self::escape($source),
            self::escape(ImageSource::alt($props['image'] ?? null, (string) ($props['alt'] ?? ''), $locale)),
        );

        if ('' !== $caption = trim((string) ($props['caption'] ?? ''))) {
            $html .= '<figcaption class="cms-image__caption">'.self::escape($caption).'</figcaption>';
        }

        return $html.'</figure>';
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
