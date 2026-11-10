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

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class SymfonyProcessExecutor implements ProcessExecutor
{
    public function run(array $command, int $timeoutSeconds): ProcessOutcome
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessStartFailedException) {
            return new ProcessOutcome(127, 'The program could not be started.');
        } catch (ProcessTimedOutException) {
            return new ProcessOutcome(124, 'The program ran out of time.');
        }

        return new ProcessOutcome($process->getExitCode() ?? 1, $process->getErrorOutput());
    }
}
