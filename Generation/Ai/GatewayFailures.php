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

use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Ai\AiFinishReason;
use Thelia\Domain\Ai\AiResponse;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;
use Thelia\Domain\Ai\Exception\AiGuardrailBlockedException;
use Thelia\Domain\Ai\Exception\AiJurisdictionRefusedException;
use Thelia\Domain\Ai\Exception\AiKeyDecryptionException;
use Thelia\Domain\Ai\Exception\AiProviderUnavailableException;
use Thelia\Domain\Ai\Exception\AiRateLimitExceededException;
use Thelia\Domain\Ai\Exception\InactiveAiUsageException;
use Thelia\Model\AiUsageLogQuery;
use TheliaCMS\Generation\GenerationException;
use TheliaCMS\Generation\GenerationRefusedException;
use TheliaCMS\Generation\GenerationUnavailableException;
use TheliaCMS\Generation\Guardrail\ReferenceFileGuardrail;
use TheliaCMS\TheliaCMS;

/**
 * What the generators say when the AI layer of the core does not give an
 * answer: one sentence an editor can act on for each cause, in the language
 * of the back office, with nothing of the provider in it. The detail stays in
 * the usage log of the core, which the ledger of the module points at.
 *
 * Also what a generator does with the reason the model stopped: a refusal, an
 * answer cut by the output limit and an answer of no use end the generation,
 * the other reasons let it go on (the tool calls are the generator's to run).
 */
