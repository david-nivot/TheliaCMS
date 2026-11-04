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

namespace TheliaCMS\Generation\Component;

use Psr\Log\LoggerInterface;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiCapability;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiMessage;
use Thelia\Domain\Ai\AiOutputSchema;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiTransparency;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentUsage;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Generation\Ai\CorrelationId;
use TheliaCMS\Generation\Ai\GatewayFailures;
use TheliaCMS\Generation\Ai\ObservedStream;
use TheliaCMS\Generation\Ai\SentBackAnswer;
use TheliaCMS\Generation\Ai\StreamCutException;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\GenerationObserverInterface;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Generation\InvalidGenerationException;
use TheliaCMS\Generation\RunDetails;
use TheliaCMS\Generation\Usage;

/**
 * Asks the model for a component of the site and hands back one the site can
 * keep: checked by the validator, its code free (a new one) or unchanged (a
 * change), and a change still taking the props the pages using it hold.
 * Nothing is saved here; the editor looks at it first.
 *
 * One conversation per request, without tools: the request, the component,
 * and the corrections it needs. A component the site refuses goes back with
 * every problem listed, twice at most. A row of the ledger, beside the calls
 * that write pages, is written before the first request, `running`, and every
 * outcome completes it before the result is returned or thrown. An answer cut
 * half way is asked again, once, and counts as neither a turn nor a correction.
 */
