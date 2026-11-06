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

namespace TheliaCMS\Zone;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Copies the banner of the HeaderHighlights module into the header zone, as a
 * draft. Publishing stays the editor's step, then removing HeaderHighlights.
 * See HeaderHighlightsImporter.
 */
#[AsCommand(
    name: 'thelia_cms:import:header-highlights',
    description: 'Copies the HeaderHighlights banner into the header zone as a draft (a Highlights section, its images in the media library), in every language.',
)]
final class ImportHeaderHighlightsCommand extends Command
{
    public function __construct(
        private readonly HeaderHighlightsImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Replaces a draft the zone already holds in a language (kept as a previous draft of the content screen) instead of skipping that language.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Writes nothing (no image, no zone, no draft) and prints what would be written and every problem.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address of the shop the draft is rendered for, over the url_site setting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $url = $input->getOption('url');

        $report = $this->importer->import($dryRun, (bool) $input->getOption('replace'), \is_string($url) ? $url : null);

        if ([] !== $report->problems) {
            $io->section('To look at');
            $io->listing($report->problems);
        }

        if (!$report->found) {
            $io->success('Nothing to import: HeaderHighlights holds no banner (tables absent or empty).');

            return Command::SUCCESS;
        }

        foreach ($report->languages as $locale => $outcome) {
            $io->writeln(match ($outcome['status']) {
                HeaderHighlightsImport::WRITTEN => \sprintf('%s: %d block(s) %s.', $locale, $outcome['blocks'], $dryRun ? 'would be written as a draft' : 'written as a draft'),
                HeaderHighlightsImport::KEPT => \sprintf('%s: skipped, the zone holds a draft in this language (use --replace to replace it).', $locale),
                HeaderHighlightsImport::FAILED => \sprintf('%s: not written.', $locale),
                default => \sprintf('%s: skipped, HeaderHighlights holds no text in this language.', $locale),
            });
        }

        $io->writeln(\sprintf('Images: %d %s, %d already in the library.', $report->imagesCreated, $dryRun ? 'to copy' : 'copied', $report->imagesReused));

        if ([] !== $report->failures) {
            $io->error(['The draft was refused:', ...$report->failures]);

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run: nothing written.' : 'Draft(s) written in the header zone. Nothing is published: read them on the content screen of the zone, publish, then remove HeaderHighlights.');

        return Command::SUCCESS;
    }
}
