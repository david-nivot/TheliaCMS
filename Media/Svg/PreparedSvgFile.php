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

namespace TheliaCMS\Media\Svg;

/**
 * An SVG waiting on the preparation screen: the bytes as they were sent, and
 * the name of the file they came in.
 */
final readonly class PreparedSvgFile
{
    public function __construct(
        public string $token,
        public string $name,
        public string $content,
    ) {
    }
}