#[AsAiUsage(
    id: 'theliacms.component',
    purpose: 'Write a component of the site (its template, its styles and its props) from a request typed in the back office.',
    audience: AiAudience::Admin,
    tier: AiTier::Advanced,
    capabilities: [AiCapability::StructuredOutput, AiCapability::Streaming],
    transparency: AiTransparency::Generative,
)]
final readonly class ComponentGenerator
{
    public const string USAGE = 'theliacms.component';

    /** How many times a refused component is sent back for correction. */
    private const int CORRECTIONS = 2;

    /** How many times an answer cut half way is asked again. */
    private const int RETRIES = 1;

    public function __construct(
        private AiGatewayInterface $gateway,
        private GeneratorSettings $settings,
        private ComponentPrompt $prompts,
        private SiteComponentValidator $validator,
        private SiteComponentRepository $components,
        private GenerationLog $log,
        private LoggerInterface $logger,
        private GatewayFailures $failures,
        private SiteComponentUsage $usage,
        private SitePropReader $props = new SitePropReader(),
        private ObservedStream $streams = new ObservedStream(),
    ) {
    }

    /**
     * @throws GenerationException when no component could be produced, after it was logged
     */
    public function generate(ComponentRequest $request, ?GenerationObserverInterface $observer = null): ComponentResult
    {
        $started = hrtime(true);
        $model = '';
        $effort = $this->settings->effort();
        $conversation = new AiRequest(
            self::USAGE,
            [AiMessage::user($this->prompts->userMessage($request))],
            implode("\n\n", array_column($this->prompts->system(), 'text')),
            outputSchema: new AiOutputSchema('site_component', ComponentPrompt::schema()),
            maxOutputTokens: $this->settings->maxTokens(),
            providerOptions: ['anthropic' => ['effort' => $effort]],
            correlationId: CorrelationId::new('theliacms', 'component', $request->componentId ?? 0),
        );
        $usage = new Usage();
        $costUsd = 0.0;
        $coreLogId = null;
        $provenance = null;
        $turns = 0;
        $corrections = 0;
        $retries = 0;
        $finishReason = null;
        $rowId = $this->log->beginComponent($request->componentId ?? 0, $request->locale, $request->kind(), $request->text);

        $record = function (string $status, ?string $error = null, ?string $component = null) use ($request, $rowId, $effort, &$model, $started, &$usage, &$costUsd, &$coreLogId, &$provenance, &$turns, &$finishReason, &$corrections, &$retries): int {
            return $this->log->recordComponent(
                $request->componentId ?? 0,
                $request->locale,
                $request->kind(),
                $request->text,
                $status,
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
                $component,
                GenerationLog::STATUS_DONE === $status ? $provenance : null,
                $rowId,
                new RunDetails($effort, $corrections, $retries),
            );
        };

        try {
            while (true) {
                ++$turns;

                try {
                    $response = $this->streams->read($this->gateway->stream($conversation), $observer);
                } catch (StreamCutException $cut) {
                    // Paid for what arrived, whether it is asked again or not.
                    $coreLogId = $cut->coreLogId ?? $coreLogId;
                    $costUsd += $this->failures->costOf($cut->coreLogId);

                    if ($retries >= self::RETRIES) {
                        throw $this->failures->exceptionForCut($cut);
                    }

                    ++$retries;

                    $this->logger->warning('TheliaCMS: the answer of the model was cut half way and is asked again: {message}', ['message' => $cut->getMessage(), 'exception' => $cut]);
                    $observer?->onCut();
                    --$turns;
                    $this->log->touch($rowId, $model, $usage, $turns, (int) ((hrtime(true) - $started) / 1_000_000), $costUsd, $coreLogId);

                    continue;
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
                $provenance = $response->provenance;
                $this->log->touch($rowId, $model, $usage, $turns, (int) ((hrtime(true) - $started) / 1_000_000), $costUsd, $coreLogId);
                $observer?->onTurn($turns, []);
                $this->failures->assertUsable($response, $this->settings->maxTokens(), 'raise the limit of the generator or ask for a simpler component.');

                try {
                    $component = $this->validator->validate($this->componentFrom(trim($response->text), $request));

                    // A change of a kept component must still take what the
                    // pages hold: one that breaks them is corrected like any
                    // other refusal, the pages named.
                    if (null !== $request->componentId && [] !== $breakages = $this->usage->breakages($component, $this->props->read($component->props))) {
                        throw InvalidSiteComponentException::because($breakages);
                    }
                } catch (InvalidSiteComponentException $exception) {
                    if (++$corrections > self::CORRECTIONS) {
                        throw InvalidGenerationException::withProblems($exception->problems);
                    }

                    $this->logger->info('TheliaCMS: the generated component was refused and sent back: {problems}', ['problems' => implode(' ', $exception->problems)]);
                    $observer?->onCorrection($corrections, $exception->problems);
                    $conversation = $conversation->withMessages(SentBackAnswer::of($response), AiMessage::user(ComponentPrompt::correction($exception->problems)));

                    continue;
                }

                $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
                $logId = $record(GenerationLog::STATUS_DONE, null, ComponentPrompt::json($component));

                return new ComponentResult($component, $model, null, $usage, round($costUsd, 6), $durationMs, $turns, $logId, $provenance);
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
     * The component of the answer, still to be checked. A change keeps its
     * code; a new component takes one no component of the site holds.
     *
     * @throws InvalidSiteComponentException
     */
    private function componentFrom(string $text, ComponentRequest $request): SiteComponent
    {
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $match)) {
            $text = $match[1];
        }

        try {
            $answer = json_decode($text, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw InvalidSiteComponentException::because(['The answer is not valid JSON: '.$exception->getMessage()]);
        }

        if (!\is_array($answer)) {
            throw InvalidSiteComponentException::because(['The answer is not a JSON object.']);
        }

        $problems = [];
        $props = self::decoded($answer['props'] ?? null, 'props', $problems);
        $sample = self::decoded($answer['sample'] ?? null, 'sample', $problems);
        $code = \is_string($answer['code'] ?? null) ? trim($answer['code']) : '';

        if (null !== $request->current && $code !== $request->current->code) {
            $problems[] = \sprintf('The code of the component stays "%s".', $request->current->code);
        }

        if (null === $request->current && '' !== $code && null !== $this->components->find($code)) {
            $problems[] = \sprintf('The code "%s" is already the one of a component of the site: choose another.', $code);
        }

        if (null !== $props && !array_is_list($props)) {
            $problems[] = '"props" is a JSON array.';
        }

        if ([] !== $problems) {
            throw InvalidSiteComponentException::because($problems);
        }

        // Both are read by now: a missing one was a problem, thrown above.
        /** @var list<array<string, mixed>> $propList */
        $propList = $props ?? [];

        return new SiteComponent(
            $code,
            \is_string($answer['label'] ?? null) ? $answer['label'] : '',
            \is_string($answer['description'] ?? null) ? $answer['description'] : '',
            $propList,
            $sample ?? [],
            \is_string($answer['template'] ?? null) ? $answer['template'] : '',
            \is_string($answer['css'] ?? null) ? $answer['css'] : '',
        );
    }

    /**
     * A field the answer writes as a JSON string.
     *
     * @param list<string> $problems
     *
     * @return array<mixed>|null
     */
    private static function decoded(mixed $value, string $field, array &$problems): ?array
    {
        if (\is_array($value)) {
            return $value;
        }

        $decoded = \is_string($value) ? json_decode($value, true) : null;

        if (!\is_array($decoded)) {
            $problems[] = \sprintf('"%s" is not JSON written as a string.', $field);

            return null;
        }

        return $decoded;
    }
}
