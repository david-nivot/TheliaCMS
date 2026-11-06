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

namespace TheliaCMS\Generation\Admin;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiProvenance;
use TheliaCMS\Content\Document;
use TheliaCMS\Generation\Alongside;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationRequest;
use TheliaCMS\Generation\HeaderOutcome;
use TheliaCMS\Generation\PageGenerator;
use TheliaCMS\TheliaCMS;

/**
 * Runs a generation for a screen and answers it: as a stream of events when
 * the script of the screen asked for one, as a redirect otherwise, so the
 * screen works without the script. Either way the screen is read again with
 * the id of the ledger row, and shows the outcome from there: the script
 * puts that screen in place of the one on show, the browser goes there.
 *
 * What the generator throws is a GenerationException, written to the ledger.
 * Anything else (the closure that saves the document failing, after the row
 * says `done`) is logged and answered like a failure: a half-written stream
 * is a worse answer than a sentence.
 */
final readonly class GenerationRunner
{
    /** The query parameter the screen reads the outcome of a call from. */
    public const string OUTCOME = 'done';

    /** What a translation did with the header of a page: read from the address like the outcome of the call, a stream leaving no flash message behind. */
    public const string HEADER = 'header';

    private const array STREAM_HEADERS = [
        'Content-Type' => 'text/event-stream; charset=UTF-8',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        // The reverse proxy would otherwise hold the whole answer back
        // until the end, which is the one thing a stream is not for.
        'X-Accel-Buffering' => 'no',
    ];

    public function __construct(
        private PageGenerator $generator,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param \Closure(Document, AiProvenance|null, Alongside): (HeaderOutcome|null) $save stores the document the generator hands back, the mark of provenance of the call and what comes with the document (fields, search engine title and description, header); says what it did with the header of a page, when it did something
     */
    public function respond(Request $request, GenerationRequest $generation, \Closure $save, string $screenUrl): Response
    {
        // A generation is as long as the model takes; the limit of a request
        // of the back office is not meant for it.
        set_time_limit(0);

        if (self::wantsAStream($request)) {
            return $this->stream($generation, $save, $screenUrl);
        }

        try {
            $result = $this->generator->generate($generation);
            $header = $save($result->document, $result->provenance, $result->alongside());
        } catch (GenerationException $exception) {
            if (null === $exception->logId) {
                $this->flash($request, 'danger', $exception->getMessage());

                return new RedirectResponse($screenUrl);
            }

            return new RedirectResponse(self::withOutcome($screenUrl, $exception->logId));
        } catch (\Throwable $exception) {
            $this->logger->error('TheliaCMS: the generation ended on an unexpected error: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
            $this->flash($request, 'danger', $this->unexpected());

            return new RedirectResponse($screenUrl);
        }

        return new RedirectResponse(self::withOutcome($screenUrl, (int) $result->logId, $header instanceof HeaderOutcome ? $header : null));
    }

    /**
     * A request refused before the generator runs (a file the brief does
     * not take), answered as a generation is: one `error` event when the
     * script of the screen asked for a stream, a flash and the screen
     * otherwise. Nothing is written to the ledger.
     */
    public function refuse(Request $request, string $message, string $screenUrl): Response
    {
        if (!self::wantsAStream($request)) {
            $this->flash($request, 'danger', $message);

            return new RedirectResponse($screenUrl);
        }

        $events = new GenerationStream(fn (string $text, array $parameters): string => $this->translator->trans($text, $parameters, TheliaCMS::DOMAIN_NAME));

        return new StreamedResponse(static function () use ($events, $message): void {
            $events->error($message, null);
        }, Response::HTTP_OK, self::STREAM_HEADERS);
    }

    /**
     * @param \Closure(Document, AiProvenance|null, Alongside): (HeaderOutcome|null) $save
     */
    private function stream(GenerationRequest $generation, \Closure $save, string $screenUrl): StreamedResponse
    {
        $events = new GenerationStream(fn (string $message, array $parameters): string => $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME));

        $response = new StreamedResponse(function () use ($events, $generation, $save, $screenUrl): void {
            // The editor closing the tab must not kill a call the site pays
            // for: it runs to its end and is written to the ledger.
            ignore_user_abort(true);

            $events->start($this->translator->trans('The generator is reading the brief.', [], TheliaCMS::DOMAIN_NAME));

            try {
                $result = $this->generator->generate($generation, $events);
                $header = $save($result->document, $result->provenance, $result->alongside());
            } catch (GenerationException $exception) {
                $events->error($exception->getMessage(), null === $exception->logId ? null : self::withOutcome($screenUrl, $exception->logId));

                return;
            } catch (\Throwable $exception) {
                $this->logger->error('TheliaCMS: the generation ended on an unexpected error: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
                $events->error($this->unexpected());

                return;
            }

            $events->done(self::withOutcome($screenUrl, (int) $result->logId, $header instanceof HeaderOutcome ? $header : null), $this->translator->trans('The document is written.', [], TheliaCMS::DOMAIN_NAME));
        }, Response::HTTP_OK, self::STREAM_HEADERS);

        return $response;
    }

    private function unexpected(): string
    {
        return $this->translator->trans('The generation stopped on an unexpected error. Try again, and tell the person who looks after the site if it lasts.', [], TheliaCMS::DOMAIN_NAME);
    }

    private static function wantsAStream(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'text/event-stream');
    }

    public static function withOutcome(string $url, int $logId, ?HeaderOutcome $header = null): string
    {
        $url .= (str_contains($url, '?') ? '&' : '?').self::OUTCOME.'='.$logId;

        return null === $header || $header->isEmpty() ? $url : $url.'&'.self::HEADER.'='.$header->toQuery();
    }

    private function flash(Request $request, string $type, string $message): void
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add($type, $message);
        }
    }
}
