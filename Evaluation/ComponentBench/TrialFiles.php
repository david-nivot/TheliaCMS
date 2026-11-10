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

namespace TheliaCMS\Evaluation\ComponentBench;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The files of a round: `<out>/<round>/<case>/<n>.json`, one per trial, out of
 * the repository.
 */
#[Exclude]
final class TrialFiles
{
    public static function path(string $out, string $round, string $case, int $run): string
    {
        return rtrim($out, '/').'/'.$round.'/'.$case.'/'.$run.'.json';
    }

    /**
     * Every trial file of the round, by path, in the order of the cases then of the runs.
     *
     * @return list<string>
     */
    public static function all(string $out, string $round): array
    {
        $files = glob(rtrim($out, '/').'/'.$round.'/*/*.json') ?: [];
        natsort($files);

        return array_values($files);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the file is not the JSON of a trial
     */
    public static function read(string $file): array
    {
        try {
            $trial = json_decode((string) file_get_contents($file), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(\sprintf('%s is not JSON: %s', $file, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($trial)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a trial.', $file));
        }

        return $trial;
    }

    /**
     * @param array<string, mixed> $trial
     */
    public static function write(string $file, array $trial): void
    {
        $directory = \dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create %s.', $directory));
        }

        $temporary = $file.'.tmp';
        file_put_contents($temporary, json_encode($trial, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT)."\n");
        rename($temporary, $file);
    }
}
