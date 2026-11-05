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

namespace TheliaCMS\Media;

/**
 * Whether the images of a page were described in the library, kept apart from
 * it so the accessibility check can be exercised without a database.
 */
interface MediaDescriptions
{
    /**
     * The state of every library image the addresses point at, read at once.
     *
     * @param list<string> $urls editor addresses found in a content
     *
     * @return array<string, ImageState> by address; an address the library does not own, or whose image has been deleted, is absent
     */
    public function statesOf(array $urls, string $locale): array;
}
