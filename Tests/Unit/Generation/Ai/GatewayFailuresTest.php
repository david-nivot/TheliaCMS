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

namespace TheliaCMS\Tests\Unit\Generation\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Exception\AiGuardrailBlockedException;
use Thelia\Domain\Ai\Exception\AiJurisdictionRefusedException;
use Thelia\Domain\Ai\Exception\AiKeyDecryptionException;
use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use Thelia\Domain\Ai\Exception\AiRateLimitExceededException;
use Thelia\Domain\Ai\Exception\InactiveAiUsageException;
use Thelia\Domain\Ai\Exception\InvalidAiArgumentException;
use Thelia\Domain\Ai\Guardrail\GuardrailMode;
use Thelia\Domain\Ai\Guardrail\GuardrailStage;
use Thelia\Domain\Ai\Guardrail\GuardrailVerdict;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GenerationUnavailableException;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * What an editor reads when the AI layer of the core does not answer: one
 * sentence for each cause, and nothing of the provider in it. The translator
 * is the identity one, so a message is tested as written.
 */
final class GatewayFailuresTest extends TestCase
{
    #[DataProvider('causes')]
    public function testEachCauseHasItsOwnSentenceAndNoTechnicalDetail(AiExceptionInterface $exception, string $class, string $expected): void
    {
        $failure = $this->failures()->exceptionFor($exception);

        self::assertSame($class, $failure::class);
        self::assertSame($expected, $failure->getMessage());
        self::assertSame($exception, $failure->getPrevious(), 'The cause stays chained for the log.');
        self::assertStringNotContainsString('secret-provider-text', $failure->getMessage());
        self::assertStringNotContainsString('theliacms.', $failure->getMessage(), 'No identifier of the core in an editor message.');
    }

    /**
     * @return iterable<string, array{AiExceptionInterface, class-string<GenerationException>, string}>
     */
    public static function causes(): iterable
    {
        yield 'budget of the usage' => [new AiBudgetExceededException('theliacms.page_generation'), GenerationUnavailableException::class, 'The AI budget of the site is spent for now. The generator works again next month, or as soon as the budget is raised in the AI settings of Thelia.'];
        yield 'global cap' => [new AiBudgetExceededException('theliacms.text', true), GenerationUnavailableException::class, 'The AI budget of the site is spent for now. The generator works again next month, or as soon as the budget is raised in the AI settings of Thelia.'];
        yield 'rate' => [new AiRateLimitExceededException('theliacms.text', new \DateTimeImmutable('+1 minute')), GenerationUnavailableException::class, 'Too many requests were sent to the generator in a short time. Wait a few minutes and try again.'];
        yield 'jurisdiction' => [new AiJurisdictionRefusedException('anthropic-main', AiJurisdiction::OutsideEuropeanUnion), GenerationUnavailableException::class, 'The AI settings of the site only allow providers in the European Union, and the connection of the generator is not one. Choose another connection, or change that setting, in the AI settings of Thelia.'];
        yield 'inactive usage, no connection, no model' => [new InactiveAiUsageException('theliacms.component'), GenerationUnavailableException::class, 'The generator is turned off, or no AI connection and model is set up for it. Turn it on and check the connection in the AI settings of Thelia.'];
        yield 'key that cannot be read' => [new AiKeyDecryptionException('anthropic-main'), GenerationUnavailableException::class, 'The key of the AI connection cannot be read. Enter it again in the AI settings of Thelia.'];
        yield 'provider down' => [new AiProviderUnavailableException('anthropic-main', true, 'secret-provider-text overloaded_error'), GenerationUnavailableException::class, 'The AI provider does not answer right now. Try again in a few minutes.'];
        yield 'provider refusing the call' => [new AiProviderUnavailableException('anthropic-main', false, 'secret-provider-text invalid x-api-key'), GenerationUnavailableException::class, 'The AI provider does not answer right now. Try again in a few minutes.'];
        yield 'guardrail' => [new AiGuardrailBlockedException(new GuardrailVerdict('limits', GuardrailStage::Input, false, GuardrailMode::Block, 'secret-provider-text')), GenerationRefusedException::class, 'A safety check of the site stopped this request. Reword the brief and try again.'];
        yield 'a request the core cannot take' => [new InvalidAiArgumentException('secret-provider-text'), GenerationException::class, 'The generator could not reach the AI. Try again, and tell the person who looks after the site if it lasts.'];
    }

    public function testAConversationThatNeverAnswersSaysHowManyTurnsItTookAndForWhat(): void
    {
        self::assertSame('The model made more than 8 turns without answering with a document.', $this->failures()->tooManyTurns(8, text: false)->getMessage());
        self::assertSame('The model made more than 6 turns without answering with a text.', $this->failures()->tooManyTurns(6, text: true)->getMessage());
    }

    public function testTheTurnsMessagesAreTranslatedInBothLanguages(): void
    {
        foreach (['en_US', 'fr_FR'] as $locale) {
            $messages = require \dirname(__DIR__, 4).'/I18n/'.$locale.'.php';

            foreach (['The model made more than %turns% turns without answering with a document.', 'The model made more than %turns% turns without answering with a text.'] as $key) {
                self::assertArrayHasKey($key, $messages, $locale);
                self::assertStringContainsString('%turns%', $messages[$key], $locale);
            }
        }
    }

