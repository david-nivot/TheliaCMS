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

namespace TheliaCMS\Front;

/**
 * The script that loads the player of an embedded video when its facade is
 * clicked. Shipped with the module, so any theme gets it through the hook.
 */
final readonly class EmbedScript
{
    private const string FILE = 'assets/embed.js';

    public function path(): string
    {
        return \dirname(__DIR__).'/'.self::FILE;
    }

    /** Names the content of the file: what is served under an address is immutable. */
    public function version(): string
    {
        $path = $this->path();

        return is_file($path) ? substr((string) hash_file('xxh3', $path), 0, 12) : '0';
    }
}
