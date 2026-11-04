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

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use Thelia\Domain\Ai\Stream\TextDelta;
use Thelia\Model\AiUsageLog;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Front\BlockStyles;
use TheliaCMS\Generation\Admin\GenerationRunner;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationObserverInterface;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\GenerationTarget;
use TheliaCMS\Generation\GenerationUnavailableException;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\Generation\PromptBuilder;
use TheliaCMS\Generation\ShopContext;
use TheliaCMS\Generation\Text\TextField;
use TheliaCMS\Generation\Text\TextGenerator;
use TheliaCMS\Generation\Text\TextPrompt;
use TheliaCMS\Generation\Text\TextRequest;
use TheliaCMS\Generation\Text\TextSanitizer;
use TheliaCMS\Generation\Tool\GeneratorTools;
use TheliaCMS\Generation\Tool\MakeComponentTool;
use TheliaCMS\Generation\Tool\ReadUrlTool;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Skill\SkillRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;
use TheliaCMS\Tests\Unit\Content\Site\PriceTable;

/**
 * The ledger follows a call from its first request: a `running` row seen
 * while it runs, an outcome whatever happens, a call that died read back as
 * interrupted, a second call on the same page refused, a stream cut half way
 * asked again once and paid for, and the columns that say what a call needed.
 */
