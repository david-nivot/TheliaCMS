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
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Http\ConsoleRequest;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\Admin\EmptyPageContentException;
use TheliaCMS\Page\Admin\HeadingStructureException;
use TheliaCMS\Page\Admin\MissingPageFieldsException;
use TheliaCMS\Page\Admin\ModelPagePublicationException;
use TheliaCMS\Page\Admin\PlaceholderPageContentException;

/**
 * Puts drafts online from the command line.
 *
 * The back office publishes one page at a time, which is the right shape for a
 * person and the wrong one for a site that has just been imported, or whose
 * content has been rewritten in bulk. Both need the same pipeline the button
 * runs: sanitiser, responsive images, search index, revision. Writing
 * `published_html` from a script skips all four, and nothing says so.
 *
 * So this asks the writer, page by page, and reports what it refused.
 *
 * `--all` means every draft of every page that is not in the bin, which is more
 * than it sounds: a page somebody is halfway through rewriting goes online in
 * the state their last save left it in. `--dry-run` lists the pairs first, and
 * runs the same refusals as a real run.
 */
#[AsCommand(
    name: 'thelia_cms:publish',
    description: 'Publishes the current draft of CMS pages, through the same pipeline as the back office.',
)]
final class PublishPagesCommand extends Command
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
            ->addOption('page', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A page to publish, by id. Repeatable.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every page that is not in the bin, drafts in progress included.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restricts to these locales. Defaults to every locale the page has a draft in.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lists what would be published, and publishes nothing.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address of the shop the links of a page point at, over the url_site setting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $pageIds */
        $pageIds = $input->getOption('page');
        /** @var list<string> $locales */
        $locales = $input->getOption('locale');
        $dryRun = (bool) $input->getOption('dry-run');
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

        $published = 0;
        $refused = [];
        $warnings = [];
        $failed = [];

        foreach ($pages as $page) {
            foreach ($this->localesOf($page, $locales) as $locale) {
                try {
                    // A dry run runs the refusals and not the writes, so the
                    // count it announces is the count a real run reaches.
                    $pageWarnings = $this->request->around(fn () => $dryRun ? $this->writer->assertPublishable($page, $locale) : $this->writer->publish($page, $locale), \is_string($url) ? $url : null);
                    ++$published;

                    if ([] !== $pageWarnings) {
                        $warnings[] = \sprintf('#%d %s: %s', $page->getId(), $locale, implode(' ', $pageWarnings));
                    }

                    if ($dryRun) {
                        $io->writeln(\sprintf('  #%d %s', $page->getId(), $locale));
                    }
                } catch (EmptyPageContentException) {
                    $refused[] = \sprintf('#%d %s: nothing to show', $page->getId(), $locale);
                } catch (PlaceholderPageContentException) {
                    $refused[] = \sprintf('#%d %s: still the sample text it was created with', $page->getId(), $locale);
                } catch (HeadingStructureException $exception) {
                    $refused[] = \sprintf('#%d %s: %s', $page->getId(), $locale, implode(' ', $exception->problems));
                } catch (ModelPagePublicationException) {
                    $refused[] = \sprintf('#%d %s: the model of its type, never published', $page->getId(), $locale);
                } catch (MissingPageFieldsException $exception) {
                    $refused[] = \sprintf('#%d %s: required fields left empty (%s)', $page->getId(), $locale, implode(', ', $exception->labels));
                } catch (InvalidDocumentException $exception) {
                    $refused[] = \sprintf('#%d %s: the draft is not a valid document (%s)', $page->getId(), $locale, $exception->getMessage());
                } catch (\Throwable $throwable) {
                    $failed[] = \sprintf('#%d %s: %s', $page->getId(), $locale, $throwable->getMessage());
                }
            }
        }

        foreach (['Refused' => $refused, 'Warnings' => $warnings, 'Failed' => $failed] as $heading => $lines) {
            if ([] !== $lines) {
                $io->section($heading);
                $io->listing($lines);
            }
        }

        $io->success(\sprintf(
            '%d page/locale pair(s) %s.%s',
            $published,
            $dryRun ? 'would be published' : 'published',
            [] === $refused && [] === $failed ? '' : \sprintf(' %d refused, %d failed.', \count($refused), \count($failed)),
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
        } else {
            // Every page means every page of the site: the models of the
            // types are demos, refused one by one otherwise.
            $query->filterByModel(0);
        }

        return array_values(iterator_to_array($query->find(), false));
    }

    /**
     * The locales a page actually has a draft in, so asking for every page does
     * not try to publish a translation nobody has written.
     *
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
