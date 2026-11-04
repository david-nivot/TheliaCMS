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
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationRequest;
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

    public function __construct(
        private PageGenerator $generator,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param \Closure(Document, array<string, mixed>|null, AiProvenance|null, array<string, string>): void $save stores the document the generator hands back, the values of the fields of the page when it wrote some, the mark of provenance of the call, and the title and description for search engines it proposed
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
            $save($result->document, $result->fields, $result->provenance, $result->seo);
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

        return new RedirectResponse(self::withOutcome($screenUrl, (int) $result->logId));
    }

    /**
     * @param \Closure(Document, array<string, mixed>|null, AiProvenance|null, array<string, string>): void $save
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
                $save($result->document, $result->fields, $result->provenance, $result->seo);
            } catch (GenerationException $exception) {
                $events->error($exception->getMessage(), null === $exception->logId ? null : self::withOutcome($screenUrl, $exception->logId));

                return;
            } catch (\Throwable $exception) {
                $this->logger->error('TheliaCMS: the generation ended on an unexpected error: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
                $events->error($this->unexpected());

                return;
            }

            $events->done(self::withOutcome($screenUrl, (int) $result->logId), $this->translator->trans('The document is written.', [], TheliaCMS::DOMAIN_NAME));
        }, Response::HTTP_OK, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            // The reverse proxy would otherwise hold the whole answer back
            // until the end, which is the one thing a stream is not for.
            'X-Accel-Buffering' => 'no',
        ]);

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

    public static function withOutcome(string $url, int $logId): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').self::OUTCOME.'='.$logId;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add($type, $message);
        }
    }
}
