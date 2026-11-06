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

namespace TheliaCMS\Media\Svg\Color;

use TheliaCMS\Media\Svg\Optimization\SvgDocument;

/**
 * The color of the text a preview of the screen stands in. A drawing shown
 * in an `img` has no text around it: its `currentColor` is black unless the
 * drawing says otherwise, so the preview gives its root the color of the text
 * it would take. Only the preview: the file stored is never tinted.
 */
enum SvgPreviewTint: string
{
    public const string PARAMETER = 'tint';

    /** Dark text on a light ground. */
    case Light = 'light';

    /** Light text on a dark ground. */
    case Dark = 'dark';

    /** A colored text, such as a link. */
    case Accent = 'accent';

    public function color(): string
    {
        return match ($this) {
            self::Light => '#212529',
            self::Dark => '#f8f9fa',
            self::Accent => '#0d6efd',
        };
    }

    /** Translation key of the theliacms domain. */
    public function label(): string
    {
        return match ($this) {
            self::Light => 'On a light ground',
            self::Dark => 'On a dark ground',
            self::Accent => 'In a colored text',
        };
    }

    public function apply(string $svg): string
    {
        $document = SvgDocument::load($svg);
        $root = $document?->documentElement;

        if (null === $document || !$root instanceof \DOMElement) {
            return $svg;
        }

        $root->setAttribute('color', $this->color());

        return SvgDocument::serialize($document);
    }
}
