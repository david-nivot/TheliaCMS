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
use Thelia\Domain\Ai\Guardrail\GuardrailMode;
use Thelia\Domain\Ai\UntrustedContent;
use Thelia\Domain\Ai\UntrustedSource;
use TheliaCMS\Content\SectionCatalog;
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
use TheliaCMS\Generation\Guardrail\ReferenceFileGuardrail;
use TheliaCMS\Generation\InvalidGenerationException;
use TheliaCMS\Generation\RunDetails;
use TheliaCMS\Generation\Usage;

/**
 * Asks the model for a component of the site and hands back one the site can
 * keep: checked by the validator, its code free (a new one) or unchanged (a
 * change), and a change still taking the props the pages using it hold.
 * Nothing is saved here; the editor looks at it first.
 *
 * The model may answer that the site already has what is asked (`existing`:
 * the name of a component of the catalogue, no component), or that it is a
 * variant of a component of the site (`copy`): the source of that component
 * is then sent in the same conversation, and the variant comes back as a
 * component under a new code, which records what it is based on.
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
    // A file joined to the request that instructs rather than documents is
    // refused before the call, as on the brief of a page.
    guardrails: [ReferenceFileGuardrail::NAME => GuardrailMode::Block],
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
        private SectionCatalog $catalog,
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
            // The file joined to the request travels as data the gateway wraps,
            // never in the message: whatever it asks, the request is the request.
            untrustedContents: null === $request->reference ? [] : [new UntrustedContent(UntrustedSource::Document, $request->reference->text, $request->reference->name)],
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
        $copying = null;
        $rowId = $this->log->beginComponent($request->componentId ?? 0, $request->locale, $request->kind(), $request->text, $request->reference);

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
                $request->reference,
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
                    $answer = self::decode(trim($response->text));
                    $outcome = $this->outcomeOf($answer, $request, null !== $copying);

                    if (ComponentResult::EXISTING === $outcome) {
                        $name = $this->existingNamed($answer);
                        $reason = trim((string) $answer['reason']);
                        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
                        $logId = $record(GenerationLog::STATUS_DONE, null, ComponentCandidates::documentOf(null, $name, null, $reason));

                        return new ComponentResult(null, $model, null, $usage, round($costUsd, 6), $durationMs, $turns, $logId, $provenance, ComponentResult::EXISTING, $name, null, $reason);
                    }

                    if (ComponentResult::COPY === $outcome) {
                        [$name, $source] = $this->copiedNamed($answer);
                        $copying = ['name' => $name, 'reason' => trim((string) ($answer['reason'] ?? ''))];
                        $conversation = $conversation->withMessages(SentBackAnswer::of($response), AiMessage::user(ComponentPrompt::copy($source, $name)));

                        continue;
                    }

                    $component = $this->validator->validate($this->componentFrom($answer, $request));

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
                $logId = $record(GenerationLog::STATUS_DONE, null, ComponentCandidates::documentOf($component, null, $copying['name'] ?? null, $copying['reason'] ?? ''));

                return new ComponentResult($component, $model, null, $usage, round($costUsd, 6), $durationMs, $turns, $logId, $provenance, ComponentResult::COMPONENT, null, $copying['name'] ?? null, $copying['reason'] ?? '');
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
     * The answer as an array, still to be read.
     *
     * @return array<mixed>
     *
     * @throws InvalidSiteComponentException
     */
    private static function decode(string $text): array
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

        return $answer;
    }

    /**
     * What the answer says it is: `component` when it does not say, as an
     * answer of before the outcomes did. A change, and the answer that
     * follows a copy, are components.
     *
     * @param array<mixed> $answer
     *
     * @throws InvalidSiteComponentException
     */
    private function outcomeOf(array $answer, ComponentRequest $request, bool $afterCopy): string
    {
        $outcome = $answer['outcome'] ?? ComponentResult::COMPONENT;

        if (!\is_string($outcome) || !\in_array($outcome, ComponentResult::OUTCOMES_ASKED, true)) {
            throw InvalidSiteComponentException::because([\sprintf('"outcome" is one of: %s.', implode(', ', ComponentResult::OUTCOMES_ASKED))]);
        }

        if (ComponentResult::COMPONENT !== $outcome && null !== $request->current) {
            throw InvalidSiteComponentException::because(['A change of a component is answered with the outcome "component": the whole component, its code unchanged.']);
        }

        if (ComponentResult::COMPONENT !== $outcome && $afterCopy) {
            throw InvalidSiteComponentException::because(['The source of the component was sent for you to write the variant: answer with the outcome "component", the whole component under a new code.']);
        }

        return $outcome;
    }

    /**
     * The name of the component the site already has, as the answer gives it.
     *
     * @param array<mixed> $answer
     *
     * @throws InvalidSiteComponentException
     */
    private function existingNamed(array $answer): string
    {
        $name = \is_string($answer['existing'] ?? null) ? trim($answer['existing']) : '';
        $problems = [];

        if (null === ($this->catalog->all()[$name] ?? null)) {
            $problems[] = \sprintf('"%s" is not a component of the list: "existing" holds the name of one of them, as written between backticks.', $name);
        }

        if ('' === trim((string) ($answer['reason'] ?? ''))) {
            $problems[] = 'With the outcome "existing", "reason" says in one sentence what that component already does.';
        }

        if ([] !== $problems) {
            throw InvalidSiteComponentException::because($problems);
        }

        return $name;
    }

    /**
     * The component of the site to copy: its name and its source.
     *
     * @param array<mixed> $answer
     *
     * @return array{0: string, 1: SiteComponent}
     *
     * @throws InvalidSiteComponentException
     */
    private function copiedNamed(array $answer): array
    {
        $name = \is_string($answer['existing'] ?? null) ? trim($answer['existing']) : '';
        $code = ComponentCandidate::siteCodeOf($name);
        $source = null === $code ? null : $this->components->find($code);

        if (null === $source) {
            throw InvalidSiteComponentException::because([\sprintf('"%s" is not a component of the site: with the outcome "copy", "existing" holds the name of one of the components `Site:…` of the list. Otherwise make a component.', $name)]);
        }

        return [$source->name(), $source];
    }

    /**
     * The component of the answer, still to be checked. A change keeps its
     * code; a new component takes one no component of the site holds.
     *
     * @param array<mixed> $answer
     *
     * @throws InvalidSiteComponentException
     */
    private function componentFrom(array $answer, ComponentRequest $request): SiteComponent
    {
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
