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

namespace TheliaCMS\Media\Icon;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\UX\Icons\Icon;

/**
 * Forgets what ux-icons kept of an icon of the module.
 *
 * ux-icons caches what its chain answered, the empty icon of an unknown name
 * included: a name given, taken away or redrawn has to leave that cache, or the
 * old answer is drawn until the cache is emptied.
 *
 * The pool is the `.ux_icons.cache` service of symfony/ux-icons (checked against
 * 2.36.1, whose `CacheIconRegistry` reads it under the key `Icon::nameToId($name)`).
 * It is private and internal to ux-icons: TheliaCMS::configureServices wires it,
 * and null stands for a site that does not have ux-icons, where nothing is cached.
 */
final readonly class IconCacheInvalidator
{
    public function __construct(private ?CacheInterface $cache)
    {
    }

    /** @param string $name the name stored, without the prefix */
    public function forget(string $name): void
    {
        if (null === $this->cache) {
            return;
        }

        $this->cache->delete(Icon::nameToId(IconName::prefixed($name)));
    }
}
