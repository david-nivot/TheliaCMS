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
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Exception\AiGuardrailBlockedException;
use Thelia\Domain\Ai\Exception\AiJurisdictionRefusedException;
use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use Thelia\Domain\Ai\Exception\AiRateLimitExceededException;
use Thelia\Domain\Ai\Exception\InactiveAiUsageException;
use Thelia\Domain\Ai\Guardrail\GuardrailMode;
use Thelia\Domain\Ai\Guardrail\GuardrailStage;
use Thelia\Domain\Ai\Guardrail\GuardrailVerdict;
use TheliaCMS\Content\DocumentSchema;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Front\BlockStyles;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\Component\ComponentGenerator;
use TheliaCMS\Generation\Component\ComponentPrompt;
use TheliaCMS\Generation\Component\ComponentRequest;
use TheliaCMS\Generation\DocumentChecker;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationRefusedException;
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
use TheliaCMS\Generation\TranslationGraft;
use TheliaCMS\Model\CmsPageGenerationQuery;
use TheliaCMS\Skill\SkillRepository;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * What an editor reads, in the three usages, when the AI layer of the core
 * refuses or cuts a call: the sentence of the module for the cause, in the
 * language of the back office, the row in the ledger, and nothing of the text
 * the provider or the core wrote.
 */
final class GatewayRefusalsTest extends CmsIntegrationTestCase
{
    private const string PROVIDER_TEXT = 'overloaded_error: provider-side detail';

