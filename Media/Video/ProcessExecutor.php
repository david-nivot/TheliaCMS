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
 * Runs a command of the server and says how it ended. The one door to a
 * process of the conversion: a test hands in a double that writes the files
 * ffmpeg would have written.
 */
interface ProcessExecutor
{
    /**
     * @param list<string> $command the program and its arguments, never a shell line
     *
     * @return ProcessOutcome exit code 127 when the program could not be started
     */
    public function run(array $command, int $timeoutSeconds): ProcessOutcome;
}
