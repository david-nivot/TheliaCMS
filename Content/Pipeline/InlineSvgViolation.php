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

/**
 * One thing a page would not keep of an SVG, as `InlineSvgPolicy::validate()`
 * finds it: what it is, and the name written in the file.
 */
final readonly class InlineSvgViolation
{
    public function __construct(
        public InlineSvgViolationKind $kind,
        public string $subject,
    ) {
    }
}
