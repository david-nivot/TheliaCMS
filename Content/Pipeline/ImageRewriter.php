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

namespace TheliaCMS\Content\Pipeline;

use TheliaCMS\Content\ImageFrame;
use TheliaCMS\Content\ImageSlot;
use TheliaCMS\Media\MediaDescriptions;
use TheliaCMS\Media\MediaFile;
use TheliaCMS\Media\MediaResolver;

/**
 * Rewrites the images of a page when it is published.
 *
 * The editor knows one URL per image; a published page needs rather more than
 * that — a modern format for browsers that take it, a set of widths so phones
 * do not download a desktop image, intrinsic dimensions so the layout does not
 * jump while loading, lazy loading everywhere except the image the visitor
 * sees first, and the description the library holds for an image printed
 * without a text.
 *
 * Doing it at publish time keeps it off the request path entirely.
 */
final readonly class ImageRewriter
{
    /**
     * Widths offered in the srcset besides the original. Only those below the
     * intrinsic width are kept: upscaling an image serves a bigger file for a
     * blurrier result. The original itself always closes the list, so an image
     * between two widths is not served at the smaller one and shown blurred.
     */
    private const array WIDTHS = [480, 960, 1440];

    /**
     * The widest slot an image is announced to fill: the viewport up to it.
     */
    private const int WIDEST_SLOT = 1440;

    public function __construct(
        private MediaResolver $library,
        private MediaDescriptions $descriptions,
    ) {
    }

    /**
     * @param string $locale the language of the rendering, the one the description of an image is read in
     */
    public function rewrite(?string $html, string $locale): ?string
    {
        if (null === $html || '' === trim($html)) {
            return $html;
        }

        $document = new \DOMDocument();
        $loaded = @$document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="cms-rewriter-root">'.$html.'</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING,
        );

        if (!$loaded) {
            return $html;
        }

        $root = $document->getElementById('cms-rewriter-root');

        if (!$root instanceof \DOMElement) {
            return $html;
        }

        $images = iterator_to_array($document->getElementsByTagName('img'));

        // Before the sources are rewritten: the library is asked by the address the rendering printed.
        $this->describe($images, $locale);

        foreach ($images as $position => $image) {
            $this->rewriteImage($document, $image, 0 === $position);
        }

        foreach (iterator_to_array($document->getElementsByTagName('picture')) as $picture) {
            $this->rewriteSources($picture);
        }

        // Read, it has done its work: nothing of it goes online.
        foreach ([ImageSlot::ATTRIBUTE, ImageSlot::HEIGHT_ATTRIBUTE, ImageFrame::ATTRIBUTE] as $attribute) {
            foreach (iterator_to_array((new \DOMXPath($document))->query('//*[@'.$attribute.']') ?: []) as $declaring) {
                if ($declaring instanceof \DOMElement) {
                    $declaring->removeAttribute($attribute);
                }
            }
        }

        return $this->innerHtml($document, $root);
    }

    /**
     * Gives an image of the library printed with no text, or a blank one, the
     * text the library holds for it in the language: its description, or an
     * empty text when it is decorative. A component of the theme prints the
     * text of its own prop, which is empty for an image described in the
     * library only; the accessibility check, which asks the library, would
     * otherwise pass a page whose visitors hear nothing. A text the rendering
     * holds is never replaced, and an image not described in the language is
     * left as it is, for the check to report.
     *
     * One question to the library for the whole page.
     *
     * @param list<\DOMElement> $images
     */
    private function describe(array $images, string $locale): void
    {
        $textless = array_values(array_filter($images, static fn (\DOMElement $image): bool => '' === trim($image->getAttribute('alt'))));

        if ([] === $textless) {
            return;
        }

        $texts = $this->descriptions->alternativeTextsOf(
            array_values(array_unique(array_map(static fn (\DOMElement $image): string => $image->getAttribute('src'), $textless))),
            $locale,
        );

        foreach ($textless as $image) {
            $text = $texts[$image->getAttribute('src')] ?? null;

            if (null !== $text) {
                $image->setAttribute('alt', $text);
            }
        }
    }

    private function rewriteImage(\DOMDocument $document, \DOMElement $image, bool $isFirst): void
    {
        // The first image of a page is the one the visitor is most likely to be
        // waiting for, so it is fetched eagerly and with priority; every other
        // one waits until it is needed.
        if ($isFirst) {
            $image->setAttribute('fetchpriority', 'high');
            $image->removeAttribute('loading');
        } else {
            $image->setAttribute('loading', 'lazy');
        }

        $image->setAttribute('decoding', 'async');

        $media = $this->library->fromUrl($image->getAttribute('src'));

        if (null === $media) {
            // An image the library does not own: nothing to resize or convert,
            // but the loading hints above still apply.
            return;
        }

        if ($media->isVector()) {
            $this->rewriteVector($image, $media);

            return;
        }

        $zone = $this->declaredZone($image, $media);
        $attributes = $this->attributes($media, $this->declaredSizes($image), $this->declaredHeight($image), $zone);

        foreach ($attributes as $name => $value) {
            $image->setAttribute($name, $value);
        }

        if (null !== $zone) {
            // The file a browser without `srcset` takes is the kept part too.
            $image->setAttribute('src', $this->url($media, $zone, null, $media->format));
        } elseif (null !== ImageFrame::region($image->getAttribute('src'))) {
            // A zone the address named and that was not kept (outside the file,
            // or the whole of it): never asked of the library, which would refuse it.
            $image->setAttribute('src', $this->url($media, null, null, $media->format));
        }

        $widths = $this->widthsFor($zone['w'] ?? $media->width);
        $sizes = $attributes['sizes'] ?? null;

        $this->wrapInPicture($document, $image, $media, $zone, $widths, $sizes);
    }

    /**
     * Gives the `<source>` of a `<picture>` that an author wrote the widths its
     * `<img>` has: a mobile picture served at the size of its file is as heavy
     * as the desktop one.
     *
     * Only a source that is one address of the library, for a media query, and
     * with no `type`, is touched: it is another file than the `<img>`, so it is
     * measured on its own, but the slot it is drawn at is the one of the
     * `<img>`, whose `sizes` it takes. The zone the `<img>` keeps is not
     * carried over, and no WebP alternative is added: a `<picture>` that
     * already exists is the author's, as `wrapInPicture()` leaves it.
     */
    private function rewriteSources(\DOMElement $picture): void
    {
        $image = $picture->getElementsByTagName('img')->item(0);

        if (!$image instanceof \DOMElement) {
            return;
        }

        foreach (iterator_to_array($picture->getElementsByTagName('source')) as $source) {
            $address = trim($source->getAttribute('srcset'));

            if ('' === trim($source->getAttribute('media')) || $source->hasAttribute('type') || '' === $address || 1 === preg_match('/\s/', $address) || null !== ImageFrame::region($address)) {
                continue;
            }

            $media = $this->library->fromUrl($address);

            if (null === $media) {
                continue;
            }

            $attributes = $this->attributes($media, $this->declaredSizes($image), $this->declaredHeight($image));

            if (!isset($attributes['srcset'])) {
                continue;
            }

            $source->setAttribute('srcset', $attributes['srcset']);
            $source->setAttribute('sizes', $image->getAttribute('sizes') ?: $attributes['sizes']);
        }
    }

    /**
     * An SVG is served as the file it is: no resized copy, no other format. It
     * keeps its own address, its intrinsic size, and neither `srcset` nor
     * `<picture>`, whose candidates the library would refuse.
     */
    private function rewriteVector(\DOMElement $image, MediaFile $media): void
    {
        $image->setAttribute('src', $this->url($media, null, null, $media->format));
        $image->removeAttribute('srcset');
        $image->removeAttribute('sizes');

        foreach ($this->attributes($media) as $name => $value) {
            $image->setAttribute($name, $value);
        }
    }

    /**
     * The attributes of an image of the library that make it responsive: the
     * intrinsic size, the set of widths and what they are chosen by. What
     * `rewrite()` writes on every image of a page, for a template that draws
     * an image itself, a card of a list for one, and has no HTML to hand over.
     *
     * @param string|null                                $sizes  the `sizes` the image is drawn at, in the form of `data-cms-sizes`; invalid or none, the viewport up to the width of the file
     * @param string|null                                $height the fixed height it is drawn at, in the form of `data-cms-height`
     * @param array{x: int, y: int, w: int, h: int}|null $zone   the part of the file that is served, in its pixels; the candidates and the intrinsic size are then those of the zone
     *
     * @return array<string, string> `width` and `height` when the library knows them, `srcset` and `sizes` when it knows the width (neither for an SVG, served as it is)
     */
    public function attributes(MediaFile $media, ?string $sizes = null, ?string $height = null, ?array $zone = null): array
    {
        $attributes = [];

        if ($media->isVector()) {
            // Served whole whatever the zone or the widths: only its size is known to the page.
            return null !== $media->width && null !== $media->height
                ? ['width' => (string) $media->width, 'height' => (string) $media->height]
                : [];
        }

        if (null !== $zone) {
            $attributes['width'] = (string) $zone['w'];
            $attributes['height'] = (string) $zone['h'];
        } elseif (null !== $media->width && null !== $media->height) {
            $attributes['width'] = (string) $media->width;
            $attributes['height'] = (string) $media->height;
        }

        $widths = $this->widthsFor($zone['w'] ?? $media->width);

        if ([] !== $widths) {
            $attributes['srcset'] = $this->srcset($media, $zone, $widths, $media->format);
            $attributes['sizes'] = $this->sizes(
                $widths,
                null !== $sizes && ImageSlot::isValid($sizes) ? trim($sizes) : null,
                null !== $height && null === $zone && ImageSlot::isValidHeight($height) ? trim($height) : null,
                $media,
            );
        }

        return $attributes;
    }

    /**
     * The `sizes` the section of the image declared, on the nearest element
     * that carries the attribute. A value that is not a list of widths counts
     * as no declaration, and the elements further up are not asked: the
     * nearest one is the one that knows.
     */
    private function declaredSizes(\DOMElement $image): ?string
    {
        return $this->nearestDeclaration($image, ImageSlot::ATTRIBUTE, ImageSlot::isValid(...));
    }

    /**
     * The part of the file to serve, in the pixels of the file. Null when there
     * is none, when the library does not know the size of the file, or when the
     * part is the whole of it: the image is then served as it is.
     *
     * Two ways to ask for one. The section of the module says what the editor
     * chose with `data-cms-crop`, and the zone is computed here, where the size
     * of the file is known; it is read only when it is what the section could
     * have written. A component that prints its own address (a component of
     * the theme, one made from a brief) is given the zone already computed, as
     * the region of the address: it is kept when it lies inside the file, and
     * ignored otherwise (`full`), for an address is whatever an author typed.
     *
     * @return array{x: int, y: int, w: int, h: int}|null
     */
    private function declaredZone(\DOMElement $image, MediaFile $media): ?array
    {
        if (null === $media->width || null === $media->height) {
            return null;
        }

        $crop = ImageFrame::parse($image->getAttribute(ImageFrame::ATTRIBUTE));

        if (null !== $crop) {
            return ImageFrame::zone($media->width, $media->height, $crop['boxWidth'], $crop['boxHeight'], $crop['focusX'], $crop['focusY'], $crop['zoom']);
        }

        $region = ImageFrame::region($image->getAttribute('src'));

        if (null === $region || $region['w'] < 1 || $region['h'] < 1 || $region['x'] + $region['w'] > $media->width || $region['y'] + $region['h'] > $media->height) {
            return null;
        }

        $whole = 0 === $region['x'] && 0 === $region['y'] && $region['w'] === $media->width && $region['h'] === $media->height;

        return $whole ? null : $region;
    }

    /**
     * The fixed height a section draws its images at, read the same way.
     */
    private function declaredHeight(\DOMElement $image): ?string
    {
        return $this->nearestDeclaration($image, ImageSlot::HEIGHT_ATTRIBUTE, ImageSlot::isValidHeight(...));
    }

    /**
     * @param \Closure(string): bool $isValid
     */
    private function nearestDeclaration(\DOMElement $image, string $attribute, \Closure $isValid): ?string
    {
        for ($node = $image; $node instanceof \DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute($attribute)) {
                $declared = trim($node->getAttribute($attribute));

                return $isValid($declared) ? $declared : null;
            }
        }

        return null;
    }

    /**
     * What a section says it draws the image at; else, the viewport up to the
     * width of the file. With the slot wider than the file, a browser reads a
     * 300px file at the width of the viewport: an image a theme leaves at its
     * own size (`width: auto`) comes out stretched and blurred. The candidate
     * it picks does not change, the file being the largest one offered.
     *
     * @param non-empty-list<int> $widths ascending, the last one being the original width
     */
    private function sizes(array $widths, ?string $declared, ?string $height, MediaFile $media): string
    {
        $slot = min($widths[array_key_last($widths)], self::WIDEST_SLOT);
        $sizes = $declared ?? \sprintf('(max-width: %1$dpx) 100vw, %1$dpx', $slot);

        // Drawn at a fixed height, an image is as wide as its proportions say,
        // and no wider than the slot it sits in.
        if (null !== $height && null !== $media->width && null !== $media->height && $media->height > 0) {
            return ImageSlot::bandedTo($sizes, $height, $media->width / $media->height);
        }

        return $sizes;
    }

    /**
     * Wraps the image in a `<picture>` offering a WebP alternative.
     *
     * AVIF is not offered: TheliaLibrary only encodes jpg, png, gif, jp2 and
     * webp. Adding it there is worth an upstream change, not a workaround here.
     *
     * @param array{x: int, y: int, w: int, h: int}|null $zone
     * @param list<int>                                  $widths
     */
    private function wrapInPicture(\DOMDocument $document, \DOMElement $image, MediaFile $media, ?array $zone, array $widths, ?string $sizes): void
    {
        $parent = $image->parentNode;

        if (!$parent instanceof \DOMNode || $this->isInsideAPicture($image)) {
            return;
        }

        $picture = $document->createElement('picture');
        $source = $document->createElement('source');
        $source->setAttribute('type', 'image/webp');
        $source->setAttribute('srcset', [] !== $widths
            ? $this->srcset($media, $zone, $widths, 'webp')
            : $this->url($media, $zone, null, 'webp'));

        if (null !== $sizes) {
            $source->setAttribute('sizes', $sizes);
        }

        $parent->replaceChild($picture, $image);
        $picture->appendChild($source);
        $picture->appendChild($image);
    }

    /**
     * Whether the image already sits in a `<picture>`, at any depth.
     *
     * Not just the direct parent: `<source>` is a void element in HTML but not
     * to the parser used here, which happily adopts the `<img>` that follows it
     * as a child. Looking only one level up would miss an existing `<picture>`
     * and nest a second one inside it.
     */
    private function isInsideAPicture(\DOMElement $image): bool
    {
        for ($node = $image->parentNode; $node instanceof \DOMNode; $node = $node->parentNode) {
            if ('picture' === $node->nodeName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{x: int, y: int, w: int, h: int}|null $zone
     * @param list<int>                                  $widths ascending, the last one being the original width (that of the zone, with one)
     */
    private function srcset(MediaFile $media, ?array $zone, array $widths, string $format): string
    {
        $original = $widths[array_key_last($widths)];

        return implode(', ', array_map(
            fn (int $width): string => $this->url($media, $zone, $width === $original ? null : $width, $format).' '.$width.'w',
            $widths,
        ));
    }

    /**
     * A candidate is asked at its width and at the height its proportions
     * give. The image route reads a lone width (`480,`) as 480 by 480, and
     * `480,!` as the largest image that fits in that square: a portrait came
     * back 480 high and narrower, though announced as `480w`. Without a known
     * height, the square is kept, which is right for a landscape image only.
     *
     * A zone is asked by its pixels, as `x,y,w,h`, and a candidate of it at
     * both its numbers, never `!`: that is an exact box of the proportions of
     * the zone, which no browser sees as a different picture, and the zone at
     * its own size is `max`, never an upscaled one.
     *
     * @param array{x: int, y: int, w: int, h: int}|null $zone
     */
    private function url(MediaFile $media, ?array $zone, ?int $width, string $format): string
    {
        if (null !== $zone) {
            $size = null === $width ? 'max' : $width.','.max(1, (int) round($width * $zone['h'] / $zone['w']));

            return \sprintf('/image-library/%d/%d,%d,%d,%d/%s/0/default.%s', $media->id, $zone['x'], $zone['y'], $zone['w'], $zone['h'], $size, $format);
        }

        $size = match (true) {
            null === $width => 'max',
            null !== $media->width && $media->width > 0 && null !== $media->height && $media->height > 0 => $width.','.max(1, (int) round($width * $media->height / $media->width)),
            default => $width.',!',
        };

        return '/image-library/'.$media->id.'/full/'.$size.'/0/default.'.$format;
    }

    /**
     * The widths below the original, then the original itself: a width equal
     * to a step is offered once, as the original.
     *
     * @return list<int>
     */
    private function widthsFor(?int $intrinsicWidth): array
    {
        if (null === $intrinsicWidth || $intrinsicWidth <= 0) {
            return [];
        }

        $smaller = array_filter(self::WIDTHS, static fn (int $width): bool => $width < $intrinsicWidth);

        return [...$smaller, $intrinsicWidth];
    }

    private function innerHtml(\DOMDocument $document, \DOMElement $root): string
    {
        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        // DOMDocument closes `<source>`, which has no closing tag in HTML.
        return preg_replace('#</source>#i', '', $html) ?? $html;
    }
}
