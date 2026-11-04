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

namespace TheliaCMS\Form\Front;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Form\AntiSpam;
use TheliaCMS\Form\FormCatalog;
use TheliaCMS\Form\FormDefinition;
use TheliaCMS\Form\SubmissionRateLimiter;
use TheliaCMS\Form\SubmissionStore;
use TheliaCMS\Form\SubmissionValidator;
use TheliaCMS\TheliaCMS;

/**
 * Receives what a visitor sent and takes them back to the page they were on.
 *
 * Answering with a redirect rather than with a page is what keeps a reload from
 * sending the same message twice — the outcome travels in the session and the
 * page renders it on the way back.
 *
 * There is no CSRF token here, and that is deliberate: the form is public, so
 * the token would be handed to anyone who asks for the page, and it would
 * protect nothing. What actually has to hold is that the submission is not
 * automated and not replayed, which is what the trap field, the signed stamp
 * spent by the message it carried and the rate limit are for.
 */
final readonly class CmsFormSubmitController
{
    public function __construct(
        private FormCatalog $forms,
        private SubmissionValidator $validator,
        private SubmissionStore $store,
        private SubmissionRateLimiter $rateLimiter,
        private AntiSpam $antiSpam,
        private SubmissionFlash $flash,
        private LangService $langService,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/cms-form/{code}', name: 'cms.form.submit', requirements: ['code' => '[a-z0-9-]+'], methods: ['POST'])]
    public function __invoke(Request $request, string $code): Response
    {
        $record = $this->forms->record($code);

        if (null === $record) {
            throw new NotFoundHttpException();
        }

        $locale = $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
        $definition = $this->forms->definitionOf($record, $locale);
        $back = $this->backTo($request, $definition);

        if (1 !== (int) $record->getActive()) {
            $this->flash->refused($code, $this->trans('This form is closed and no longer accepts messages.'));

            return $back;
        }

        $input = $request->request->all();

        // Silence, on purpose: a robot that filled the trap or posted a stamp
        // this site never issued is told the same thing as everybody else. An
        // error message is feedback, and feedback is what a robot tunes against.
        if (!$this->antiSpam->trapIsUntouched($input) || !$this->antiSpam->stampIsPlausible($code, $input)) {
            $this->logger->info('Thelia CMS: a submission of the "{form}" form was dropped as automated.', ['form' => $code]);
            $this->flash->sent($code);

            return $back;
        }

        $submission = $this->validator->validate($definition->fields, $input);

        if (!$submission->isValid()) {
            $this->flash->rejected($code, $submission);

            return $back;
        }

        // Counted here rather than before the checks above: what has to be
        // capped is the messages that get stored and emailed, not the attempts.
        // Someone mistyping their address six times on a long form would
        // otherwise be locked out for an hour for filling the form in badly.
        if (!$this->rateLimiter->allows($code, $request->getClientIp())) {
            $this->flash->refused($code, $this->trans(
                'Too many messages have been sent from here. Try again in %minutes% minutes.',
                ['%minutes%' => $this->rateLimiter->waitingMinutes()],
            ));

            return $back;
        }

        // Spent here, once the message is about to be kept: a message sent back
        // for a mistyped field goes again with the stamp of the same display.
        // A stamp that already carried a message is a replay, told what a robot
        // is told.
        if (!$this->rateLimiter->spendsStamp($code, (string) AntiSpam::stampOf($input))) {
            $this->logger->info('Thelia CMS: a submission of the "{form}" form was dropped, its stamp had already carried a message.', ['form' => $code]);
            $this->flash->sent($code);

            return $back;
        }

        $this->store->keep($record, $definition, $submission, $locale, $request->getClientIp());

        // A conversion is reported only when the form is set to report one and
        // the person agreed to be contacted: measuring a lead the site is not
        // allowed to act on is measuring nothing, and doing it without the
        // agreement is processing the visit for a purpose they refused.
        $this->flash->sent($code, 1 === (int) $record->getLeadEvent() && $submission->consentGranted());

        return $back;
    }

    /**
     * Back to the page the form was posted from, at the form itself.
     *
     * The address comes from the request headers, so it is checked against the
     * host being served: a redirect built from what the caller sent is an open
     * redirect, and one on a contact form is a phishing tool.
     */
    private function backTo(Request $request, FormDefinition $definition): RedirectResponse
    {
        $path = ReturnPath::of((string) $request->headers->get('referer'), $request->getHost());

        return new RedirectResponse($path.'#'.$definition->anchor());
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME);
    }
}
