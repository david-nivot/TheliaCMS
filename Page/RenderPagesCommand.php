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

namespace TheliaCMS\Page;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TheliaCMS\Http\ConsoleRequest;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\DraftRendering;

/**
 * Renders drafts again from their documents, without publishing them.
 *
 * The HTML of a draft is written when the draft is saved: a change of the
 * module or of the theme since — the classes a theme gives free text, the
 * markup of a component — reaches a page only at its next save. This brings
 * every draft up to date at once; what is online moves when the page is
 * published, here or with `thelia_cms:publish`.
 *
 * A draft holding custom code its new rendering would lose is left alone and
 * listed: the command runs with nobody's right to it.
 */
#[AsCommand(
    name: 'thelia_cms:render',
    description: 'Renders the drafts of CMS pages again from their documents, without publishing them.',
)]
final class RenderPagesCommand extends Command
{
    public function __construct(
        private readonly CmsPageWriter $writer,
        private readonly ConsoleRequest $request,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A page to render, by id. Repeatable.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every page that is not in the bin.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restricts to these locales. Defaults to every locale the page has a draft in.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address of the shop the links of a page point at, over the url_site setting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $pageIds */
        $pageIds = $input->getOption('page');
        /** @var list<string> $locales */
        $locales = $input->getOption('locale');
        $url = $input->getOption('url');

        if ([] === $pageIds && !$input->getOption('all')) {
            $io->error('Name the pages with --page, or ask for every page with --all.');

            return Command::INVALID;
        }

        $pages = $this->pages($pageIds);

        if ([] === $pages) {
            $io->warning('No page matched.');

            return Command::SUCCESS;
        }

        $rendered = 0;
        $unchanged = 0;
        $kept = [];
        $failed = [];

        foreach ($pages as $page) {
            foreach ($this->localesOf($page, $locales) as $locale) {
                try {
                    match ($this->request->around(fn (): DraftRendering => $this->writer->renderDraftAgain($page, $locale), \is_string($url) ? $url : null)) {
                        DraftRendering::Rendered => ++$rendered,
                        DraftRendering::Unchanged, DraftRendering::NoDraft, DraftRendering::ChangedMeanwhile => ++$unchanged,
                        DraftRendering::KeptForCustomCode => $kept[] = \sprintf('#%d %s: its custom code would be taken out; save it from the back office with the right to custom code', $page->getId(), $locale),
                    };
                } catch (\Throwable $throwable) {
                    $failed[] = \sprintf('#%d %s: %s', $page->getId(), $locale, $throwable->getMessage());
                }
            }
        }

        foreach (['Kept as they are' => $kept, 'Failed' => $failed] as $heading => $lines) {
            if ([] !== $lines) {
                $io->section($heading);
                $io->listing($lines);
            }
        }

        $io->success(\sprintf(
            '%d draft(s) rendered again, %d unchanged.%s',
            $rendered,
            $unchanged,
            [] === $kept && [] === $failed ? '' : \sprintf(' %d kept, %d failed.', \count($kept), \count($failed)),
        ));

        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $pageIds
     *
     * @return list<CmsPage>
     */
    private function pages(array $pageIds): array
    {
        $query = CmsPageQuery::create()->filterByDeletedAt(null, Criteria::ISNULL)->orderById();

        if ([] !== $pageIds) {
            $query->filterById(array_map('intval', $pageIds), Criteria::IN);
        }

        return array_values(iterator_to_array($query->find(), false));
    }

    /**
     * @param list<string> $wanted
     *
     * @return list<string>
     */
    private function localesOf(CmsPage $page, array $wanted): array
    {
        $query = CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByDraftDocument(null, Criteria::ISNOTNULL);

        if ([] !== $wanted) {
            $query->filterByLocale($wanted, Criteria::IN);
        }

        return array_values(array_map('strval', $query->select(['Locale'])->find()->toArray()));
    }
}
