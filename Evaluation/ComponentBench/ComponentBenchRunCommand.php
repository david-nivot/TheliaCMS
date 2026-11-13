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

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thelia\Domain\Ai\AiGatewayInterface;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Model\CmsPageGenerationQuery;

/**
 * Plays the cases of the bench against the real generator: N trials per case,
 * one file per trial, nothing kept but the ledger line the generator writes.
 * The calls are paid: a trial whose file exists is not played again, and no
 * trial starts once the cost of the round has reached the budget.
 */
#[AsCommand(
    name: 'thelia_cms:component-bench:run',
    description: 'Plays the cases of the bench of the generator of components, N trials each, and writes one file per trial. Real calls, paid.',
)]
final class ComponentBenchRunCommand extends Command
{
    public const int DEFAULT_RUNS = 3;

    public const float DEFAULT_BUDGET_USD = 8.0;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ComponentBenchGenerators $generators,
        private readonly GeneratorSettings $settings,
        private readonly BenchPreviews $previews,
    ) {
        parent::__construct();
    }

    public static function defaultCases(): string
    {
        return \dirname(__DIR__, 2).'/docs/evaluation/components/cases';
    }

    public static function defaultOut(): string
    {
        return rtrim(\defined('THELIA_ROOT') ? THELIA_ROOT : (string) getcwd().'/', '/').'/var/eval/components';
    }

    protected function configure(): void
    {
        $this
            ->addOption('round', null, InputOption::VALUE_REQUIRED, 'The name of the round, such as round-0 (required).')
            ->addOption('cases', null, InputOption::VALUE_REQUIRED, 'The directory of the cases.', self::defaultCases())
            ->addOption('case', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Plays this case only (repeatable).')
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Trials per case.', (string) self::DEFAULT_RUNS)
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'The directory the rounds are written to.', self::defaultOut())
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'No trial starts once the cost of the round, the files already written included, reaches this amount.', (string) self::DEFAULT_BUDGET_USD);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $round = (string) $input->getOption('round');
        $runs = (int) $input->getOption('runs');
        $out = (string) $input->getOption('out');
        $budget = (float) $input->getOption('budget-usd');

        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $round)) {
            $output->writeln('<error>Name the round with --round (letters, digits, dots, dashes), such as round-0.</error>');

            return Command::INVALID;
        }

        if ($runs < 1) {
            $output->writeln('<error>--runs is 1 at least.</error>');

            return Command::INVALID;
        }

        try {
            $cases = BenchCase::loadAll((string) $input->getOption('cases'));
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $only = array_values(array_unique(array_map('strval', (array) $input->getOption('case'))));

        if ([] !== $only) {
            $unknown = array_diff($only, array_keys($cases));

            if ([] !== $unknown) {
                $output->writeln(\sprintf('<error>No such case: %s.</error>', implode(', ', $unknown)));

                return Command::INVALID;
            }

            $cases = array_intersect_key($cases, array_flip($only));
        }

        if ([] === $cases) {
            $output->writeln('<error>No case found.</error>');

            return Command::FAILURE;
        }

        $cumulative = 0.0;

        foreach (TrialFiles::all($out, $round) as $file) {
            try {
                $cumulative += (float) (TrialFiles::read($file)['cost_usd'] ?? 0.0);
            } catch (\InvalidArgumentException $exception) {
                $output->writeln('<comment>'.$exception->getMessage().' (not counted)</comment>');
            }
        }

        $played = 0;
        $skipped = 0;

        // One run of every case before the next run of any: a budget that
        // stops the round leaves every case with the same number of trials.
        for ($run = 1; $run <= $runs; ++$run) {
            foreach ($cases as $case) {
                $file = TrialFiles::path($out, $round, $case->id, $run);

                if (is_file($file)) {
                    ++$skipped;

                    continue;
                }

                if ($cumulative >= $budget) {
                    $output->writeln(\sprintf('<comment>Budget reached: %.2f USD spent on the round, %.2f USD allowed. %d trial(s) played, %d already there; the rest is not played. Run again with a higher --budget-usd to go on.</comment>', $cumulative, $budget, $played, $skipped));

                    return Command::SUCCESS;
                }

                $trial = $this->play($case, $round, $run);
                TrialFiles::write($file, $trial);
                $cumulative += (float) $trial['cost_usd'];
                ++$played;

                $output->writeln(\sprintf(
                    '%s #%d  %s  turns=%s  cost=%.4f USD  %.1f s  total=%.2f USD',
                    $case->id,
                    $run,
                    null === $trial['error'] ? (string) (null !== $trial['based_on'] ? 'copy' : $trial['outcome']) : 'ERROR '.mb_substr((string) $trial['error'], 0, 80),
                    $trial['turns'],
                    $trial['cost_usd'],
                    $trial['duration_ms'] / 1000,
                    $cumulative,
                ));
            }
        }

        $output->writeln(\sprintf('%d trial(s) played, %d already there, %.2f USD spent on the round.', $played, $skipped, $cumulative));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed> the file of the trial, without its verdict
     */
    private function play(BenchCase $case, string $round, int $run): array
    {
        $recorder = new RecordingAiGateway($this->gateway);
        $started = hrtime(true);
        $error = null;
        $result = null;
        $ledgerId = null;
        $cost = 0.0;

        try {
            $result = $this->generators->around($recorder)->generate(new ComponentRequest($case->request, $case->locale));
            $ledgerId = $result->logId;
            $cost = $result->costUsd;
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
            $ledgerId = $exception instanceof \TheliaCMS\Generation\GenerationException ? $exception->logId : null;
            // A failed call is paid too: the ledger holds what it cost.
            $cost = null === $ledgerId ? 0.0 : (float) CmsPageGenerationQuery::create()->findPk($ledgerId)?->getCostUsd();
        }

        $durationMs = null === $result ? (int) ((hrtime(true) - $started) / 1_000_000) : $result->durationMs;
        $component = $result?->component;

        return [
            'case' => $case->id,
            'round' => $round,
            'run' => $run,
            'module' => $this->moduleVersion(),
            'model' => null === $result ? $recorder->model() : $result->model,
            'effort' => $this->settings->effort(),
            'locale' => $case->locale,
            'request' => $case->request,
            'prompt_sha256' => $recorder->promptSha256(),
            'system' => $recorder->system(),
            'user' => $recorder->user(),
            'answers' => $recorder->answers(),
            'outcome' => $result?->outcome,
            'existing' => $result?->existing,
            'based_on' => $result?->basedOn,
            'code' => $component?->code,
            'scope_class' => $component?->scopeClass(),
            'component' => null === $component ? null : [
                'code' => $component->code,
                'label' => $component->label,
                'description' => $component->description,
                'props' => $component->props,
                'sample' => (object) $component->sample,
                'template' => $component->template,
                'css' => $component->css,
            ],
            'turns' => null === $result ? \count($recorder->answers()) : $result->turns,
            'corrections' => null === $result ? null : max(0, $result->turns - 1 - (null === $result->basedOn ? 0 : 1)),
            'cost_usd' => $cost,
            'duration_ms' => $durationMs,
            'ledger_id' => $ledgerId,
            'preview_url' => null === $result || null === $component ? null : $this->previews->candidate($result->logId, $case->locale),
            'error' => $error,
            'verdict' => null,
        ];
    }

    private function moduleVersion(): string
    {
        $xml = @simplexml_load_file(\dirname(__DIR__, 2).'/Config/module.xml');

        return false === $xml ? '' : (string) $xml->version;
    }
}
