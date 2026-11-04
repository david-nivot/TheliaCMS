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

namespace TheliaCMS\Tests\Support;

use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiProvenance;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiResponse;
use Thelia\Domain\Ai\AiTokenUsage;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use Thelia\Domain\Ai\Stream\StreamCompleted;
use Thelia\Domain\Ai\Stream\StreamEvent;
use Thelia\Domain\Ai\Stream\TextDelta;
use Thelia\Domain\Ai\Stream\ToolCallReceived;

/**
 * The AI gateway of the core as the tests see it: answers queued in advance,
 * every request kept for the assertions. Nothing reaches a provider.
 *
 * An answer queued with complete() or stream() is consumed by either method,
 * in order. A queued Throwable is thrown instead, by the first iteration for a
 * stream, as the real gateway does. A stream queued with cutAfter() gives
 * its events and then fails, as the gateway does for a provider that drops
 * after the first tokens.
 */
final class FakeAiGateway implements AiGatewayInterface
{
    /** @var list<AiResponse|list<StreamEvent>|\Throwable|array{cut: list<StreamEvent>, failure: AiProviderUnavailableException}> */
    private array $queue = [];

    /** @var list<AiRequest> every request received, in order */
    public array $requests = [];

    /**
     * @param array<string, mixed>|null $data
     * @param list<AiToolCall>          $toolCalls
     */
    public static function response(
        string $text = '',
        ?array $data = null,
        array $toolCalls = [],
        ?AiFinishReason $finishReason = null,
        int $logId = 1,
        ?AiProvenance $provenance = null,
        int $outputTokens = 5,
    ): AiResponse {
        return new AiResponse(
            $text,
            $data,
            $toolCalls,
            new AiTokenUsage(10, $outputTokens),
            '0.00100000',
            'fake-model',
            'fake',
            $finishReason ?? ([] === $toolCalls ? AiFinishReason::Completed : AiFinishReason::ToolCalls),
            [],
            $logId,
            $provenance,
        );
    }

    /**
     * The mark the core puts on the answer of a generative usage, which the
     * real gateway builds and this one is told to.
     */
    public static function provenance(string $usageId = 'theliacms.page_generation', int $logId = 1): AiProvenance
    {
        return new AiProvenance($usageId, 'fake-model', new \DateTimeImmutable('2026-10-28T10:00:00+00:00'), $logId);
    }

    public function answer(AiResponse|\Throwable $answer): self
    {
        $this->queue[] = $answer;

        return $this;
    }

    /**
     * Queues a stream: a delta for every piece of text, one event for every
     * tool call, then the completion with the response.
     *
     * @param list<string> $deltas
     */
    public function streamed(array $deltas, AiResponse $response): self
    {
        $events = array_map(static fn (string $text): StreamEvent => new TextDelta($text), $deltas);

        foreach ($response->toolCalls as $toolCall) {
            $events[] = new ToolCallReceived($toolCall);
        }

        $events[] = new StreamCompleted($response);
        $this->queue[] = $events;

        return $this;
    }

    /**
     * Queues a stream of the events given, whatever they are: a stream cut
     * before its last event, an event of a kind to come.
     *
     * @param list<StreamEvent> $events
     */
    public function streamOf(array $events): self
    {
        $this->queue[] = $events;

        return $this;
    }

    /**
     * Queues a stream that gives a delta for every piece of text and then
     * fails with the exception given, as a provider dropping half way does.
     *
     * @param list<string> $deltas
     */
    public function cutAfter(array $deltas, AiProviderUnavailableException $failure): self
    {
        $this->queue[] = ['cut' => array_map(static fn (string $text): StreamEvent => new TextDelta($text), $deltas), 'failure' => $failure];

        return $this;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $answer = $this->next();

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        if (\is_array($answer) && isset($answer['cut'])) {
            throw $answer['failure'];
        }

        if (\is_array($answer)) {
            foreach ($answer as $event) {
                if ($event instanceof StreamCompleted) {
                    return $event->response;
                }
            }

            throw new \LogicException('The queued stream holds no completion to answer complete() with.');
        }

        return $answer;
    }

    public function stream(AiRequest $request): iterable
    {
        $this->requests[] = $request;
        $answer = $this->next();

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        if ($answer instanceof AiResponse) {
            $answer = [new StreamCompleted($answer)];
        }

        if (isset($answer['cut'])) {
            yield from $answer['cut'];

            throw $answer['failure'];
        }

        yield from $answer;
    }

    public function remaining(): int
    {
        return \count($this->queue);
    }

    /**
     * @return AiResponse|list<StreamEvent>|\Throwable|array{cut: list<StreamEvent>, failure: AiProviderUnavailableException}
     */
    private function next(): AiResponse|array|\Throwable
    {
        if ([] === $this->queue) {
            throw new \LogicException(\sprintf('The fake gateway has no answer left for request %d.', \count($this->requests)));
        }

        return array_shift($this->queue);
    }
}
