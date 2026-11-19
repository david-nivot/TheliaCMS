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

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Holds each value of an inline SVG to the form `InlineSvgPolicy` gives its
 * attribute: a paint is a color or a reference to an element of the same SVG,
 * never a `url(…)` that would load a file; a transform is a list of
 * transforms; the `href` of a gradient points inside the SVG, while that of a
 * link is left to the link rules.
 */
final readonly class SvgAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['fill', 'stroke', 'transform', 'xmlns', 'mask', 'clip-path', 'gradienttransform', 'stop-color', 'href', 'shape-rendering'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return InlineSvgPolicy::acceptsValue($element, $attribute, $value) ? trim($value) : null;
    }
}
