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

namespace TheliaCMS\Tests\Support;

use TheliaCMS\Http\CachePurgerInterface;

/**
 * A shared cache that remembers the tags it was told to drop.
 */
final class RecordingCachePurger implements CachePurgerInterface
{
    /** @var list<string> */
    public array $purged = [];

    public function purge(array $tags): void
    {
        array_push($this->purged, ...$tags);
    }
}
