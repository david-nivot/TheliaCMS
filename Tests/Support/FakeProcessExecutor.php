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

use TheliaCMS\Media\Video\ProcessExecutor;
use TheliaCMS\Media\Video\ProcessOutcome;

/**
 * Stands in for ffmpeg: answers `-version`, and writes the file a conversion
 * or a poster would have written at the last argument of the command, so the
 * handler goes through its real path with no encoder.
 */
final class FakeProcessExecutor implements ProcessExecutor
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(
        public bool $ffmpegPresent = true,
        public bool $conversionSucceeds = true,
        public string $conversionError = '',
        public bool $posterSucceeds = true,
        public bool $writesAnEmptyConversion = false,
    ) {
    }

    public function run(array $command, int $timeoutSeconds): ProcessOutcome
    {
        $this->commands[] = $command;

        if ('-version' === ($command[1] ?? null)) {
            return $this->ffmpegPresent ? new ProcessOutcome(0) : new ProcessOutcome(127, 'not found');
        }

        $target = (string) end($command);

        if (str_ends_with($target, '.mp4')) {
            if (!$this->conversionSucceeds) {
                return new ProcessOutcome(1, $this->conversionError);
            }

            file_put_contents($target, $this->writesAnEmptyConversion ? '' : Mp4Videos::bytes());
        }

        if (str_ends_with($target, '.jpg') && $this->posterSucceeds) {
            imagejpeg(imagecreatetruecolor(8, 8), $target);
        }

        return new ProcessOutcome(0);
    }

    /**
     * The commands that converted or took a poster, `-version` left out.
     *
     * @return list<list<string>>
     */
    public function work(): array
    {
        return array_values(array_filter($this->commands, static fn (array $command): bool => '-version' !== ($command[1] ?? null)));
    }
}
