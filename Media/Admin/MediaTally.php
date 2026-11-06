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

namespace TheliaCMS\Media\Admin;

/**
 * How many images the library holds, and how many still say nothing.
 *
 * Counted over the whole library rather than over the page being shown. The
 * screen used to count the images it had loaded, which is capped: a library of
 * 1378 images announced "200 images, 122 still to describe" and the real
 * backlog, 757, was nowhere on screen. A count that is only right on small
 * libraries is worse than no count, because it is believed.
 */
final readonly class MediaTally
{
    public function __construct(
        public int $total,
        public int $undescribed,
        /** The videos among the total: they are never to describe. */
        public int $videos = 0,
    ) {
    }
}
