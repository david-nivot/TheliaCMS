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

namespace TheliaCMS\Generation;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ties the translations written before the update that follows them to the
 * draft they were written from, for the ones the ledger of the generator
 * proves current (`TranslationBackfill`). Run once after the update, on the
 * sites that translate; a dry run unless told to apply.
 */
#[AsCommand(
    name: 'thelia_cms:translations:follow',
    description: 'Ties the translations written by the generator to the draft they were written from, when the ledger proves it is still the one.',
)]
final class TranslationsFollowCommand extends Command
{
    public function __construct(
        private readonly TranslationBackfill $backfill,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Writes the links. Without it, says what would be tied and writes nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $outcomes = $this->backfill->run($apply);
        $tied = array_filter($outcomes, static fn (TranslationBackfillOutcome $outcome): bool => $outcome->isTied());
        $left = array_filter($outcomes, static fn (TranslationBackfillOutcome $outcome): bool => !$outcome->isTied());

        foreach ($tied as $outcome) {
            $io->writeln(\sprintf('%s %s in %s, from %s.', $apply ? 'Tied' : 'Would tie', $outcome->content, $outcome->locale, $outcome->sourceLocale));
        }

        foreach ($left as $outcome) {
            $io->writeln(\sprintf('<comment>Left alone</comment> %s in %s: %s', $outcome->content, $outcome->locale, $outcome->reason));
        }

        $io->newLine();
        $io->writeln(\sprintf('%d tied, %d left alone, of %d translation(s) in the ledger.', $apply ? \count($tied) : 0, \count($left), \count($outcomes)));

        if (!$apply) {
            $io->note(\sprintf('Nothing is written: %d would be tied. Run again with --apply to write the links.', \count($tied)));
        }

        return Command::SUCCESS;
    }
}
