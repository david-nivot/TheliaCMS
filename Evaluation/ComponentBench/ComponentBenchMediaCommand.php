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
 * Fills the media library with the pictures and videos of the bench, so the
 * sample of a component has something to show. Run it before a round; run
 * twice, it imports nothing the second time. Calls no API.
 */
#[AsCommand(
    name: 'thelia_cms:component-bench:media',
    description: 'Imports the pictures and videos of the bench into the media library (once each). Calls no API.',
)]
final class ComponentBenchMediaCommand extends Command
{
    public function __construct(
        private readonly BenchMediaImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'The folder of the files and of its manifest.json.', BenchMediaImporter::defaultDirectory())
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Says what would be imported, and imports nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $report = $this->importer->import((string) $input->getOption('dir'), $dryRun);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return Command::FAILURE;
        }

        $imported = 0;

        foreach ($report as $line) {
            $imported += 'already' === $line['status'] ? 0 : 1;
            $output->writeln(\sprintf(
                '%s: %s%s',
                $line['file'],
                match ($line['status']) {
                    'imported' => 'imported',
                    'would-import' => 'would be imported',
                    'already' => 'already in the library',
                },
                null === $line['id'] ? '' : \sprintf(' (id %d)', $line['id']),
            ));
        }

        $output->writeln(\sprintf('%d %s, %d already in the library.', $imported, $dryRun ? 'to import' : 'imported', \count($report) - $imported));

        return Command::SUCCESS;
    }
}
