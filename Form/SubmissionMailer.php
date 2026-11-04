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

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\TheliaCMS;

/**
 * Sends a submission on to the people who have to read it, and optionally a
 * receipt to the person who wrote it.
 *
 * The receipt carries the text of the site and nothing of the answers. Its
 * address is typed by whoever posts the form: a receipt that repeated what was
 * posted would let anyone make the shop write any text, in its own name, to an
 * address of their choosing.
 *
 * `sendSimpleEmailMessage` rather than the templated path of the core: that one
 * needs a session and a parser to resolve a message template, so it is not safe
 * from a command — and a form is also submitted through one during a test.
 */
final readonly class SubmissionMailer
{
    public function __construct(
        private MailerFactory $mailer,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<Answer> $answers
     */
    public function notifyRecipients(CmsForm $form, FormDefinition $definition, array $answers, string $locale): void
    {
        $recipients = Recipients::parse($form->getRecipients());

        if ([] === $recipients) {
            return;
        }

        $subject = $this->trans('New message from the site: %form%', ['%form%' => $definition->title], $locale);
        $intro = $this->trans('Someone filled in the "%form%" form.', ['%form%' => $definition->title], $locale);

        $this->send(
            to: array_fill_keys($recipients, ''),
            subject: $subject,
            intro: $intro,
            answers: $answers,
            locale: $locale,
            // So that hitting reply in a mail client answers the person rather
            // than the shop itself.
            replyTo: $this->replyTo($answers),
        );
    }

    public function sendReceipt(CmsForm $form, string $to, string $locale): void
    {
        $form->setLocale($locale);

        $subject = trim((string) $form->getReceiptSubject());
        $text = trim((string) $form->getReceiptBody());
        $text = '' !== $text ? $text : $this->trans('Thank you for writing to us. We have received your message.', [], $locale);

        $this->deliver(
            to: [$to => ''],
            subject: '' !== $subject ? $subject : $this->trans('We have received your message', [], $locale),
            htmlBody: \sprintf('<p>%s</p>', nl2br(htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE))),
            textBody: $text,
            replyTo: [],
        );
    }

    /**
     * @param array<string, string> $to
     * @param list<Answer>          $answers
     * @param array<string, string> $replyTo
     */
    private function send(array $to, string $subject, string $intro, array $answers, string $locale, array $replyTo): void
    {
        $this->deliver($to, $subject, $this->htmlBody($intro, $answers, $locale), $this->textBody($intro, $answers, $locale), $replyTo);
    }

    /**
     * @param array<string, string> $to
     * @param array<string, string> $replyTo
     */
    private function deliver(array $to, string $subject, string $htmlBody, string $textBody, array $replyTo): void
    {
        $from = [(string) ConfigQuery::getStoreEmail() => (string) ConfigQuery::getStoreName()];

        try {
            $this->mailer->sendSimpleEmailMessage($from, $to, $subject, $htmlBody, $textBody, replyTo: $replyTo);
        } catch (\Throwable $throwable) {
            // The visitor is told their message went through, because as far as
            // the site is concerned it did: it is stored. A mail server that is
            // down is not their problem to solve on a contact page.
            $this->logger->error('Thelia CMS: a form submission could not be emailed: {reason}', [
                'reason' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param list<Answer> $answers
     */
    private function htmlBody(string $intro, array $answers, string $locale): string
    {
        $rows = '';

        foreach ($answers as $answer) {
            $rows .= \sprintf(
                '<tr><th scope="row" style="text-align:left;padding:4px 12px 4px 0;vertical-align:top">%s</th><td style="padding:4px 0">%s</td></tr>',
                htmlspecialchars($answer->label, \ENT_QUOTES | \ENT_SUBSTITUTE),
                nl2br(htmlspecialchars($this->asText($answer, $locale), \ENT_QUOTES | \ENT_SUBSTITUTE)),
            );
        }

        return \sprintf(
            '<p>%s</p><table>%s</table>',
            htmlspecialchars($intro, \ENT_QUOTES | \ENT_SUBSTITUTE),
            $rows,
        );
    }

    /**
     * @param list<Answer> $answers
     */
    private function textBody(string $intro, array $answers, string $locale): string
    {
        $lines = [$intro, ''];

        foreach ($answers as $answer) {
            $lines[] = $answer->label.': '.$this->asText($answer, $locale);
        }

        return implode("\n", $lines);
    }

    private function asText(Answer $answer, string $locale): string
    {
        return $answer->asText($this->trans('Yes', [], $locale), $this->trans('No', [], $locale));
    }

    /**
     * @param list<Answer> $answers
     *
     * @return array<string, string>
     */
    private function replyTo(array $answers): array
    {
        $email = $this->emailOf($answers);

        return null === $email ? [] : [$email => ''];
    }

    /**
     * @param list<Answer> $answers
     */
    private function emailOf(array $answers): ?string
    {
        foreach ($answers as $answer) {
            if (FieldType::Email === $answer->type && \is_string($answer->value) && '' !== $answer->value) {
                return $answer->value;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $message, array $parameters, string $locale): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME, $locale);
    }
}
