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

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use TheliaCMS\Form\ValidatedSubmission;

/**
 * Carries the outcome of a submission across the redirect that follows it.
 *
 * A form is answered with a redirect rather than with a page, so that reloading
 * the confirmation does not send the message a second time. The refusal and
 * what the visitor had typed therefore have to survive one request, which is
 * what the session is for.
 *
 * Read once and dropped: a message that stays would reappear on the next page.
 */
final readonly class SubmissionFlash
{
    private const string KEY = 'thelia_cms.form';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function rejected(string $formCode, ValidatedSubmission $submission): void
    {
        $this->write($formCode, [
            'errors' => $submission->errors,
            'entered' => $submission->entered,
        ]);
    }

    /**
     * @param bool $reportLead whether the page may tell the measurement tools a
     *                         lead came in — see LeadEvent
     */
    public function sent(string $formCode, bool $reportLead = false): void
    {
        $this->write($formCode, ['sent' => true, 'lead' => $reportLead]);
    }

    /**
     * Said when the form was refused as a whole rather than field by field:
     * too many attempts, a form that has been closed since it was rendered, or
     * a check of another module (SubmissionCheckEvent).
     *
     * @param array<string, mixed> $entered what was typed, given back to the
     *                                      form when it is worth typing again
     */
    public function refused(string $formCode, string $message, array $entered = []): void
    {
        $this->write($formCode, [] === $entered ? ['refused' => $message] : ['refused' => $message, 'entered' => $entered]);
    }

    /**
     * @return array{errors?: array<string, string>, entered?: array<string, mixed>, sent?: bool, lead?: bool, refused?: string}
     */
    public function take(string $formCode): array
    {
        $session = $this->session();

        if (null === $session) {
            return [];
        }

        $all = $session->get(self::KEY);
        $all = \is_array($all) ? $all : [];
        $outcome = $all[$formCode] ?? [];

        unset($all[$formCode]);

        if ([] === $all) {
            $session->remove(self::KEY);
        } else {
            $session->set(self::KEY, $all);
        }

        return \is_array($outcome) ? $outcome : [];
    }

    /**
     * @param array<string, mixed> $outcome
     */
    private function write(string $formCode, array $outcome): void
    {
        $session = $this->session();

        if (null === $session) {
            return;
        }

        $all = $session->get(self::KEY);
        $all = \is_array($all) ? $all : [];
        $all[$formCode] = $outcome;

        $session->set(self::KEY, $all);
    }

    /**
     * `getSession()` throws when there is none, and a page holding a form is
     * also rendered from a command and from the editor preview.
     */
    private function session(): ?SessionInterface
    {
        $request = $this->requestStack->getMainRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
