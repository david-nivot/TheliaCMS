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

namespace TheliaCMS\Media\Svg\Optimization;

/**
 * Removes what an Illustrator export writes on the root and nothing reads:
 * `x` and `y` place an `svg` inside another one and do nothing on the root,
 * whether the file is shown as an image or written in a page; `xml:space`
 * only tells how the spaces of a text are kept, so it goes only from a
 * drawing with no text.
 *
 * A page does not keep these attributes: left in, they would stop a drawing
 * that is otherwise whole from becoming an icon.
 */
final readonly class RootPlacementRemover
{
    private const string XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

    private const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    public function remove(\DOMDocument $document): bool
    {
        $root = $document->documentElement;

        if (!$root instanceof \DOMElement || 'svg' !== $root->localName) {
            return false;
        }

        $changed = false;

        foreach (['x', 'y'] as $attribute) {
            if ($root->hasAttribute($attribute)) {
                $root->removeAttribute($attribute);
                $changed = true;
            }
        }

        if ($root->hasAttributeNS(self::XML_NAMESPACE, 'space') && !$this->holdsText($document)) {
            $root->removeAttributeNS(self::XML_NAMESPACE, 'space');
            $changed = true;
        }

        return $changed;
    }

    private function holdsText(\DOMDocument $document): bool
    {
        foreach (['text', 'tspan', 'textPath'] as $element) {
            if ($document->getElementsByTagNameNS(self::SVG_NAMESPACE, $element)->length > 0 || $document->getElementsByTagName($element)->length > 0) {
                return true;
            }
        }

        return false;
    }
}
