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
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItem;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Generation\Admin\TranslationSources;
use TheliaCMS\Http\ConsoleRequest;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\Admin\CmsPageWriter;

/**
 * Writes the draft of a page, or the document of an item of the catalogue,
 * from a brief, from the command line: what a script, a demo, a test of the
 * real API or the evaluation of the generator uses. Publishing stays a
 * separate step, through `thelia_cms:publish`.
 */
#[AsCommand(
    name: 'thelia_cms:generate',
    description: 'Writes the draft of a CMS page or the document of a catalogue item from a brief, or a language from another, with the generator.',
)]
final class GenerateCommand extends Command
{
    public function __construct(
        private readonly PageGenerator $generator,
        private readonly CmsPageWriter $writer,
        private readonly ConsoleRequest $request,
        private readonly GenerationTargets $targets,
        private readonly CatalogueItems $items,
        private readonly ItemContentRepository $itemContents,
        private readonly ContentWriter $contentWriter,
        private readonly ContentTargets $contentTargets,
        private readonly TranslationSources $translationSources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'The page to write, by id.')
            ->addOption('item', null, InputOption::VALUE_REQUIRED, 'The item of the catalogue to write the document of, as <type>:<id> (product, category, content, folder or dealer), instead of a page.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'The language of the page, such as fr_FR.')
            ->addOption('brief', null, InputOption::VALUE_REQUIRED, 'The brief, or a path to a file holding it.')
            ->addOption('revise', null, InputOption::VALUE_NONE, 'Reads the brief as a change asked on the document the page holds.')
            ->addOption('translate-from', null, InputOption::VALUE_REQUIRED, 'Writes the language from the draft the target holds in this one, such as fr_FR. Takes no brief.')
            ->addOption('effort', null, InputOption::VALUE_REQUIRED, \sprintf('The effort of this call (%s), over the one of the settings.', implode(', ', GeneratorSettings::EFFORTS)))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Prints the document and saves nothing.')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The address of the shop the links of the page point at, over the url_site setting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = (int) $input->getOption('page');
        $itemOption = (string) $input->getOption('item');
        $locale = (string) $input->getOption('locale');
        $briefOption = (string) $input->getOption('brief');
        $sourceLocale = (string) $input->getOption('translate-from');
        $effort = $input->getOption('effort');
        $url = $input->getOption('url');
        $url = \is_string($url) ? $url : null;

        if (($pageId > 0) === ('' !== $itemOption) || '' === $locale || ('' === $briefOption) === ('' === $sourceLocale)) {
            $io->error('Name the page with --page or the item with --item, the language with --locale, and either the brief with --brief or the language to write from with --translate-from.');

            return Command::INVALID;
        }

        if (null !== $effort && !\in_array($effort, GeneratorSettings::EFFORTS, true)) {
            $io->error(\sprintf('The effort is one of: %s.', implode(', ', GeneratorSettings::EFFORTS)));

            return Command::INVALID;
        }

        if ('' !== $itemOption) {
            $item = $this->item($itemOption, $locale, $url);

            if (null === $item) {
                $io->error(\sprintf('No item %s: name it as <type>:<id>, the type being one of product, category, content or folder.', $itemOption));

                return Command::FAILURE;
            }

            $page = null;
            $target = $this->targets->forItem($item);
        } else {
            $page = CmsPageQuery::create()->findPk($pageId);

            if (null === $page || null !== $page->getDeletedAt()) {
                $io->error(\sprintf('No page #%d.', $pageId));

                return Command::FAILURE;
            }

            $item = null;
            $target = $this->targets->forPage($page, $locale);
        }

        try {
            $request = $this->generationRequest($input, $target, $locale, $briefOption, $sourceLocale, $page, $item);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (\is_string($effort)) {
            $request = $request->withEffort($effort);
        }

        try {
            $result = $this->generator->generate($request);
        } catch (GenerationException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->writeln($result->document->toJson());

        if (null !== $result->fields) {
            $io->writeln(json_encode($result->fields, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        }

        if ([] !== $result->seo) {
            $io->writeln(json_encode($result->seo, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        }

        $io->newLine();
        // One line whatever the width of the terminal: `compare-runs` reads
        // the cost of a run from it, and SymfonyStyle wraps a comment at 80
        // columns when the output is not a terminal.
        $output->writeln(\sprintf(
            '<comment>// %s, %d turn(s), %d in / %d out tokens (%d cached), %.4f USD, %.1f s</comment>',
            $result->model,
            $result->turns,
            $result->usage->inputTokens,
            $result->usage->outputTokens,
            $result->usage->cacheReadTokens,
            $result->costUsd,
            $result->durationMs / 1000,
        ));

        if ([] !== $result->leftOut) {
            $io->warning(['The page leaves out part of what the brief asked:', ...$result->leftOut]);
        }

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        if (null !== $item) {
            $this->request->around(function () use ($item, $result): void {
                $this->contentWriter->saveContent($this->contentTargets->forItem($item), $result->document, $result->provenance);
            }, $url);
            $io->success(\sprintf('Draft of %s #%d saved in %s.', $item->type->value, $item->id, $locale));

            return Command::SUCCESS;
        }

        \assert($page instanceof CmsPage);
        $this->request->around(function () use ($page, $locale, $result): void {
            $this->writer->saveContent($page, $locale, $result->document, $result->provenance);

            if (null !== $result->fields && [] !== $result->fields) {
                $this->writer->saveFields($page, $locale, $result->fields);
            }

            if ([] !== $result->seo) {
                $this->writer->saveSeo($page, $locale, $result->seo);
            }
        }, $url);
        $io->success(\sprintf('Draft of page #%d saved in %s. Publish it with thelia_cms:publish --page %d.', $pageId, $locale, $pageId));

        return Command::SUCCESS;
    }

    /**
     * @throws \InvalidArgumentException when there is nothing to revise or to translate from
     */
    private function generationRequest(
        InputInterface $input,
        GenerationTarget $target,
        string $locale,
        string $briefOption,
        string $sourceLocale,
        ?CmsPage $page,
        ?CatalogueItem $item,
    ): GenerationRequest {
        if ('' !== $sourceLocale) {
            $source = null !== $item
                ? $this->translationSources->itemDocument($item->type, $item->id, $sourceLocale)
                : $this->translationSources->pageDocument((int) $page?->getId(), $sourceLocale);

            return GenerationRequest::translation($target, $locale, $source ?? new Document([]), $sourceLocale);
        }

        $brief = new Brief(is_file($briefOption) ? (string) file_get_contents($briefOption) : $briefOption);

        if (!$input->getOption('revise')) {
            return GenerationRequest::fromBrief($target, $locale, $brief);
        }

        $current = null !== $item
            ? $this->itemContents->draftDocument($item->type, $item->id, $locale)
            : Document::fromJson(CmsPageContentQuery::create()->filterByPageId((int) $page?->getId())->filterByLocale($locale)->findOne()?->getDraftDocument());

        if (null === $current) {
            throw new \InvalidArgumentException('The target holds no document to revise in this language.');
        }

        return GenerationRequest::revision($target, $locale, $current, $brief);
    }

    /**
     * Read inside a made-up request: the address of an item is built by the
     * URL service, which has none on the command line.
     */
    private function item(string $option, string $locale, ?string $url): ?CatalogueItem
    {
        if (1 !== preg_match('/^([a-z]+):(\d+)$/', $option, $match)) {
            return null;
        }

        $type = CatalogueItemType::tryFrom($match[1]);

        if (null === $type) {
            return null;
        }

        return $this->request->around(fn (): ?CatalogueItem => $this->items->find($type, (int) $match[2], $locale), $url);
    }
}
