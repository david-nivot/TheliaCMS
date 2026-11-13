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

/**
 * Applies the contract of each case to the trials of a round, writes the
 * verdict into each file, and prints the table. Calls no API.
 *
 * With `--refresh-previews` alone, it only writes a fresh signature into the
 * `preview_url` of the trials that made a component (the address expires after
 * an hour, and a round lasts about as long), and checks nothing.
 */
#[AsCommand(
    name: 'thelia_cms:component-bench:check',
    description: 'Applies the oracle of each case to the trials of a round, writes the verdicts and prints the table. Calls no API.',
)]
final class ComponentBenchCheckCommand extends Command
{
    public function __construct(
        private readonly BenchPreviews $previews,
        private readonly ComponentOracle $oracle = new ComponentOracle(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('round', null, InputOption::VALUE_REQUIRED, 'The name of the round (required).')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'The directory the rounds are written to.', ComponentBenchRunCommand::defaultOut())
            ->addOption('cases', null, InputOption::VALUE_REQUIRED, 'The directory of the cases.', ComponentBenchRunCommand::defaultCases())
            ->addOption('markdown', null, InputOption::VALUE_NONE, 'Prints the table only, as it goes into results.md.')
            ->addOption('refresh-previews', null, InputOption::VALUE_NONE, 'Only signs the preview addresses again; checks nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $round = (string) $input->getOption('round');
        $out = (string) $input->getOption('out');

        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $round)) {
            $output->writeln('<error>Name the round with --round, such as round-0.</error>');

            return Command::INVALID;
        }

        $files = TrialFiles::all($out, $round);

        if ([] === $files) {
            $output->writeln(\sprintf('<error>No trial under %s/%s.</error>', rtrim($out, '/'), $round));

            return Command::FAILURE;
        }

        if ($input->getOption('refresh-previews')) {
            return $this->refresh($files, $output);
        }

        try {
            $cases = BenchCase::loadAll((string) $input->getOption('cases'));
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $trials = [];

        foreach ($files as $file) {
            try {
                $trial = TrialFiles::read($file);
            } catch (\InvalidArgumentException $exception) {
                $output->writeln('<comment>'.$exception->getMessage().' (left out)</comment>');

                continue;
            }

            $case = $cases[(string) ($trial['case'] ?? '')] ?? null;

            if (null === $case) {
                $output->writeln(\sprintf('<comment>%s: no case "%s" (left out).</comment>', $file, (string) ($trial['case'] ?? '')));

                continue;
            }

            $verdict = $this->oracle->judge($case, $trial);
            $trial['verdict'] = $verdict;
            TrialFiles::write($file, $trial);
            $trials[] = $trial;
        }

        $output->writeln(RoundReport::markdown($cases, $trials));

        if (!$input->getOption('markdown') && '' !== $failures = RoundReport::failures($trials)) {
            $output->writeln('');
            $output->writeln('Trials that broke the contract:');
            $output->writeln($failures);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $files
     */
    private function refresh(array $files, OutputInterface $output): int
    {
        $refreshed = 0;

        foreach ($files as $file) {
            try {
                $trial = TrialFiles::read($file);
            } catch (\InvalidArgumentException $exception) {
                $output->writeln('<comment>'.$exception->getMessage().' (left out)</comment>');

                continue;
            }

            if ('component' !== ($trial['outcome'] ?? null) || !\is_int($trial['ledger_id'] ?? null)) {
                continue;
            }

            $trial['preview_url'] = $this->previews->candidate($trial['ledger_id'], (string) ($trial['locale'] ?? 'fr_FR'));
            TrialFiles::write($file, $trial);
            ++$refreshed;
        }

        $output->writeln(\sprintf('%d preview address(es) signed again, valid one hour.', $refreshed));

        return Command::SUCCESS;
    }
}