    private FakeAiGateway $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->api = new FakeAiGateway();
    }

    /**
     * @return iterable<string, array{AiExceptionInterface, class-string<GenerationException>, string}>
     */
    public static function causes(): iterable
    {
        yield 'budget' => [new AiBudgetExceededException('theliacms.x'), GenerationUnavailableException::class, 'The AI budget of the site is spent for now'];
        yield 'rate' => [new AiRateLimitExceededException('theliacms.x'), GenerationUnavailableException::class, 'Too many requests were sent'];
        yield 'jurisdiction' => [new AiJurisdictionRefusedException('anthropic-main', AiJurisdiction::OutsideEuropeanUnion), GenerationUnavailableException::class, 'only allow providers in the European Union'];
        yield 'inactive usage or no connection' => [new InactiveAiUsageException('theliacms.x'), GenerationUnavailableException::class, 'The generator is turned off, or no AI connection and model'];
        yield 'provider down' => [new AiProviderUnavailableException('anthropic-main', true, self::PROVIDER_TEXT, null, 9001), GenerationUnavailableException::class, 'The AI provider does not answer right now'];
        yield 'guardrail' => [new AiGuardrailBlockedException(new GuardrailVerdict('limits', GuardrailStage::Input, false, GuardrailMode::Block, self::PROVIDER_TEXT), 9002), GenerationRefusedException::class, 'A safety check of the site stopped this request'];
    }

    /**
     * @param class-string<GenerationException> $class
     */
    #[DataProvider('causes')]
    public function testThePageSaysWhyTheCoreRefusedAndTheLedgerHoldsTheFailure(AiExceptionInterface $cause, string $class, string $sentence): void
    {
        $page = $this->createPage('Page en panne', published: false);
        $this->api->answer($cause);

        $failure = $this->catch(fn () => $this->pages()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $this->locale()), $this->locale(), new Brief('Something'))));

        $this->assertSaid($failure, $class, $sentence);
        $row = CmsPageGenerationQuery::create()->findPk($failure->logId);
        self::assertSame(GenerationRefusedException::class === $class ? GenerationLog::STATUS_REFUSED : GenerationLog::STATUS_FAILED, $row?->getStatus());
        self::assertSame($failure->getMessage(), $row->getError(), 'The ledger holds the sentence the editor read.');
        self::assertSame($cause->logId ?? null, $row->getLogId(), 'The row of the core that logged the refusal is kept when the exception names it.');
    }

    /**
     * @param class-string<GenerationException> $class
     */
    #[DataProvider('causes')]
    public function testTheTextSaysWhyTheCoreRefused(AiExceptionInterface $cause, string $class, string $sentence): void
    {
        $this->api->answer($cause);
        $target = GenerationTarget::field('product', 42, 'Bol en grès', $this->locale());

        $failure = $this->catch(fn () => $this->texts()->generate(new TextRequest(GenerationRequest::text($target, $this->locale(), new Brief('Décris le bol.')), TextField::Description, '')));

        $this->assertSaid($failure, $class, $sentence);
        self::assertNotNull(CmsPageGenerationQuery::create()->findPk($failure->logId));
    }

    /**
     * @param class-string<GenerationException> $class
     */
    #[DataProvider('causes')]
    public function testTheComponentSaysWhyTheCoreRefused(AiExceptionInterface $cause, string $class, string $sentence): void
    {
        $this->api->answer($cause);

        $failure = $this->catch(fn () => $this->components()->generate(new ComponentRequest('Un tableau de prix.', $this->locale())));

        $this->assertSaid($failure, $class, $sentence);
        self::assertNotNull(CmsPageGenerationQuery::create()->findPk($failure->logId));
    }

    public function testAnAnswerCutForAReasonTheLayerDoesNotKnowIsAnErrorInTheLedgerForEveryUsage(): void
    {
        $page = $this->createPage('Page muette', published: false);
        $target = GenerationTarget::field('product', 42, 'Bol en grès', $this->locale());

        $this->api->streamed([], FakeAiGateway::response('', finishReason: AiFinishReason::Other));
        $this->api->answer(FakeAiGateway::response('', finishReason: AiFinishReason::Other));
        $this->api->streamed([], FakeAiGateway::response('', finishReason: AiFinishReason::Other));

        $failures = [
            $this->catch(fn () => $this->pages()->generate(GenerationRequest::fromBrief(GenerationTarget::page($page, $this->locale()), $this->locale(), new Brief('Something')))),
            $this->catch(fn () => $this->texts()->generate(new TextRequest(GenerationRequest::text($target, $this->locale(), new Brief('Décris le bol.')), TextField::Description, ''))),
            $this->catch(fn () => $this->components()->generate(new ComponentRequest('Un tableau de prix.', $this->locale()))),
        ];

        foreach ($failures as $failure) {
            self::assertSame('The AI provider stopped before it had written anything. Try again.', $failure->getMessage());
            self::assertSame(GenerationLog::STATUS_FAILED, CmsPageGenerationQuery::create()->findPk($failure->logId)?->getStatus());
        }
    }

    public function testAnEmptyAnswerSentBackForCorrectionIsRefusedInTheModuleAndNotByTheCore(): void
    {
        // The component answers nothing, is sent back, and answers nothing again.
        $this->api->streamed([], FakeAiGateway::response(''));
        $this->api->streamed([], FakeAiGateway::response(''));
        $this->api->streamed([], FakeAiGateway::response(''));

        $failure = $this->catch(fn () => $this->components()->generate(new ComponentRequest('Un tableau de prix.', $this->locale())));

        self::assertSame(\TheliaCMS\Generation\InvalidGenerationException::class, $failure::class, 'A refused answer, with the module wording and a row in the ledger, not an exception of the core.');
        self::assertSame(GenerationLog::STATUS_INVALID, CmsPageGenerationQuery::create()->findPk($failure->logId)?->getStatus());
    }

    public function testTheRequestsOfOneCallShareACorrelationIdAndAnotherCallHasItsOwn(): void
    {
        $page = $this->createPage('Nos services', published: false);
        $request = GenerationRequest::fromBrief(GenerationTarget::page($page, $this->locale()), $this->locale(), new Brief('Une page de services.'));
        $document = json_encode(['version' => 1, 'sections' => [['id' => 'hero', 'component' => 'cms:richtext', 'props' => json_encode(['html' => '<h2>Nos services</h2>'])]]], \JSON_THROW_ON_ERROR);

        $this->api
            ->streamed([], FakeAiGateway::response('', toolCalls: [new AiToolCall('toolu_1', 'search_pages', ['query' => 'tarif'])]))
            ->streamed([], FakeAiGateway::response('{"not":"a document"'))
            ->streamed([], FakeAiGateway::response($document))
            ->streamed([], FakeAiGateway::response($document));

        $this->pages()->generate($request);
        $this->pages()->generate($request);

        $ids = array_map(static fn (AiRequest $sent): ?string => $sent->correlationId, $this->api->requests);

        self::assertCount(4, $ids);
        self::assertSame(1, \count(array_unique(\array_slice($ids, 0, 3))), 'The tool turn and the correction belong to the same task.');
        self::assertMatchesRegularExpression('/^theliacms\.page\.'.$page->getId().'\.[0-9a-f]{8}$/', (string) $ids[0]);
        self::assertNotSame($ids[0], $ids[3], 'The next task on the same page is another one.');
    }

    public function testTheTextAndTheComponentHaveTheirOwnCorrelationIds(): void
    {
        $this->api->answer(FakeAiGateway::response(json_encode(['html' => '<p>Un bol.</p>'], \JSON_THROW_ON_ERROR)));
        $this->api->answer(new InactiveAiUsageException('theliacms.component'));
        $target = GenerationTarget::field('product', 42, 'Bol en grès', $this->locale());

        $this->texts()->generate(new TextRequest(GenerationRequest::text($target, $this->locale(), new Brief('Décris le bol.')), TextField::Description, ''));
        $this->catch(fn () => $this->components()->generate(new ComponentRequest('Un tableau de prix.', $this->locale())));

        self::assertMatchesRegularExpression('/^theliacms\.text\.product\.42\.[0-9a-f]{8}$/', (string) $this->api->requests[0]->correlationId);
        self::assertMatchesRegularExpression('/^theliacms\.component\.0\.[0-9a-f]{8}$/', (string) $this->api->requests[1]->correlationId);
    }

    /**
     * @param class-string<GenerationException> $class
     */
    private function assertSaid(GenerationException $failure, string $class, string $sentence): void
    {
        self::assertSame($class, $failure::class);
        self::assertStringContainsString($sentence, $failure->getMessage());
        self::assertStringNotContainsString('provider-side', $failure->getMessage(), 'Nothing the provider said reaches an editor.');
        self::assertStringNotContainsString('AI gateway', $failure->getMessage());
        self::assertNotNull($failure->logId);
    }

    private function catch(\Closure $generation): GenerationException
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
            new NullLogger(),
            $this->failures(),
            $this->getService(TranslationGraft::class),
            $this->getService(DocumentChecker::class),
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
            new NullLogger(),
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
            new NullLogger(),
            $this->failures(),
            new SiteComponentUsage(),
        );
    }
}
