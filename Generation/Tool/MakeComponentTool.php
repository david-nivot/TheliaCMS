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

namespace TheliaCMS\Generation\Tool;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Thelia\Domain\Ai\AiToolDefinition;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Generation\CatalogSheet;
use TheliaCMS\Generation\Component\ComponentCandidates;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationObserverInterface;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Model\CmsComponentQuery;

/**
 * The tool that lets the generator writing a page make a component of the
 * site when the catalogue lacks one: a call of the generator of components
 * inside the call of the page, the component checked by the site, kept as a
 * prototype (in the catalogue for the pages that hold it, offered to nobody
 * else) and handed back as a sheet the model writes the page with.
 *
 * Not a GeneratorToolInterface, which every request is offered: the page
 * generator offers this one when the site allows it and the editor may make
 * components. Two components at most for one page; a page that needs more is
 * a catalogue to grow by hand.
 *
 * Stateful within one generation: the names made so far, which the page
 * generator adds to the enum of its output schema and `read_section` answers
 * for. Reset at the start of a generation and between requests.
 */
final class MakeComponentTool implements ResetInterface
{
    public const string NAME = 'make_component';

    /** How many components one page may have made. */
    public const int USES = 2;

    /** @var list<string> */
    private array $made = [];

    public function __construct(
        private readonly ComponentGenerator $generator,
        private readonly SiteComponentRepository $components,
        private readonly ComponentCandidates $candidates,
        private readonly SectionCatalog $catalog,
        private readonly CatalogSheet $sheet,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function definition(): AiToolDefinition
    {
        return new AiToolDefinition(
            self::NAME,
            \sprintf('Makes a new component of the site when no component of the catalogue can show a part of the brief. Say in one or two sentences what it shows and what varies from one use to the next (its props); the site writes and checks it, and the answer is its sheet, to use like any component of the catalogue. It is a call of its own, up to a minute: prefer a component of the catalogue whenever one is close (read_section shows them in full), and make at most %d for one page.', self::USES),
            [
                'type' => 'object',
                'properties' => ['request' => ['type' => 'string', 'description' => 'What the component shows and what varies, in the language of the brief.']],
                'required' => ['request'],
                'additionalProperties' => false,
            ],
        );
    }

    /**
     * The answer to a call: the sheet of the component made, or a sentence
     * saying why none was. Never an exception, which would end the call of
     * the page.
     *
     * @param array<string, mixed> $input
     */
    public function make(array $input, GenerationRequest $request, ?GenerationObserverInterface $observer = null): string
    {
        $text = trim((string) ($input['request'] ?? ''));

        if ('' === $text) {
            return 'Say what the component shows and what varies.';
        }

        if (\count($this->made) >= self::USES) {
            return \sprintf('No more than %d components are made for one page. Write the rest with the components of the catalogue.', self::USES);
        }

        try {
            // The text and the corrections of the nested call are told to the
            // observer of the page; its turns are not, which would count as
            // turns of the page.
            $result = $this->generator->generate(new ComponentRequest($text, $request->locale), null === $observer ? null : self::forwardingTextOf($observer));
        } catch (GenerationException $exception) {
            $this->logger->info('TheliaCMS: the component asked by the generator of pages was not made: {message}', ['message' => $exception->getMessage()]);

            return \sprintf('The component could not be made: %s Write this part with the components of the catalogue, or leave it out and name it in left_out.', $exception->getMessage());
        }

        $component = $result->component;
        $this->components->save($component, SiteComponentRepository::STATUS_DRAFT);
        $this->candidates->attach([$result->logId], (int) CmsComponentQuery::create()->findOneByCode($component->code)?->getId());

        // The catalogue of this request was built before the row existed.
        $this->catalog->reset();
        $definition = $this->catalog->find($component->name());

        if (null === $definition) {
            return \sprintf('The component "%s" was made but the catalogue cannot read it. Write this part with the components of the catalogue.', $component->name());
        }

        $this->made[] = $component->name();

        return \sprintf("The component `%s` is made and in the catalogue: use it like any other, with the props below.\n\n%s", $component->name(), $this->sheet->section($definition));
    }

    /**
     * The names of the components made during this generation.
     *
     * @return list<string>
     */
    public function made(): array
    {
        return $this->made;
    }

    public function reset(): void
    {
        $this->made = [];
    }

    private static function forwardingTextOf(GenerationObserverInterface $observer): GenerationObserverInterface
    {
        return new class($observer) implements GenerationObserverInterface {
            public function __construct(private readonly GenerationObserverInterface $observer)
            {
            }

            public function onTurn(int $turn, array $tools): void
            {
            }

            public function onText(int $characters): void
            {
                $this->observer->onText($characters);
            }

            public function onCorrection(int $round, array $problems): void
            {
                $this->observer->onCorrection($round, $problems);
            }

            public function onCut(): void
            {
                $this->observer->onCut();
            }
        };
    }
}