final class GenerationRunTrackingTest extends CmsIntegrationTestCase
{
    private FakeAiGateway $api;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
        $this->logger = new RecordingLogger();
    }

    public function testTheRowIsRunningWhileTheModelWritesAndDoneAtTheEnd(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $seen = [];
        $observer = new ObservingProbe(static function () use ($page, &$seen): void {
            $row = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne();
            $seen[] = [$row?->getStatus(), $row?->getPrompt()];
        });
        $this->api->streamed(['{"ver'], FakeAiGateway::response(self::document()));

        $result = $this->pages()->generate($this->request($page), $observer);

        self::assertNotEmpty($seen, 'The observer was told of the text.');
        self::assertSame(GenerationLog::STATUS_RUNNING, $seen[0][0], 'The row is written before the first answer, and says running.');
        self::assertStringContainsString('Une page', (string) $seen[0][1], 'It already holds what was asked.');

        $rows = CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->find();
        self::assertCount(1, $rows, 'The call completes its row, it does not write another.');
        self::assertSame($result->logId, (int) $rows[0]->getId());
        self::assertSame(GenerationLog::STATUS_DONE, $rows[0]->getStatus());
    }

    public function testTheRowIsTouchedAtEveryTurn(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $row = static fn (): ?\TheliaCMS\Model\CmsPageGeneration => CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne();
        $moments = [];
        // The row looks an hour old while the model writes: the turn that
        // follows must bring updated_at back to now.
        $observer = new ObservingProbe(
            static function () use ($page): void {
                CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->update(['UpdatedAt' => date('Y-m-d H:i:s', time() - 3600)]);
            },
            static function () use ($row, &$moments): void {
                $moments[] = [(int) $row()?->getTurns(), (string) $row()?->getModel(), (string) $row()?->getUpdatedAt('Y-m-d H:i:s')];
            },
        );
        $this->api
            ->streamed(['{"ver'], FakeAiGateway::response('', toolCalls: [new AiToolCall('toolu_1', 'search_pages', ['query' => 'x'])]))
            ->streamed([], FakeAiGateway::response(self::document()));

        $this->pages()->generate($this->request($page), $observer);

        self::assertSame([1, 'fake-model'], \array_slice($moments[0], 0, 2), 'The turn is on the row when the observer is told of it.');
        self::assertGreaterThan(date('Y-m-d H:i:s', time() - 60), $moments[0][2], 'updated_at moved at the turn.');
    }

    public function testAnUnexpectedErrorLeavesAFailedRowAndIsRethrownAsAGenerationException(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $boom = new \TypeError('boom of a tool');
        $this->api->answer($boom);

        $failure = $this->failure(fn () => $this->pages()->generate($this->request($page)));

        self::assertSame($boom, $failure->getPrevious(), 'The error is kept as the previous one.');
        self::assertStringNotContainsString('boom', $failure->getMessage(), 'The editor reads a sentence, not the error.');
        self::assertStringContainsString('unexpected error', $failure->getMessage());
        $row = CmsPageGenerationQuery::create()->findPk($failure->logId);
        self::assertSame(GenerationLog::STATUS_FAILED, $row?->getStatus());
        self::assertSame($failure->getMessage(), $row->getError());
        self::assertSame($boom, $this->logger->records[0]['context']['exception'] ?? null, 'The log holds the exception, with its trace.');
        self::assertSame('error', $this->logger->records[0]['level']);
    }

    public function testTheTextAndTheComponentLeaveAFailedRowToo(): void
    {
        $target = GenerationTarget::field('product', 42, 'Bol en grès', $this->locale());
        $this->api->answer(new \LogicException('boom'));
        $this->api->answer(new \LogicException('boom'));

        $text = $this->failure(fn () => $this->texts()->generate(new TextRequest(GenerationRequest::text($target, $this->locale(), new Brief('Décris le bol.')), TextField::Description, '')));
        $component = $this->failure(fn () => $this->components()->generate(new ComponentRequest('Un tableau de prix.', $this->locale())));

        foreach ([$text, $component] as $failure) {
            self::assertSame(GenerationLog::STATUS_FAILED, CmsPageGenerationQuery::create()->findPk($failure->logId)?->getStatus());
            self::assertStringContainsString('unexpected error', $failure->getMessage());
        }

        self::assertSame(0, CmsPageGenerationQuery::create()->filterByStatus(GenerationLog::STATUS_RUNNING)->count(), 'No row is left running.');
    }

    public function testARunningRowNotTouchedForThirtyMinutesIsReadAsInterrupted(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $target = GenerationTarget::page($page, $this->locale());
        $log = $this->getService(GenerationLog::class);
        $dead = $log->begin($this->request($page), 'prompt');
        $alive = $log->begin($this->request($page), 'prompt');
        $this->touchedAt($dead, 31 * 60);
        $this->touchedAt($alive, 29 * 60);

        $statuses = array_column(array_map(static fn ($row): array => ['id' => (int) $row->getId(), 'status' => $row->getStatus()], $log->history($target, $this->locale())), 'status', 'id');

        self::assertSame(GenerationLog::STATUS_INTERRUPTED, $statuses[$dead], 'The history writes it.');
        self::assertSame(GenerationLog::STATUS_RUNNING, $statuses[$alive], 'A call touched 29 minutes ago may be running still.');

        $another = $log->begin($this->request($page), 'prompt');
        $this->touchedAt($another, 40 * 60);
        self::assertSame(GenerationLog::STATUS_INTERRUPTED, $log->find($another, $target, $this->locale())?->getStatus(), 'find() writes it too.');
    }

    public function testTheGuardRefusesAFreshRunningCallAndLetsAStaleOnePass(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $log = $this->getService(GenerationLog::class);
        $running = $log->begin($this->request($page), 'prompt');
        $this->api->streamed([], FakeAiGateway::response(self::document()));

        $refusal = $this->failure(fn () => $this->pages()->generate($this->request($page)));

        self::assertInstanceOf(GenerationUnavailableException::class, $refusal);
        self::assertNull($refusal->logId, 'The refusal has no row of its own.');
        self::assertMatchesRegularExpression('/^A generation is already running for this page in this language \(started at \d\d:\d\d by .+\)\.$/', $refusal->getMessage());
        self::assertCount(0, $this->api->requests, 'No request reached the model.');
        self::assertSame(1, CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->count());

        $other = $this->createPage('Autre page', published: false);
        $this->api->streamed([], FakeAiGateway::response(self::document()));
        $this->pages()->generate($this->request($other));
        self::assertCount(1, $this->api->requests, 'Another page is not held up.');

        $this->touchedAt($running, 31 * 60);
        $this->pages()->generate($this->request($page));

        self::assertCount(2, $this->api->requests, 'A call that died no longer holds the page.');
        self::assertSame(GenerationLog::STATUS_INTERRUPTED, CmsPageGenerationQuery::create()->findPk($running)?->getStatus());
    }

    public function testAStreamCutHalfWayIsAskedAgainOnceAndTheCostOfTheCutIsAdded(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $cutLog = $this->coreLog('0.004000');
        $this->api
            ->cutAfter(['{"version":1,"sec'], new AiProviderUnavailableException('anthropic-main', true, 'overloaded_error', null, $cutLog))
            ->streamed([], FakeAiGateway::response(self::document(), logId: 777));
        $observer = new ObservingProbe();

        $result = $this->pages()->generate($this->request($page), $observer);

        self::assertCount(2, $this->api->requests);
        self::assertEquals($this->api->requests[0]->messages, $this->api->requests[1]->messages, 'The same conversation is sent again.');
        self::assertSame(1, $result->turns, 'A cut is not a turn.');
        self::assertSame(1, $observer->cuts, 'The screen is told.');
        self::assertSame(0.005, $result->costUsd, 'The cost of the cut request is added to that of the answer (0.004 + 0.001).');
        $row = CmsPageGenerationQuery::create()->findPk($result->logId);
        self::assertSame('0.005000', $row?->getCostUsd());
        self::assertSame([1, 0, 777], [(int) $row->getRetries(), (int) $row->getCorrections(), (int) $row->getLogId()]);
        self::assertSame(GenerationLog::STATUS_DONE, $row->getStatus());
        self::assertSame(0.005, $this->getService(GenerationLog::class)->spentSince(new \DateTimeImmutable('-1 hour')), 'The spend of the site counts it.');
    }

    public function testAStreamThatEndsWithoutItsLastEventIsAskedAgainOnce(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $this->api
            ->streamOf([new TextDelta('{"ver')])
            ->streamed([], FakeAiGateway::response(self::document()));

        $result = $this->pages()->generate($this->request($page));

        self::assertCount(2, $this->api->requests);
        self::assertSame(1, (int) CmsPageGenerationQuery::create()->findPk($result->logId)?->getRetries());
    }

    public function testASecondCutFailsWithTheCurrentMessageAndBothCutsArePaid(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $first = $this->coreLog('0.004000');
        $second = $this->coreLog('0.002000');
        $this->api
            ->cutAfter(['{"ver'], new AiProviderUnavailableException('anthropic-main', true, 'overloaded_error', null, $first))
            ->cutAfter(['{"ver'], new AiProviderUnavailableException('anthropic-main', true, 'overloaded_error', null, $second))
            ->streamed([], FakeAiGateway::response(self::document()));

        $failure = $this->failure(fn () => $this->pages()->generate($this->request($page)));

        self::assertInstanceOf(GenerationUnavailableException::class, $failure);
        self::assertStringContainsString('The AI provider does not answer right now', $failure->getMessage());
        self::assertCount(2, $this->api->requests, 'The third answer queued was never asked for.');
        $row = CmsPageGenerationQuery::create()->findPk($failure->logId);
        self::assertSame(GenerationLog::STATUS_FAILED, $row?->getStatus());
        self::assertSame('0.006000', $row->getCostUsd());
        self::assertSame(1, (int) $row->getRetries(), 'Only the cut that was asked again counts.');
        self::assertSame($second, (int) $row->getLogId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providerFailures(): iterable
    {
        yield '429 rate limit of the provider' => ['rate_limit_error'];
        yield '529 overloaded' => ['overloaded_error'];
    }

    #[DataProvider('providerFailures')]
    public function testAFailureBeforeAnyEventIsNotAskedAgainByTheModule(string $error): void
    {
        $page = $this->createPage('Nos services', published: false);
        $failedLog = $this->coreLog('0.000000');
        $this->api
            ->answer(new AiProviderUnavailableException('anthropic-main', true, $error, null, $failedLog))
            ->streamed([], FakeAiGateway::response(self::document()));

        $failure = $this->failure(fn () => $this->pages()->generate($this->request($page)));

        self::assertCount(1, $this->api->requests, 'The core retried it already: the module does not.');
        self::assertSame(0, (int) CmsPageGenerationQuery::create()->findPk($failure->logId)?->getRetries());
        self::assertSame(GenerationLog::STATUS_FAILED, CmsPageGenerationQuery::create()->findPk($failure->logId)?->getStatus());
    }

    public function testAFailureAfterEventsThatIsNotRetryableFailsAtOnceAndIsPaid(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $this->api->cutAfter(['{"ver'], new AiProviderUnavailableException('anthropic-main', false, 'invalid_request_error', null, $this->coreLog('0.003000')));

        $failure = $this->failure(fn () => $this->pages()->generate($this->request($page)));

        self::assertCount(1, $this->api->requests);
        self::assertSame('0.003000', CmsPageGenerationQuery::create()->findPk($failure->logId)?->getCostUsd());
    }

    public function testTheComponentTakesUpACutStreamOnceAsWell(): void
    {
        $this->api
            ->cutAfter(['{"code'], new AiProviderUnavailableException('anthropic-main', true, 'overloaded_error', null, $this->coreLog('0.004000')))
            ->streamed([], FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));

        $result = $this->components()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));

        self::assertCount(2, $this->api->requests);
        self::assertSame(1, $result->turns);
        self::assertSame(0.005, $result->costUsd);
        self::assertSame(1, (int) CmsPageGenerationQuery::create()->findPk($result->logId)?->getRetries());
    }

    public function testTheColumnsSayWhatThePageCallNeeded(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $unknown = json_encode(['version' => 1, 'sections' => [['id' => 'x', 'component' => 'Cms:Nothing', 'props' => '{}']]], \JSON_THROW_ON_ERROR);
        $this->api
            ->streamed([], FakeAiGateway::response('', toolCalls: [new AiToolCall('t1', 'search_pages', ['query' => 'a']), new AiToolCall('t2', 'search_pages', ['query' => 'b']), new AiToolCall('t3', 'search_products', ['query' => 'c'])]))
            ->streamed([], FakeAiGateway::response($unknown))
            ->streamed([], FakeAiGateway::response(self::document()));

        $result = $this->pages()->generate($this->request($page, effort: 'low'));

        $row = CmsPageGenerationQuery::create()->findPk($result->logId);
        self::assertSame('low', $row?->getEffort());
        self::assertSame([1, 0], [(int) $row->getCorrections(), (int) $row->getRetries()]);
        self::assertSame(['search_pages' => 2, 'search_products' => 1], GenerationLog::toolsOf($row));
    }

    public function testTheColumnsSayWhatTheTextAndTheComponentNeeded(): void
    {
        $target = GenerationTarget::field('product', 42, 'Bol en grès', $this->locale());
        $this->api
            ->answer(FakeAiGateway::response('', toolCalls: [new AiToolCall('t1', 'search_pages', ['query' => 'a'])]))
            ->answer(FakeAiGateway::response(json_encode(['html' => '<p>Un bol.</p>'], \JSON_THROW_ON_ERROR)))
            ->streamed([], FakeAiGateway::response('{"not":"a component"'))
            ->streamed([], FakeAiGateway::response(ComponentPrompt::json(PriceTable::component())));

        $text = $this->texts()->generate(new TextRequest(GenerationRequest::text($target, $this->locale(), new Brief('Décris le bol.')), TextField::Description, ''));
        $component = $this->components()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()));

        $textRow = CmsPageGenerationQuery::create()->findPk($text->logId);
        self::assertSame(['search_pages' => 1], GenerationLog::toolsOf($textRow));
        self::assertNotEmpty($textRow->getEffort());

        $componentRow = CmsPageGenerationQuery::create()->findPk($component->logId);
        self::assertSame(1, (int) $componentRow?->getCorrections());
        self::assertNotEmpty($componentRow->getEffort());
    }

    public function testASaveThatFailsAfterTheCallIsLoggedAndAnsweredNotLeftToBreakTheStream(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $this->api->streamed([], FakeAiGateway::response(self::document()));
        $runner = new GenerationRunner($this->pages(), $this->getService(TranslatorInterface::class), $this->logger);
        $save = static function (): void {
            throw new \RuntimeException('the draft cannot be saved');
        };
        $request = Request::create('/generate', 'POST', server: ['HTTP_ACCEPT' => 'text/event-stream']);

        $response = $runner->respond($request, $this->request($page), $save, '/screen');

        self::assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('event: error', $output);
        self::assertStringContainsString('unexpected error', $output);
        self::assertStringNotContainsString('cannot be saved', $output);
        self::assertSame(GenerationLog::STATUS_DONE, CmsPageGenerationQuery::create()->filterByTargetId($page->getId())->findOne()?->getStatus(), 'The row stays done: logged only.');
        self::assertSame('error', $this->logger->records[0]['level']);

        $this->api->streamed([], FakeAiGateway::response(self::document()));
        $plain = $runner->respond(Request::create('/generate', 'POST'), $this->request($this->createPage('Autre', published: false)), $save, '/screen');
        self::assertSame(302, $plain->getStatusCode(), 'Without the script, the browser is sent back to the screen.');
    }

    private function request(CmsPage $page, ?string $effort = null): GenerationRequest
    {
        $request = GenerationRequest::fromBrief(GenerationTarget::page($page, $this->locale()), $this->locale(), new Brief('Une page qui présente nos services de conseil.'));

        return null === $effort ? $request : $request->withEffort($effort);
    }

    private static function document(): string
    {
        return json_encode([
            'version' => 1,
            'sections' => [['id' => 'hero', 'component' => 'cms:richtext', 'props' => json_encode(['html' => '<h1>Nos services</h1>'], \JSON_THROW_ON_ERROR)]],
        ], \JSON_THROW_ON_ERROR);
    }

    /** A row of the usage log of the core, as its gateway writes one for a request cut half way. */
    private function coreLog(string $cost): int
    {
        $row = (new AiUsageLog())
            ->setUsageId('theliacms.page_generation')
            ->setModule('theliacms')
            ->setKind('stream')
            ->setConnectionCode('fake')
            ->setProvider('fake')
            ->setModel('fake-model')
            ->setCost($cost)
            ->setCurrency('USD')
            ->setActorType('admin')
            ->setActorHash(str_repeat('0', 64))
            ->setStatus('aborted');
        $row->save();

        return (int) $row->getId();
    }

    private function touchedAt(int $rowId, int $secondsAgo): void
    {
        CmsPageGenerationQuery::create()->filterById($rowId)->update(['UpdatedAt' => date('Y-m-d H:i:s', time() - $secondsAgo)]);
    }

    private function failure(\Closure $generation): GenerationException
    {
        try {
            $generation();
        } catch (GenerationException $exception) {
            return $exception;
        }

        self::fail('The generation should have failed.');
    }

    private function failures(): GatewayFailures
    {
        return new GatewayFailures($this->getService(TranslatorInterface::class));
    }

    private function pages(): PageGenerator
    {
        return new PageGenerator(
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
            $this->logger,
            $this->failures(),
        );
    }

    private function texts(): TextGenerator
    {
        return new TextGenerator(
            $this->api,
            $this->getService(GeneratorSettings::class),
            new TextPrompt($this->getService(SkillRepository::class), $this->getService(ShopContext::class)),
            $this->getService(GeneratorTools::class),
            new GeneratorToolDefinitions($this->getService(GeneratorTools::class)),
            new TextSanitizer(),
            $this->getService(GenerationLog::class),
            $this->logger,
            $this->failures(),
        );
    }

    private function components(): ComponentGenerator
    {
        return new ComponentGenerator(
            $this->api,
            $this->getService(GeneratorSettings::class),
            new ComponentPrompt($this->getService(SkillRepository::class), $this->getService(SectionCatalog::class), $this->getService(ShopContext::class), new BlockStyles()),
            new SiteComponentValidator(),
            $this->getService(SiteComponentRepository::class),
            $this->getService(GenerationLog::class),
            $this->logger,
            $this->failures(),
            new SiteComponentUsage(),
        );
    }
}

final class RecordingLogger extends AbstractLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class ObservingProbe implements GenerationObserverInterface
{
    public int $cuts = 0;

    /**
     * @param (\Closure(): void)|null $onText
     * @param (\Closure(): void)|null $afterTurn
     */
    public function __construct(private readonly ?\Closure $onText = null, private readonly ?\Closure $afterTurn = null)
    {
    }

    public function onTurn(int $turn, array $tools): void
    {
        $this->afterTurn?->__invoke();
    }

    public function onText(int $characters): void
    {
        $this->onText?->__invoke();
    }

    public function onCorrection(int $round, array $problems): void
    {
    }

    public function onCut(): void
    {
        ++$this->cuts;
    }
}
