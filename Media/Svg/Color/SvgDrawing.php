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

/**
 * A shape as it is drawn: where it stands in the file, or through a `<use>`,
 * which then passes on what the shape and its parents up to `$target` do not
 * set.
 */
final readonly class SvgDrawing
{
    public function __construct(
        public \DOMElement $shape,
        public ?\DOMElement $use = null,
        public ?\DOMElement $target = null,
    ) {
    }

    public function paintsAnArea(): bool
    {
        // A line has no inside: its fill paints nothing.
        return 'line' !== strtolower($this->shape->localName ?? '');
    }
}