final readonly class GatewayFailures
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * The exception of the module for one of the core: unavailable when the
     * site cannot call the AI now, refused when a check of the site stopped
     * the request, a failure otherwise.
     */
    public function exceptionFor(AiExceptionInterface $exception): GenerationException
    {
        return match (true) {
            $exception instanceof AiBudgetExceededException => GenerationUnavailableException::because($this->say('The AI budget of the site is spent for now. The generator works again next month, or as soon as the budget is raised in the AI settings of Thelia.'), $exception),
            $exception instanceof AiRateLimitExceededException => GenerationUnavailableException::because($this->say('Too many requests were sent to the generator in a short time. Wait a few minutes and try again.'), $exception),
            $exception instanceof AiJurisdictionRefusedException => GenerationUnavailableException::because($this->say('The AI settings of the site only allow providers in the European Union, and the connection of the generator is not one. Choose another connection, or change that setting, in the AI settings of Thelia.'), $exception),
            $exception instanceof InactiveAiUsageException => GenerationUnavailableException::because($this->say('The generator is turned off, or no AI connection and model is set up for it. Turn it on and check the connection in the AI settings of Thelia.'), $exception),
            $exception instanceof AiKeyDecryptionException => GenerationUnavailableException::because($this->say('The key of the AI connection cannot be read. Enter it again in the AI settings of Thelia.'), $exception),
            $exception instanceof AiProviderUnavailableException => GenerationUnavailableException::because($this->say('The AI provider does not answer right now. Try again in a few minutes.'), $exception),
            $exception instanceof AiGuardrailBlockedException && ReferenceFileGuardrail::NAME === $exception->verdict->guardrail => GenerationRefusedException::because($this->say(ReferenceFileGuardrail::REJECTED === $exception->verdict->reason
                ? 'The file joined to the brief reads as instructions rather than as a document on the page, and the page was not written. Join another file, or none.'
                : 'The file joined to the brief could not be checked, and the page was not written. The check runs on the usage theliacms.guardrail_reference, which needs a connection and a model of the fast range in the AI settings of Thelia.'), $exception),
            $exception instanceof AiGuardrailBlockedException => GenerationRefusedException::because($this->say('A safety check of the site stopped this request. Reword the brief and try again.'), $exception),
            default => GenerationException::because($this->say('The generator could not reach the AI. Try again, and tell the person who looks after the site if it lasts.'), $exception),
        };
    }

    /**
     * What a call that was cut twice fails with: the message of the failure
     * of the provider, or the one of a stream that just ended.
     */
    public function exceptionForCut(StreamCutException $cut): GenerationException
    {
        return null === $cut->unavailable ? GenerationException::because($this->say($cut->getMessage()), $cut) : $this->exceptionFor($cut->unavailable);
    }

    /**
     * What a call that failed for a reason nobody foresaw tells the editor.
     * The detail is for the log of the site, not for the screen.
     */
    public function unexpected(\Throwable $previous): GenerationException
    {
        return GenerationException::because($this->say('The generation stopped on an unexpected error. Try again, and tell the person who looks after the site if it lasts.'), $previous);
    }

    /**
     * What the guard says when a call is already running for the same page
     * in the same language.
     */
    public function alreadyRunning(\DateTimeInterface $since, ?string $name): GenerationUnavailableException
    {
        return GenerationUnavailableException::because($this->say('A generation is already running for this page in this language (started at %time% by %name%).', [
            '%time%' => $since->format('H:i'),
            '%name%' => $name ?? $this->say('another editor'),
        ]));
    }

    /**
     * What the row of the usage log of the core says the request cost, for
     * a request that failed or was cut: the core prices it from the tokens
     * received. Zero when no row is named, or the row holds no cost.
     */
    public function costOf(?int $coreLogId): float
    {
        return null === $coreLogId ? 0.0 : (float) AiUsageLogQuery::create()->findPk($coreLogId)?->getCost();
    }

    /**
     * What stops a conversation that keeps calling tools and never answers.
     * The message is one of two, so each stays a whole sentence to translate.
     */
    public function tooManyTurns(int $turns, bool $text): GenerationException
    {
        return GenerationException::because($text
            ? $this->say('The model made more than %turns% turns without answering with a text.', ['%turns%' => $turns])
            : $this->say('The model made more than %turns% turns without answering with a document.', ['%turns%' => $turns]));
    }

    /**
     * The row of the usage log of the core the exception speaks of, when it
     * names one: a refused or failed request is logged there too.
     */
    public function logIdOf(AiExceptionInterface $exception): ?int
    {
        return $exception instanceof AiProviderUnavailableException || $exception instanceof AiGuardrailBlockedException ? $exception->logId : null;
    }

    /**
     * @param int|null $outputLimit the limit the request set, null when it took that of the usage
     * @param string   $advice      what the editor can do about a cut answer, in one sentence, as an English message to translate
     *
     * @throws GenerationRefusedException when the provider refused to answer
     * @throws GenerationException        when the output limit cut the answer, or the provider stopped without anything to use
     */
    public function assertUsable(AiResponse $response, ?int $outputLimit, string $advice): void
    {
        switch ($response->finishReason) {
            case AiFinishReason::ContentFilter:
                throw GenerationRefusedException::because($this->say('The model declined to write this. Reword the brief.'));
            case AiFinishReason::MaxTokens:
                throw GenerationException::because($this->cut($response, $outputLimit, $advice));
            case AiFinishReason::Other:
                // A reason the layer does not know is no failure by itself:
                // an answer that holds something goes on to be checked. One
                // that holds nothing has nothing to check.
                if ('' === trim($response->text) && !$response->hasToolCalls()) {
                    throw GenerationException::because($this->say('The AI provider stopped before it had written anything. Try again.'));
                }

                return;
            case AiFinishReason::Completed:
            case AiFinishReason::ToolCalls:
                return;
        }
    }

    /**
     * The limit that cut the answer. The AI layer holds a call under the limit
     * of its usage and of its model as well, set in the AI settings of Thelia:
     * an answer that stopped below the limit asked was cut there, and raising
     * the limit of the module would change nothing.
     */
    private function cut(AiResponse $response, ?int $outputLimit, string $advice): string
    {
        if (null === $outputLimit) {
            return $this->say('The answer was cut at the output limit: %advice%', ['%advice%' => $this->say($advice)]);
        }

        $written = $response->tokenUsage->outputTokens;

        if ($written > 0 && $written < $outputLimit) {
            return $this->say('The answer was cut at %written% tokens, below the %limit% asked: the AI settings of Thelia hold this usage, or its model, to less. Raise the limit there, or ask for less.', ['%written%' => $written, '%limit%' => $outputLimit]);
        }

        return $this->say('The answer was cut at %limit% tokens: %advice%', ['%limit%' => $outputLimit, '%advice%' => $this->say($advice)]);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function say(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }
}
