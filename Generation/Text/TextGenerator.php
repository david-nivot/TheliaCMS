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

namespace TheliaCMS\Generation\Text;

use Psr\Log\LoggerInterface;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiCapability;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiMessage;
use Thelia\Domain\Ai\AiOutputSchema;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\AiTransparency;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use TheliaCMS\Generation\Ai\CorrelationId;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\GeneratorToolDefinitions;
use TheliaCMS\Generation\Ai\SentBackAnswer;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\InvalidGenerationException;
use TheliaCMS\Generation\RunDetails;
use TheliaCMS\Generation\Tool\GeneratorTools;
use TheliaCMS\Generation\Usage;

/**
 * Asks the model for the text of one rich-text field and hands back
 * formatted text only. Nothing is saved here: the editor reads it and puts it
 * in the field, or not, and the form is saved as if they had typed it.
 *
 * The model may search the products, categories and pages of the site and
 * read a skill. It reads no page of the web: the tools offered are the ones of
 * TOOLS and nothing the provider runs. An answer that is not the envelope
 * asked for, or holds no text once filtered, goes back once. A row of the
 * ledger, with the kind `text`, is written before the first request, `running`,
 * and every outcome completes it before the result is returned or thrown.
 */
#[AsAiUsage(
    id: 'theliacms.text',
    purpose: 'Write the text of one rich-text field of the back office (summary, description, conclusion) from a brief typed by the editor.',
    audience: AiAudience::Admin,
    tier: AiTier::Standard,
    capabilities: [AiCapability::StructuredOutput, AiCapability::Tools],
    transparency: AiTransparency::Generative,
)]
final readonly class TextGenerator
{
    public const string USAGE = 'theliacms.text';

    /** The tools a text needs: what the site sells and what it publishes. */
    public const array TOOLS = ['read_skill', 'search_categories', 'search_pages', 'search_products'];

    private const int MAX_TURNS = 8;

    /** How many times an answer that cannot be used is sent back. */
    private const int CORRECTIONS = 1;

    public function __construct(
        private AiGatewayInterface $gateway,
        private GeneratorSettings $settings,
        private TextPrompt $prompts,
        private GeneratorTools $tools,
        private GeneratorToolDefinitions $definitions,
        private TextSanitizer $sanitizer,
        private GenerationLog $log,
        private LoggerInterface $logger,
        private GatewayFailures $failures,
    ) {
    }

    /**
     * @throws GenerationException when no text could be written, after it was logged
     */
    public function generate(TextRequest $text): TextResult
    {
        $request = $text->request;
        $effort = $this->settings->textEffort();
        $started = hrtime(true);
        $model = '';
        $prompt = $this->prompts->userMessage($text);
        $conversation = new AiRequest(
            self::USAGE,
            [AiMessage::user($prompt)],
            implode("\n\n", array_column($this->prompts->system(), 'text')),
            outputSchema: new AiOutputSchema('text', TextPrompt::schema()),
            tools: $this->definitions->of(self::TOOLS),
            maxOutputTokens: $this->settings->textMaxTokens(),
            providerOptions: ['anthropic' => ['effort' => $effort]],
            correlationId: CorrelationId::new('theliacms', 'text', $request->target->type, $request->target->id),
        );
        $usage = new Usage();
        $costUsd = 0.0;
        $coreLogId = null;
        $turns = 0;
        $corrections = 0;
        /** @var array<string, int> $toolCounts */
        $toolCounts = [];
        $finishReason = null;
        $rowId = $this->log->begin($request, $prompt);

        $record = function (string $status, ?string $error = null, ?string $html = null) use ($request, $prompt, $rowId, $effort, &$model, $started, &$usage, &$costUsd, &$coreLogId, &$turns, &$finishReason, &$corrections, &$toolCounts): int {
            return $this->log->record(
                $request,
                $status,
                $prompt,
                $model,
                $usage,
                $turns,
                (int) ((hrtime(true) - $started) / 1_000_000),
                $costUsd,
                $coreLogId,
                null,
                $finishReason,
                null,
                $error,
                $html,
                rowId: $rowId,
                details: new RunDetails($effort, $corrections, 0, $toolCounts),
            );
        };

        try {
            while (true) {
                if (++$turns > self::MAX_TURNS) {
                    throw $this->failures->tooManyTurns(self::MAX_TURNS, text: true);
                }

                try {
                    $response = $this->gateway->complete($conversation);
                } catch (AiExceptionInterface $exception) {
                    $failedLogId = $this->failures->logIdOf($exception);
                    $coreLogId = $failedLogId ?? $coreLogId;
                    $costUsd += $this->failures->costOf($failedLogId);

                    throw $this->failures->exceptionFor($exception);
                }

                $model = $response->model;
                $finishReason = $response->finishReason->value;
                $usage = $usage->plusTokens($response->tokenUsage);
                $costUsd += (float) $response->cost;
                $coreLogId = $response->logId;
                $this->failures->assertUsable($response, $this->settings->textMaxTokens(), 'raise the limit of the texts in the settings or ask for a shorter text.');
                $this->log->touch($rowId, $model, $usage, $turns, (int) ((hrtime(true) - $started) / 1_000_000), $costUsd, $coreLogId);

                if ($response->hasToolCalls()) {
                    $toolCounts = RunDetails::counted($toolCounts, array_map(static fn (AiToolCall $call): string => $call->name, $response->toolCalls));
                    $conversation = $conversation->withMessages(AiMessage::fromResponse($response), ...$this->toolResults($response->toolCalls, $text));

                    continue;
                }

                $problems = [];
                $html = $this->htmlFrom(trim($response->text), $problems);

                if ([] !== $problems) {
                    if (++$corrections > self::CORRECTIONS) {
                        throw InvalidGenerationException::withProblems($problems);
                    }

                    $this->logger->info('TheliaCMS: the generated text was refused and sent back: {problems}', ['problems' => implode(' ', $problems)]);
                    $conversation = $conversation->withMessages(SentBackAnswer::of($response), AiMessage::user(TextPrompt::correction($problems)));

                    continue;
                }

                $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
                $logId = $record(GenerationLog::STATUS_DONE, null, $html);

                return new TextResult($html, $model, null, $usage, round($costUsd, 6), $durationMs, $turns, $logId);
            }
        } catch (GenerationRefusedException $exception) {
            $exception->logId = $record(GenerationLog::STATUS_REFUSED, $exception->getMessage());

            throw $exception;
        } catch (InvalidGenerationException $exception) {
            $exception->logId = $record(GenerationLog::STATUS_INVALID, $exception->getMessage());

            throw $exception;
        } catch (GenerationException $exception) {
            $exception->logId = $record(GenerationLog::STATUS_FAILED, $exception->getMessage());

            throw $exception;
        } catch (AiExceptionInterface $exception) {
            // What the core refuses outside the call itself, a message it
            // cannot take among them: the same ledger row, the same words.
            $failure = $this->failures->exceptionFor($exception);
            $failure->logId = $record(GenerationLog::STATUS_FAILED, $failure->getMessage());

            throw $failure;
        } catch (\Throwable $exception) {
            // Anything else (Propel, a TypeError in a tool): the editor gets
            // a sentence, the log gets the exception with its trace, and the
            // row says `failed` rather than staying `running`.
            $this->logger->error('TheliaCMS: the generation stopped on an unexpected error: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
            $failure = $this->failures->unexpected($exception);

            try {
                $failure->logId = $record(GenerationLog::STATUS_FAILED, $failure->getMessage());
            } catch (\Throwable $recording) {
                $this->logger->error('TheliaCMS: the failed generation could not be written to the ledger: {message}', ['message' => $recording->getMessage(), 'exception' => $recording]);
            }

            throw $failure;
        }
    }

    /**
     * The text of the envelope, filtered down to formatted text.
     *
     * @param list<string> $problems
     */
    private function htmlFrom(string $answer, array &$problems): string
    {
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $answer, $match)) {
            $answer = $match[1];
        }

        $decoded = json_decode($answer, true);

        if (!\is_array($decoded) || !\is_string($decoded['html'] ?? null)) {
            $problems[] = 'The answer is not a JSON object with the text in "html".';

            return '';
        }

        $html = $this->sanitizer->text($decoded['html']);

        if ('' === trim(strip_tags($html))) {
            $problems[] = 'The text is empty: write it in "html", as paragraphs.';
        }

        return $html;
    }

    /**
     * @param list<AiToolCall> $calls
     *
     * @return list<AiMessage>
     */
    private function toolResults(array $calls, TextRequest $text): array
    {
        return array_map(
            // Only the tools offered run, whatever name the call gives.
            fn (AiToolCall $call): AiMessage => AiMessage::toolResult(
                $call->id,
                \in_array($call->name, self::TOOLS, true)
                    ? $this->tools->run($call->name, $call->arguments, $text->request)
                    : \sprintf('There is no tool called "%s".', $call->name),
            ),
            $calls,
        );
    }
}
