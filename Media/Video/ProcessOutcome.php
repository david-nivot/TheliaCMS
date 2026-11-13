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

namespace TheliaCMS\Media\Video;

/**
 * What a command run by `ProcessExecutor` gave.
 */
final readonly class ProcessOutcome
{
    public function __construct(
        public int $exitCode,
        public string $errorOutput = '',
    ) {
    }

    public function succeeded(): bool
    {
        return 0 === $this->exitCode;
    }
}
