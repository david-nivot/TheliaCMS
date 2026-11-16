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

namespace TheliaCMS\Media\Svg\Optimization\Path;

/**
 * One command of a path and its arguments, numbers kept as written. A
 * command repeated without its letter is a segment of its own, and the
 * pairs after a moveto are linetos; an arc flag is "0" or "1".
 */
final readonly class PathSegment
{
    /**
     * @param list<string> $arguments
     */
    public function __construct(
        public string $command,
        public array $arguments,
    ) {
    }

    public function isRelative(): bool
    {
        return $this->command === strtolower($this->command);
    }
}
