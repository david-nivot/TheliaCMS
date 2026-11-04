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

namespace TheliaCMS\Tests\Integration\Generation;

use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Catalogue\Admin\ItemContentWriter;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Generation\Admin\TranslationSources;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\DocumentChecker;
use TheliaCMS\Generation\GenerateCommand;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationTargets;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\PromptBuilder;
use TheliaCMS\Generation\Tool\GeneratorTools;
use TheliaCMS\Generation\Tool\MakeComponentTool;
use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Generation\TranslationGraft;
use TheliaCMS\Http\ConsoleRequest;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The command writes an item of the catalogue as well as a page, a language
 * from another one, and at an effort of its own: what the evaluation of the
 * generator runs without a browser.
 */
final class GenerateCommandTest extends CmsIntegrationTestCase
{
    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
    }

    public function testTheDocumentOfAnItemIsWrittenAtTheEffortAskedAndSavedOnlyWithoutDryRun(): void
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale($this->locale())->setTitle('Vernis mat pour parquet')->save();
        $id = (int) $product->getId();
        $this->answerWith('<h2>Le vernis</h2><p>Mat, pour parquet.</p>');
        $this->answerWith('<h2>Le vernis</h2><p>Mat, pour parquet.</p>');

        $dryRun = $this->generate(['--item' => 'product:'.$id, '--locale' => $this->locale(), '--brief' => 'Présente le vernis.', '--effort' => 'medium', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $dryRun->getStatusCode(), $dryRun->getDisplay());
        self::assertStringContainsString('Mat, pour parquet.', $dryRun->getDisplay());
        self::assertMatchesRegularExpression('/^\/\/ .*\d+ turn\(s\).*USD, [\d.]+ s$/m', $dryRun->getDisplay(), 'The cost of the run stays on one line, where compare-runs reads it.');
        self::assertSame('medium', $this->api->requests[0]->providerOptions['anthropic']['effort'] ?? null);
        self::assertStringContainsString('Vernis mat pour parquet', $this->api->requests[0]->messages[0]->content, 'The generator is told which item it writes for.');
        self::assertNull($this->getService(ItemContentRepository::class)->draftDocument(CatalogueItemType::Product, $id, $this->locale()), 'A dry run saves nothing.');

        $saved = $this->generate(['--item' => 'product:'.$id, '--locale' => $this->locale(), '--brief' => 'Présente le vernis.']);

        self::assertSame(Command::SUCCESS, $saved->getStatusCode(), $saved->getDisplay());
        self::assertSame('high', $this->api->requests[1]->providerOptions['anthropic']['effort'] ?? null, 'Without --effort, the one of the settings.');
        self::assertInstanceOf(Document::class, $this->getService(ItemContentRepository::class)->draftDocument(CatalogueItemType::Product, $id, $this->locale()));
    }

    public function testALanguageIsWrittenFromTheDraftOfAnother(): void
    {
        $page = $this->createPage('Notre atelier', html: '<h1>Notre atelier</h1><p>Fondé en 1987 à Lyon.</p>');
        $target = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $this->answerWith('<h1>Our workshop</h1><p>Founded in 1987 in Lyon.</p>');

        $tester = $this->generate(['--page' => (string) $page->getId(), '--locale' => $target, '--translate-from' => $this->locale(), '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Fondé en 1987 à Lyon.', $this->api->requests[0]->messages[0]->content, 'The generator reads the document to translate.');
    }

    public function testATranslationFromALanguageHoldingNothingFails(): void
    {
        $page = $this->createPage('Page vide');

        $tester = $this->generate(['--page' => (string) $page->getId(), '--locale' => $this->locale(), '--translate-from' => 'xx_XX', '--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->api->requests, 'Nothing was sent to the API.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'a page and an item' => [['--page' => '1', '--item' => 'product:1', '--locale' => 'fr_FR', '--brief' => 'x']];
        yield 'neither' => [['--locale' => 'fr_FR', '--brief' => 'x']];
        yield 'a brief and a translation' => [['--page' => '1', '--locale' => 'fr_FR', '--brief' => 'x', '--translate-from' => 'en_US']];
        yield 'an effort the API does not know' => [['--page' => '1', '--locale' => 'fr_FR', '--brief' => 'x', '--effort' => 'extreme']];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOptions')]
    public function testOptionsThatContradictEachOtherAreRefused(array $options): void
    {
        self::assertSame(Command::INVALID, $this->generate($options)->getStatusCode());
        self::assertSame([], $this->api->requests);
    }

    public function testAnItemNamedWrongIsNotFound(): void
    {
        self::assertSame(Command::FAILURE, $this->generate(['--item' => 'brand:1', '--locale' => 'fr_FR', '--brief' => 'x'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->generate(['--item' => 'product:999999999', '--locale' => 'fr_FR', '--brief' => 'x'])->getStatusCode());
    }

    private function answerWith(string $html): void
    {
        $this->api->answer(FakeAiGateway::response(json_encode(
            ['version' => 1, 'sections' => [['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => $html]]]],
            \JSON_THROW_ON_ERROR,
        )));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function generate(array $options): CommandTester
    {
        $command = new GenerateCommand(
            new PageGenerator(
                $this->api,
                $this->getService(GeneratorSettings::class),
                $this->getService(PromptBuilder::class),
                $this->getService(GeneratorTools::class),
                $this->getService(GeneratorToolDefinitions::class),
                new ReadUrlTool(),
                $this->getService(MakeComponentTool::class),
                $this->getService(DocumentSchema::class),
                $this->getService(DocumentValidator::class),
                $this->getService(GenerationLog::class),
                new NullLogger(),
                new GatewayFailures($this->getService(TranslatorInterface::class)),
                $this->getService(TranslationGraft::class),
                $this->getService(DocumentChecker::class),
            ),
            $this->getService(CmsPageWriter::class),
            $this->getService(ConsoleRequest::class),
            $this->getService(GenerationTargets::class),
            $this->getService(CatalogueItems::class),
            $this->getService(ItemContentRepository::class),
            $this->getService(ItemContentWriter::class),
            $this->getService(TranslationSources::class),
        );

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }
}
