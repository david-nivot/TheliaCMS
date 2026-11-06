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
 * Loading and writing back the DOM the two rules work on.
 */
final readonly class SvgDocument
{
    /**
     * Null when the markup is not a plain document: a DOCTYPE or an entity
     * is never expanded here, the rules leave such a file alone.
     */
    public static function load(string $svg): ?\DOMDocument
    {
        if (str_contains($svg, '<!DOCTYPE') || str_contains($svg, '<!ENTITY')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $loaded = $document->loadXML($svg, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement instanceof \DOMElement || 'svg' !== $document->documentElement->localName) {
            return null;
        }

        return $document;
    }

    public static function serialize(\DOMDocument $document): string
    {
        $root = $document->documentElement;

        return $root instanceof \DOMElement ? (string) $document->saveXML($root) : '';
    }
}
