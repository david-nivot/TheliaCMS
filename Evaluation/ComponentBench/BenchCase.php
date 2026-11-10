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
 * One case of the bench: a request an editor would type, and the contract a
 * machine can check on what the generator answers (docs/evaluation/components).
 */
#[Exclude]
final readonly class BenchCase
{
    /**
     * @param list<string>                                       $outcomes accepted outcomes: component, existing, copy
     * @param list<string>                                       $target   accepted names of the component to reuse or copy
     * @param list<array<string, mixed>>                         $props    lines of `{type, min?, max?, with?}`
     * @param array{require: list<string>, forbid: list<string>} $markup   regular expressions, without delimiters
     * @param array{require: list<string>, forbid: list<string>} $css      same, on the stylesheet
     */
    public function __construct(
        public string $id,
        public string $family,
        public string $locale,
        public string $request,
        public array $outcomes,
        public array $target,
        public array $props,
        public array $markup,
        public array $css,
        public ?string $gap = null,
    ) {
        foreach ([...$markup['require'], ...$markup['forbid'], ...$css['require'], ...$css['forbid']] as $pattern) {
            if (false === @preg_match(self::delimited($pattern), '')) {
                throw new \InvalidArgumentException(\sprintf('Case "%s": "%s" is not a regular expression.', $id, $pattern));
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $oracle = \is_array($data['oracle'] ?? null) ? $data['oracle'] : [];
        $list = static fn (mixed $value): array => \is_array($value) ? array_values(array_map('strval', $value)) : [];
        $patterns = static fn (mixed $value): array => ['require' => $list(\is_array($value) ? ($value['require'] ?? []) : []), 'forbid' => $list(\is_array($value) ? ($value['forbid'] ?? []) : [])];

        if (!\is_string($data['id'] ?? null) || !\is_string($data['request'] ?? null)) {
            throw new \InvalidArgumentException('A case has an id and a request.');
        }

        return new self(
            $data['id'],
            (string) ($data['family'] ?? ''),
            (string) ($data['locale'] ?? 'fr_FR'),
            $data['request'],
            $list($oracle['outcome'] ?? []),
            $list($oracle['target'] ?? []),
            array_values(array_filter(\is_array($oracle['props'] ?? null) ? $oracle['props'] : [], 'is_array')),
            $patterns($oracle['markup'] ?? []),
            $patterns($oracle['css'] ?? []),
            \is_string($data['gap'] ?? null) ? $data['gap'] : null,
        );
    }

    /**
     * Every case of a directory, by id.
     *
     * @return array<string, self>
     */
    public static function loadAll(string $directory): array
    {
        $files = glob(rtrim($directory, '/').'/*.json') ?: [];
        sort($files);
        $cases = [];

        foreach ($files as $file) {
            try {
                $case = self::fromArray((array) json_decode((string) file_get_contents($file), true, 16, \JSON_THROW_ON_ERROR));
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException(\sprintf('%s is not JSON: %s', $file, $exception->getMessage()), 0, $exception);
            }

            $cases[$case->id] = $case;
        }

        return $cases;
    }

    /**
     * A pattern of a case as PCRE reads it: no delimiters in the files,
     * case-insensitive, UTF-8.
     */
    public static function delimited(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }
}
