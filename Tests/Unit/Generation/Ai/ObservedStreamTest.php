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

use PHPUnit\Framework\TestCase;
use Thelia\Domain\Ai\AiMessage;
use Thelia\Domain\Ai\AiRequest;
use Thelia\Domain\Ai\AiToolCall;
use Thelia\Domain\Ai\Stream\StreamCompleted;
use Thelia\Domain\Ai\Stream\StreamEvent;
use Thelia\Domain\Ai\Stream\TextDelta;
use TheliaCMS\Generation\Ai\ObservedStream;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationObserverInterface;
use TheliaCMS\Tests\Support\FakeAiGateway;

/**
 * The text of a stream, told to the observer of the screen as a count of characters.
 */
final class ObservedStreamTest extends TestCase
{
    public function testTheObserverIsToldTheCharactersWrittenSoFar(): void
    {
        $observer = new RecordingObserver();
        $response = FakeAiGateway::response('Bonjour le monde');
        $gateway = (new FakeAiGateway())->streamed(['Bonjour', ' le', ' monde'], $response);

        $read = (new ObservedStream())->read($gateway->stream(self::anyRequest()), $observer);

        self::assertSame([7, 10, 16], $observer->characters);
        self::assertSame($response, $read);
    }

    public function testCharactersAreCountedNotBytes(): void
    {
        $observer = new RecordingObserver();
        $gateway = (new FakeAiGateway())->streamed(['é', 'à€'], FakeAiGateway::response('éà€'));

        (new ObservedStream())->read($gateway->stream(self::anyRequest()), $observer);

        self::assertSame([1, 3], $observer->characters);
    }

    public function testAStreamIsReadWithoutObserver(): void
    {
        $response = FakeAiGateway::response('Texte');
        $gateway = (new FakeAiGateway())->streamed(['Texte'], $response);

        self::assertSame($response, (new ObservedStream())->read($gateway->stream(self::anyRequest())));
    }

    public function testToolCallsAreInTheResponseAndTellTheObserverNothing(): void
    {
        $observer = new RecordingObserver();
        $response = FakeAiGateway::response('', null, [new AiToolCall('call_1', 'search_pages', ['query' => 'tarte'])]);
        $gateway = (new FakeAiGateway())->streamed([], $response);

        $read = (new ObservedStream())->read($gateway->stream(self::anyRequest()), $observer);

        self::assertSame([], $observer->characters);
        self::assertSame('search_pages', $read->toolCalls[0]->name);
    }

    public function testAnEventOfAKindToComeIsIgnored(): void
    {
        $observer = new RecordingObserver();
        $response = FakeAiGateway::response('ab');
        $gateway = (new FakeAiGateway())->streamOf([
            new class implements StreamEvent {
            },
            new TextDelta('ab'),
            new StreamCompleted($response),
        ]);

        $read = (new ObservedStream())->read($gateway->stream(self::anyRequest()), $observer);

        self::assertSame([2], $observer->characters);
        self::assertSame($response, $read);
    }

    public function testAStreamCutBeforeItsLastEventIsAnError(): void
    {
        $gateway = (new FakeAiGateway())->streamOf([new TextDelta('abc')]);

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('The AI gateway closed the stream before answering.');

        (new ObservedStream())->read($gateway->stream(self::anyRequest()));
    }

    private static function anyRequest(): AiRequest
    {
        return new AiRequest('theliacms.test', [AiMessage::user('Hello')]);
    }
}

final class RecordingObserver implements GenerationObserverInterface
{
    /** @var list<int> */
    public array $characters = [];

    public function onTurn(int $turn, array $tools): void
    {
    }

    public function onText(int $characters): void
    {
        $this->characters[] = $characters;
    }

    public function onCorrection(int $round, array $problems): void
    {
    }

    public function onCut(): void
    {
    }
}
