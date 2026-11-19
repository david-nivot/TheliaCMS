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

namespace TheliaCMS\Media\Svg\Optimization\Rounding;

/**
 * A path, polyline or polygon, and how it is drawn wherever it is drawn:
 * in place and through every `use` that references it or a group around it.
 */
final readonly class DrawnShape
{
    /**
     * @param string $attribute   `d` or `points`
     * @param float  $enlargement the strongest product of the transforms between the shape and the root
     * @param bool   $drawsEnds   a cap or a marker draws the ends or corners of its lines somewhere
     * @param bool   $ownScale    it is drawn somewhere at a scale of its own (pattern, marker, symbol, nested svg, units of the bounding box)
     */
    public function __construct(
        public \DOMElement $element,
        public string $attribute,
        public float $enlargement,
        public bool $drawsEnds,
        public bool $ownScale,
    ) {
    }
}
