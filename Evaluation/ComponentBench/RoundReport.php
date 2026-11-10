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
 * The table of a round: per case the trials, the outcomes, the trials that kept
 * the contract, the corrections and the mean cost; the cases of a known gap
 * apart; the totals. Pure: it reads the trials, judged, it was given.
 */
#[Exclude]
final class RoundReport
{
    /**
     * @param array<string, BenchCase>   $cases
     * @param list<array<string, mixed>> $trials each with its `verdict`
     */
    public static function markdown(array $cases, array $trials): string
    {
        $byCase = [];

        foreach ($trials as $trial) {
            $byCase[(string) $trial['case']][] = $trial;
        }

        $lines = [];
        $gaps = [];

        foreach ($cases as $case) {
            if (!isset($byCase[$case->id])) {
                continue;
            }

            if (null !== $case->gap) {
                $gaps[] = [$case, $byCase[$case->id]];

                continue;
            }

            $lines[] = self::row($case, $byCase[$case->id]);
        }

        $out = [...self::header(), ...$lines];

        if ([] !== $gaps) {
            $out[] = '';
            $out[] = 'Cases of a known gap (counted apart):';
            $out[] = '';
            array_push($out, ...self::header());

            foreach ($gaps as [$case, $caseTrials]) {
                $out[] = self::row($case, $caseTrials, true);
            }
        }

        $all = array_merge(...array_values($byCase) ?: [[]]);
        $passed = \count(array_filter($all, static fn (array $trial): bool => true === ($trial['verdict']['pass'] ?? false)));
        $ordinary = array_filter($all, static fn (array $trial): bool => null === ($cases[(string) $trial['case']]->gap ?? null));
        $ordinaryPassed = \count(array_filter($ordinary, static fn (array $trial): bool => true === ($trial['verdict']['pass'] ?? false)));
        $out[] = '';
        $out[] = \sprintf(
            '%d trials, %d kept the contract (%d of %d outside the cases of a known gap). Cost %.2f USD, duration %.1f min.',
            \count($all),
            $passed,
            $ordinaryPassed,
            \count($ordinary),
            array_sum(array_map(static fn (array $trial): float => (float) ($trial['cost_usd'] ?? 0), $all)),
            array_sum(array_map(static fn (array $trial): int => (int) ($trial['duration_ms'] ?? 0), $all)) / 60000,
        );

        return implode("\n", $out);
    }

    /**
     * The trials that did not keep the contract, with why, for the console.
     *
     * @param list<array<string, mixed>> $trials
     */
    public static function failures(array $trials): string
    {
        $lines = [];

        foreach ($trials as $trial) {
            if (true === ($trial['verdict']['pass'] ?? false)) {
                continue;
            }

            $lines[] = \sprintf('- %s #%s: %s', $trial['case'], $trial['run'] ?? '?', implode(' ', (array) ($trial['verdict']['problems'] ?? [])) ?: 'no verdict');
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function header(): array
    {
        return [
            '| Case | Trials | Outcomes | Kept the contract | Corrections | Copied a target | Mean cost USD |',
            '|---|---|---|---|---|---|---|',
        ];
    }

    /**
     * @param list<array<string, mixed>> $trials
     */
    private static function row(BenchCase $case, array $trials, bool $gap = false): string
    {
        $outcomes = [];
        $corrections = 0;
        $passed = 0;
        $based = 0;
        $basedInTarget = 0;

        foreach ($trials as $trial) {
            $label = null !== ($trial['error'] ?? null) ? 'error' : (null !== ($trial['based_on'] ?? null) ? 'copy' : (string) ($trial['outcome'] ?? '?'));
            $outcomes[$label] = ($outcomes[$label] ?? 0) + 1;
            $corrections += (int) ($trial['corrections'] ?? 0);
            $passed += true === ($trial['verdict']['pass'] ?? false) ? 1 : 0;

            if (null !== ($trial['based_on'] ?? null)) {
                ++$based;
                $basedInTarget += \in_array($trial['based_on'], $case->target, true) ? 1 : 0;
            }
        }

        ksort($outcomes);

        return \sprintf(
            '| %s | %d | %s | %d/%d | %d | %s | %.3f |',
            $case->id.($gap ? ' ('.$case->gap.')' : ''),
            \count($trials),
            implode(', ', array_map(static fn (string $label, int $count): string => $count.' '.$label, array_keys($outcomes), $outcomes)),
            $passed,
            \count($trials),
            $corrections,
            0 === $based ? '-' : \sprintf('%d/%d', $basedInTarget, $based),
            array_sum(array_map(static fn (array $trial): float => (float) ($trial['cost_usd'] ?? 0), $trials)) / \count($trials),
        );
    }
}
