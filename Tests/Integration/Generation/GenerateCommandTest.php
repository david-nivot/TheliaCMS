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
use TheliaCMS\Catalogue\Admin\ContentTargets;
use TheliaCMS\Catalogue\CatalogueItems;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Catalogue\ItemContentRepository;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\Editing\ContentVersions;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\TranslationFreshness;
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
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageRevisionQuery;
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

    public function testTheSearchFieldsOfAPageAreWrittenWithItsDraftAndShownInTheOutput(): void
    {
        $page = $this->createPage('Notre atelier', published: false);
        $locale = $this->locale();
        $this->api->answer(FakeAiGateway::response(json_encode(
            ['version' => 1, 'sections' => [['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => '<h2>Atelier</h2>']]], 'meta_title' => 'Notre atelier à Lyon', 'meta_description' => 'Un atelier de menuiserie.'],
            \JSON_THROW_ON_ERROR,
        )));

        $tester = $this->generate(['--page' => (string) $page->getId(), '--locale' => $locale, '--brief' => 'Présente l\'atelier.']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('{"meta_title":"Notre atelier à Lyon","meta_description":"Un atelier de menuiserie."}', $tester->getDisplay());

        $saved = CmsPageQuery::create()->findPk($page->getId())->setLocale($locale);
        self::assertSame(['Notre atelier à Lyon', 'Un atelier de menuiserie.'], [$saved->getMetaTitle(), $saved->getMetaDescription()]);
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

    public function testATranslationIsSavedInTheLanguageAndLeavesTheSourceAlone(): void
    {
        $page = $this->createPage('Notre atelier', html: '<h1>Notre atelier</h1><p>Fondé en 1987 à Lyon.</p>');
        $target = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $this->answerWith('<h1>Our workshop</h1><p>Founded in 1987 in Lyon.</p>');

        $tester = $this->generate(['--page' => (string) $page->getId(), '--locale' => $target, '--translate-from' => $this->locale()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $translated = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($target)->findOne();
        self::assertStringContainsString('Founded in 1987 in Lyon.', (string) $translated?->getDraftHtml());
        self::assertStringContainsString('Fondé en 1987 à Lyon.', (string) CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne()?->getDraftHtml(), 'The source draft is untouched.');
        self::assertSame('translation', CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->filterByLocale($target)->findOne()?->getKind());
    }

    public function testATranslationWrittenByTheCommandIsFollowedAndTheDraftItReplacesIsKept(): void
    {
        $page = $this->createPage('Notre atelier', html: '<h1>Notre atelier</h1><p>Fondé en 1987 à Lyon.</p>');
        $target = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $this->getService(CmsPageWriter::class)->saveContent($page, $target, Document::richText('<h1>Our workshop</h1><p>By hand.</p>'));
        $this->answerWith('<h1>Our workshop</h1><p>Founded in 1987 in Lyon.</p>');

        $tester = $this->generate(['--page' => (string) $page->getId(), '--locale' => $target, '--translate-from' => $this->locale()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $targets = $this->getService(ContentTargets::class);
        $link = $this->getService(TranslationFreshness::class)->link($targets->forPage($page, $target), static fn (string $locale) => $targets->forPage($page, $locale));
        self::assertSame($this->locale(), $link?->sourceLocale, 'The command records what it wrote from.');
        self::assertFalse($link->isStale());
        self::assertSame(1, CmsPageRevisionQuery::create()->filterByPageId($page->getId())->filterByLocale($target)->filterByLabel(ContentVersions::DRAFT_LABEL)->count(), 'The draft the command replaces is kept, as the screen keeps it.');
    }

    public function testTheHeaderOfAPageIsWrittenWithItsTranslationAndShownAsAJsonLine(): void
    {
        $page = $this->createPage('Notre atelier', html: '<h1>Notre atelier</h1><p>Fondé en 1987 à Lyon.</p>');
        $page->setLocale($this->locale())->setChapo('<p>Un atelier à Lyon.</p>')->setDescription('<p>Fondé en 1987.</p>')->save();
        $target = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $this->answerWith('<h1>Our workshop</h1><p>Founded in 1987 in Lyon.</p>', ['title' => 'Our workshop', 'summary' => '<p>A workshop in Lyon.</p>', 'description' => '<p>Founded in 1987.</p><script>alert(1)</script>']);

        $dryRun = $this->generate(['--page' => (string) $page->getId(), '--locale' => $target, '--translate-from' => $this->locale(), '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $dryRun->getStatusCode(), $dryRun->getDisplay());
        self::assertStringContainsString('{"title":"Our workshop","chapo":"<p>A workshop in Lyon.</p>","description":"<p>Founded in 1987.</p><script>alert(1)</script>"}', $dryRun->getDisplay(), 'The header is a JSON line, beside the others.');
        self::assertStringContainsString('# The page header in', $this->api->requests[0]->messages[0]->content);
        self::assertNull(CmsPageQuery::create()->findPk($page->getId())?->setLocale($target)->getTitle(), 'A dry run writes nothing.');
        self::assertNull(CmsPageQuery::create()->findPk($page->getId())?->getRewrittenUrl($target));

        $this->answerWith('<h1>Our workshop</h1><p>Founded in 1987 in Lyon.</p>', ['title' => 'Our workshop', 'summary' => '<p>A workshop in Lyon.</p>', 'description' => '<p>Founded in 1987.</p><script>alert(1)</script>']);
        $saved = $this->generate(['--page' => (string) $page->getId(), '--locale' => $target, '--translate-from' => $this->locale()]);

        self::assertSame(Command::SUCCESS, $saved->getStatusCode(), $saved->getDisplay());
        $held = CmsPageQuery::create()->findPk($page->getId())?->setLocale($target);
        self::assertSame(['Our workshop', '<p>A workshop in Lyon.</p>'], [$held?->getTitle(), $held->getChapo()]);
        self::assertStringNotContainsString('script', (string) $held->getDescription(), 'Nobody holds the right to custom code on the command line: it is taken out.');
        self::assertStringContainsString('<p>Founded in 1987.</p>', (string) $held->getDescription());
        self::assertSame('our-workshop', $held->getRewrittenUrl($target), 'The language had no address: it gets one from the translated title.');
        self::assertSame('Notre atelier', CmsPageQuery::create()->findPk($page->getId())?->setLocale($this->locale())->getTitle(), 'The source header is untouched.');
    }

    public function testATranslationOfAnItemAsksAndWritesNoHeader(): void
    {
        $factory = $this->createFixtureFactory();
        $content = $factory->content($factory->folder(), ['title' => 'Notre méthode', 'locale' => $this->locale()]);
        $id = (int) $content->getId();
        $target = 'fr_FR' === $this->locale() ? 'en_US' : 'fr_FR';
        $this->getService(ContentWriter::class)->saveContent($this->getService(ContentTargets::class)->forItemKey(CatalogueItemType::Content, $id, $this->locale()), Document::richText('<h2>Notre méthode</h2>'));
        $this->answerWith('<h2>Our method</h2>', ['title' => 'Our method']);

        $tester = $this->generate(['--item' => 'content:'.$id, '--locale' => $target, '--translate-from' => $this->locale()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringNotContainsString('The page header in', $this->api->requests[0]->messages[0]->content);
        self::assertStringNotContainsString('"title":"Our method"', $tester->getDisplay());
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

    /**
     * @param array<string, string> $more keys of the envelope beside the sections
     */
    private function answerWith(string $html, array $more = []): void
    {
        $this->api->answer(FakeAiGateway::response(json_encode(
            ['version' => 1, 'sections' => [['id' => 'content', 'component' => 'cms:richtext', 'props' => ['html' => $html]]], ...$more],
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
                $this->getService(\TheliaCMS\Generation\EditorValues::class),
            ),
            $this->getService(CmsPageWriter::class),
            $this->getService(ConsoleRequest::class),
            $this->getService(GenerationTargets::class),
            $this->getService(CatalogueItems::class),
            $this->getService(ItemContentRepository::class),
            $this->getService(ContentWriter::class),
            $this->getService(ContentTargets::class),
            $this->getService(TranslationSources::class),
            $this->getService(TranslationFreshness::class),
        );

        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }
}
