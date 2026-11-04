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

namespace TheliaCMS\Generation\Ai;

use Thelia\Domain\Ai\AiResponse;
use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use Thelia\Domain\Ai\Stream\StreamCompleted;
use Thelia\Domain\Ai\Stream\StreamEvent;
use Thelia\Domain\Ai\Stream\TextDelta;
use TheliaCMS\Generation\GenerationObserverInterface;

/**
 * Reads a stream of the gateway to its end: tells the observer how many
 * characters of text have arrived, and hands back the whole response the last
 * event carries.
 *
 * Tells a stream cut half way from a failure before any event: the core has
 * retried the latter by itself, and it stays a failure; the former is a
 * StreamCutException, which the generator may take up once.
 */
final readonly class ObservedStream
{
    /**
     * @param iterable<StreamEvent> $events
     *
     * @throws StreamCutException             when the stream was cut after its first event, or ended without its last event
     * @throws AiProviderUnavailableException when the provider failed before any event, or for good
     */
    public function read(iterable $events, ?GenerationObserverInterface $observer = null): AiResponse
    {
        $characters = 0;
        $received = false;

        try {
            foreach ($events as $event) {
                $received = true;

                if ($event instanceof TextDelta) {
                    $characters += mb_strlen($event->text);
                    $observer?->onText($characters);
                } elseif ($event instanceof StreamCompleted) {
                    return $event->response;
                }
                // Any other event, known or not, is nothing the observer is told of.
            }
        } catch (AiProviderUnavailableException $unavailable) {
            throw $received && $unavailable->retryable ? StreamCutException::after($unavailable) : $unavailable;
        }

        throw StreamCutException::endedEarly();
    }
}
