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

namespace TheliaCMS\Generation\Component;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thelia\Model\Lang;

#[AsCommand(
    name: 'thelia_cms:components:site-heading',
    description: 'Offers each component of the site that draws its own heading a version with the heading of the site, to keep from its screen.',
)]
final class SiteHeadingUpgradeCommand extends Command
{
    public function __construct(
        private readonly SiteHeadingUpgrade $upgrade,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lists the components that would be offered a version, and writes nothing.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The language of the labels of the two new props; the default language of the shop when left out.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = !$input->getOption('dry-run');
        $locale = (string) ($input->getOption('locale') ?? Lang::getDefaultLanguage()->getLocale());
        $proposed = $this->upgrade->propose($locale, $write);

        if ([] === $proposed) {
            $io->success('No component of the site draws its own heading as the generator wrote it.');

            return Command::SUCCESS;
        }

        $io->table(['Component', 'Class of its heading, now unused', 'Version to keep'], array_map(
            static fn (array $row): array => [$row['code'], '.'.str_replace(' ', '.', $row['class']), $row['problem'] ?? (null === $row['candidate'] ? '-' : '#'.$row['candidate'])],
            $proposed,
        ));

        $offered = \count(array_filter($proposed, static fn (array $row): bool => null === $row['problem']));

        if (!$write) {
            $io->note(\sprintf('%d component(s) would be offered a version. Nothing was written.', $offered));
        } else {
            $io->success(\sprintf('%d version(s) written, each to keep from the screen of its component; no page changes before.', $offered));
        }

        return $offered === \count($proposed) ? Command::SUCCESS : Command::FAILURE;
    }
}
