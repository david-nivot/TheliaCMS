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
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\PublishedRendering;
use TheliaCMS\Http\ConsoleRequest;
use TheliaCMS\Model\CmsItemContentQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;

/**
 * Renders what is online again from the published documents.
 *
 * The published HTML is written when a page is published: a change of the
 * module since (the `sizes` of the images, the markup of a section) reaches a
 * page only at its next publication. This brings what is online up to date at
 * once, without publishing anything: the drafts, the kept versions, the date
 * of the publication, the activity log and the checks of a publication are left
 * as they are, and a page that renders the same is not written. The cache of a
 * page that changed is purged the way a publication purges it.
 *
 * It is the published counterpart of `thelia_cms:render`, which does the same
 * for the drafts.
 *
 * `--page` names pages; `--all` is every page and every content of the
 * catalogue (products, categories, brands, folders, contents) that is online.
 * `--dry-run` counts what would change and writes nothing.
 */
#[AsCommand(
    name: 'thelia_cms:render-published',
    description: 'Renders what is online again from the published documents, without publishing anything.',
)]
final class RenderPublishedCommand extends Command
{
    public function __construct(
        private readonly CmsPageWriter $pages,
        private readonly ContentWriter $content,
        private readonly ContentTargets $targets,
        private readonly ConsoleRequest $request,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A page to render, by id. Repeatable.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every page that is not in the bin, and every content of the catalogue, that is online.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restricts to these locales. Defaults to every locale that is online.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Counts what would change, and writes nothing.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address of the shop the links of a page point at, over the url_site setting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $pageIds */
        $pageIds = $input->getOption('page');
        /** @var list<string> $locales */
        $locales = $input->getOption('locale');
        $all = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');
        $url = $input->getOption('url');

        if ([] === $pageIds && !$all) {
            $io->error('Name the pages with --page, or ask for everything that is online with --all.');

            return Command::INVALID;
        }

        $rendered = 0;
        $unchanged = 0;
        $kept = [];
        $failed = [];

        // A page and a content of the catalogue are told apart by their label.
        $work = [];

        foreach ($this->pages($pageIds) as $page) {
            foreach ($this->pageLocales($page, $locales) as $locale) {
                $label = \sprintf('page #%d %s', $page->getId(), $locale);
                $work[] = [$label, fn (): PublishedRendering => $this->pages->renderPublishedAgain($page, $locale, !$dryRun)];
            }
        }

        if ($all) {
            foreach ($this->itemLocales($locales) as [$type, $id, $locale]) {
                $label = \sprintf('%s #%d %s', $type->value, $id, $locale);
                $work[] = [$label, fn (): PublishedRendering => $this->content->renderPublishedAgain($this->targets->forItemKey($type, $id, $locale), !$dryRun)];
            }
        }

        if ([] === $work) {
            $io->warning('Nothing online matched.');

            return Command::SUCCESS;
        }

        foreach ($work as [$label, $render]) {
            try {
                match ($this->request->around($render, \is_string($url) ? $url : null)) {
                    PublishedRendering::Rendered => $this->changed($io, $label, $rendered, $dryRun),
                    PublishedRendering::Unchanged, PublishedRendering::NotOnline => ++$unchanged,
                    PublishedRendering::KeptForCustomCode => $kept[] = \sprintf('%s: its custom code would be taken out; publish it again from the back office with the right to custom code', $label),
                };
            } catch (\Throwable $throwable) {
                $failed[] = \sprintf('%s: %s', $label, $throwable->getMessage());
            }
        }

        foreach (['Kept as they are' => $kept, 'Failed' => $failed] as $heading => $lines) {
            if ([] !== $lines) {
                $io->section($heading);
                $io->listing($lines);
            }
        }

        $io->success(\sprintf(
            '%d online version(s) %s, %d unchanged.%s',
            $rendered,
            $dryRun ? 'would change' : 'rendered again',
            $unchanged,
            [] === $kept && [] === $failed ? '' : \sprintf(' %d kept, %d failed.', \count($kept), \count($failed)),
        ));

        return [] === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    private function changed(SymfonyStyle $io, string $label, int &$rendered, bool $dryRun): void
    {
        ++$rendered;

        if ($dryRun) {
            $io->writeln('  '.$label);
        }
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

        return iterator_to_array($query->find(), false);
    }

    /**
     * The locales a page is online in.
     *
     * @param list<string> $wanted
     *
     * @return list<string>
     */
    private function pageLocales(CmsPage $page, array $wanted): array
    {
        $query = CmsPageContentQuery::create()
            ->filterByPageId($page->getId())
            ->filterByPublishedDocument(null, Criteria::ISNOTNULL);

        if ([] !== $wanted) {
            $query->filterByLocale($wanted, Criteria::IN);
        }

        return array_values(array_map('strval', $query->select(['Locale'])->find()->toArray()));
    }

    /**
     * @param list<string> $wanted
     *
     * @return list<array{0: CatalogueItemType, 1: int, 2: string}>
     */
    private function itemLocales(array $wanted): array
    {
        $query = CmsItemContentQuery::create()
            ->filterByPublishedDocument(null, Criteria::ISNOTNULL)
            ->orderByItemType()
            ->orderByItemId();

        if ([] !== $wanted) {
            $query->filterByLocale($wanted, Criteria::IN);
        }

        $rows = [];

        foreach ($query->find() as $content) {
            $type = CatalogueItemType::tryFrom((string) $content->getItemType());

            if (null !== $type) {
                $rows[] = [$type, (int) $content->getItemId(), (string) $content->getLocale()];
            }
        }

        return $rows;
    }
}
