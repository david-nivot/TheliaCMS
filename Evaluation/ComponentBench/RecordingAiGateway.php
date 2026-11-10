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

namespace TheliaCMS\Evaluation\ComponentBench;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiMessageRole;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiResponse;
use Thelia\Domain\Ai\Stream\StreamCompleted;

/**
 * The gateway of the core with a memory: it hands every call to the real one
 * and keeps what was sent (the system prompt, the messages) and the raw text
 * of every answer, streamed or not. The events of a stream go through as they
 * come, so what the generator reads is unchanged.
 *
 * One instance per trial: a trial is one conversation.
 */
#[Exclude]
final class RecordingAiGateway implements AiGatewayInterface
{
    /** @var list<AiRequest> */
    private array $requests = [];

    /** @var list<string> */
    private array $answers = [];

    private string $model = '';

    public function __construct(private readonly AiGatewayInterface $inner)
    {
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $response = $this->inner->complete($request);
        $this->keep($response);

        return $response;
    }

    public function stream(AiRequest $request): iterable
    {
        $this->requests[] = $request;

        foreach ($this->inner->stream($request) as $event) {
            if ($event instanceof StreamCompleted) {
                $this->keep($event->response);
            }

            yield $event;
        }
    }

    /**
     * The system prompt of the first call.
     */
    public function system(): string
    {
        return (string) ($this->requests[0]->system ?? '');
    }

    /**
     * The first message of the user, in the first call.
     */
    public function user(): string
    {
        foreach ($this->requests[0]->messages ?? [] as $message) {
            if (AiMessageRole::User === $message->role) {
                return $message->content;
            }
        }

        return '';
    }

    /**
     * The hash of what the model was asked: the system prompt and the first
     * message of the user. A recording whose hash differs from the one of
     * today's prompt is stale.
     */
    public function promptSha256(): string
    {
        return hash('sha256', $this->system()."\n\n".$this->user());
    }

    /**
     * @return list<string> the raw text of every answer that came back complete, in order
     */
    public function answers(): array
    {
        return $this->answers;
    }

    public function model(): string
    {
        return $this->model;
    }

    private function keep(AiResponse $response): void
    {
        $this->answers[] = $response->text;
        $this->model = $response->model;
    }
}
