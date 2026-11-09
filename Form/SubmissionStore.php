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

namespace TheliaCMS\Form;

use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmission;

/**
 * What happens once a submission has been accepted: it is kept if the form says
 * so, it is sent on, and the person is answered.
 *
 * Storing and emailing are two settings rather than one behaviour: a site that
 * only wants the message in a mailbox should not be accumulating personal data
 * in a database it will forget to empty.
 */
final readonly class SubmissionStore
{
    public function __construct(
        private SubmissionMailer $mailer,
        private VisitorFingerprint $fingerprints,
        private SubmissionRateLimiter $rateLimiter,
    ) {
    }

    public function keep(
        CmsForm $record,
        FormDefinition $definition,
        ValidatedSubmission $submission,
        string $locale,
        ?string $ipAddress,
    ): void {
        $now = new \DateTimeImmutable();

        $kept = null;

        if (1 === (int) $record->getStoreSubmissions()) {
            $kept = (new CmsFormSubmission())
                ->setFormId($record->getId())
                ->setLocale($locale)
                ->setEmail($submission->email())
                ->setData(SubmissionData::encode($submission->answers, $now))
                ->setIpHash($this->fingerprints->of($ipAddress));
            $kept->save();
        }

        $this->mailer->notifyRecipients($record, $definition, $submission->answers, $locale, null === $kept ? null : (int) $kept->getId());

        $to = $submission->email();

        // Past the ceiling the copy is skipped without a word: the message is
        // kept and the recipients told, which is all the visitor asked for.
        if (1 === (int) $record->getSendReceipt() && null !== $to && $this->rateLimiter->allowsReceiptTo($to)) {
            $this->mailer->sendReceipt($record, $to, $locale);
        }
    }
}