    public function testTheLogRowOfAFailedRequestIsFoundWhenTheExceptionNamesOne(): void
    {
        $failures = $this->failures();

        self::assertSame(77, $failures->logIdOf(new AiProviderUnavailableException('anthropic-main', true, '', null, 77)));
        self::assertSame(78, $failures->logIdOf(new AiGuardrailBlockedException(new GuardrailVerdict('limits', GuardrailStage::Input, false, GuardrailMode::Block, 'too long'), 78)));
        self::assertNull($failures->logIdOf(new AiBudgetExceededException('theliacms.text')));
    }

    public function testARefusalBecomesTheRefusalOfTheModule(): void
    {
        $this->expectException(GenerationRefusedException::class);
        $this->expectExceptionMessage('The model declined to write this. Reword the brief.');

        $this->failures()->assertUsable(FakeAiGateway::response('', finishReason: AiFinishReason::ContentFilter), 2000, 'ask for less.');
    }

    public function testACutAnswerNamesTheLimitAndTheAdvice(): void
    {
        try {
            $this->failures()->assertUsable(FakeAiGateway::response('{', finishReason: AiFinishReason::MaxTokens, outputTokens: 2000), 2000, 'ask for a shorter text.');
            self::fail('A cut answer must end the generation.');
        } catch (GenerationException $exception) {
            self::assertNotInstanceOf(GenerationRefusedException::class, $exception);
            self::assertSame('The answer was cut at 2000 tokens: ask for a shorter text.', $exception->getMessage());
        }
    }

    /**
     * The core holds an answer under the limit of the usage and of its model,
     * set in the AI settings of Thelia. An answer cut there was cut below what
     * the module asked: raising the limit of the module changes nothing.
     */
    public function testAnAnswerCutBelowTheLimitOfTheModuleNamesTheAiSettingsOfThelia(): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('The answer was cut at 8191 tokens, below the 48000 asked: the AI settings of Thelia hold this usage, or its model, to less. Raise the limit there, or ask for less.');

        $this->failures()->assertUsable(FakeAiGateway::response('{', finishReason: AiFinishReason::MaxTokens, outputTokens: 8191), 48000, 'raise the limit of the generator or ask for a shorter page.');
    }

    public function testACutAnswerWithoutALimitOfTheRequestSaysItWasTheLimitOfTheUsage(): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('The answer was cut at the output limit: ask for a shorter page.');

        $this->failures()->assertUsable(FakeAiGateway::response('{', finishReason: AiFinishReason::MaxTokens), null, 'ask for a shorter page.');
    }

    public function testAnUnknownReasonWithNothingToCheckEndsTheGeneration(): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('The AI provider stopped before it had written anything. Try again.');

        $this->failures()->assertUsable(FakeAiGateway::response('  ', finishReason: AiFinishReason::Other), 2000, 'ask for less.');
    }

    #[DataProvider('answersThatLetTheGenerationGoOn')]
    public function testTheOtherAnswersEndNothing(AiFinishReason $reason, string $text, bool $withToolCall): void
    {
        $response = FakeAiGateway::response($text, toolCalls: $withToolCall ? [new AiToolCall('toolu_1', 'search_pages', [])] : [], finishReason: $reason);

        $this->failures()->assertUsable($response, 2000, 'ask for less.');

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{AiFinishReason, string, bool}>
     */
    public static function answersThatLetTheGenerationGoOn(): iterable
    {
        yield 'completed' => [AiFinishReason::Completed, '{}', false];
        yield 'tool calls' => [AiFinishReason::ToolCalls, '', true];
        yield 'a reason the layer does not know, with a text to check' => [AiFinishReason::Other, '{}', false];
        yield 'a reason the layer does not know, with a tool call' => [AiFinishReason::Other, '', true];
    }

    /**
     * Every sentence the module says for a failure of the AI layer, and every
     * piece of advice a generator passes, is in both catalogues, and French is
     * not English.
     */
    public function testEverySentenceIsInBothCatalogues(): void
    {
        $root = \dirname(__DIR__, 4);
        $english = require $root.'/I18n/en_US.php';
        $french = require $root.'/I18n/fr_FR.php';
        $sources = [$root.'/Generation/Ai/GatewayFailures.php', $root.'/Generation/PageGenerator.php', $root.'/Generation/Text/TextGenerator.php', $root.'/Generation/Component/ComponentGenerator.php'];
        $messages = [];

        foreach ($sources as $source) {
            preg_match_all("/(?:say\\(|assertUsable\\([^;]*?, )'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($source), $found);
            array_push($messages, ...array_map(static fn (string $message): string => stripslashes($message), $found[1]));
        }

        self::assertGreaterThanOrEqual(15, \count(array_unique($messages)), 'The sentences are found in the sources.');

        foreach (array_unique($messages) as $message) {
            self::assertArrayHasKey($message, $english, 'In English: '.$message);
            self::assertArrayHasKey($message, $french, 'In French: '.$message);
            self::assertNotSame($message, $french[$message], 'Translated: '.$message);
        }
    }

    private function failures(): GatewayFailures
    {
        return new GatewayFailures(new IdentityTranslator());
    }
}
