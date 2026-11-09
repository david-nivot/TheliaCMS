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

namespace TheliaCMS\Tests\Integration\Form;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FieldData;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\AntiSpam;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Form\Front\CmsFormSubmitController;
use TheliaCMS\Form\Front\SubmissionFlash;
use TheliaCMS\Form\VisitorFingerprint;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmissionQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A public form from the post to what is kept and mailed: the checks a robot
 * meets, the rate limit, the notification, and the copy the person receives.
 *
 * Goes through the controller of the container, so every piece is the one a
 * site runs; mail is read from the message logger of Symfony, which sees a
 * message whether the shop sends it at once or through a queue.
 */
final class FormSubmissionTest extends CmsIntegrationTestCase
{
    private const string CODE = 'contact-test-chain';

    private const string WRITER = 'jeanne@example.com';

    private ?Request $pushed = null;

    protected function setUp(): void
    {
        parent::setUp();

        ConfigQuery::write('form_firewall_active', '1');
        ConfigQuery::write('form_firewall_attempts', '6');
        ConfigQuery::write('form_firewall_time_to_wait', '60');
        // A shop with no address of its own sends nothing: the mail fails on its sender.
        ConfigQuery::write('store_email', 'shop@example.com');
        ConfigQuery::write('store_name', 'Test shop');

        $this->mailLog()->reset();
    }

    protected function tearDown(): void
    {
        if (null !== $this->pushed) {
            $this->getService(RequestStack::class)->pop();
            $this->pushed = null;
        }

        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testAnAcceptedMessageIsKeptWithTheSenderAddressHashed(): void
    {
        $this->createForm();

        $outcome = $this->post($this->answers(), '203.0.113.10');

        self::assertTrue($outcome['sent'] ?? false);
        $kept = CmsFormSubmissionQuery::create()->filterByFormId($this->form()->getId())->find();
        self::assertCount(1, $kept);
        self::assertSame(self::WRITER, $kept[0]->getEmail());
        self::assertSame($this->getService(VisitorFingerprint::class)->of('203.0.113.10'), $kept[0]->getIpHash());
        self::assertStringNotContainsString('203.0.113.10', (string) $kept[0]->getData());
    }

    /**
     * A robot is told what everybody is told: feedback is what it tunes against.
     */
    public function testTheTrapAndAForgedStampAreToldSentAndKeepNothing(): void
    {
        $this->createForm();

        $trapped = $this->post([...$this->answers(), AntiSpam::TRAP_FIELD => 'https://spam.example'], '203.0.113.11');
        $forged = $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => time().'.forged'], '203.0.113.11');
        $tooFast = $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => $this->stamp(new \DateTimeImmutable())], '203.0.113.11');

        self::assertTrue($trapped['sent'] ?? false);
        self::assertTrue($forged['sent'] ?? false);
        self::assertTrue($tooFast['sent'] ?? false);
        self::assertSame(0, $this->kept());
        self::assertSame([], $this->mails());
    }

    public function testAFieldFilledInWronglyIsSentBackWithItsErrorAndWhatWasTyped(): void
    {
        $this->createForm();

        $outcome = $this->post([...$this->answers(), 'email' => 'not-an-address'], '203.0.113.12');

        self::assertArrayHasKey('email', $outcome['errors'] ?? []);
        self::assertSame('not-an-address', $outcome['entered']['email'] ?? null);
        self::assertSame(0, $this->kept());
    }

    public function testAClosedFormRefusesTheMessage(): void
    {
        $this->createForm(active: false);

        $outcome = $this->post($this->answers(), '203.0.113.13');

        self::assertNotEmpty($outcome['refused'] ?? '');
        self::assertSame(0, $this->kept());
    }

    /**
     * Hitting reply in the mail client answers the person, not the shop.
     */
    public function testTheRecipientsAreToldWithThePersonAsReplyTo(): void
    {
        $this->createForm(recipients: 'sales@example.com, support@example.com');

        $this->post($this->answers(), '203.0.113.14');

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame(['sales@example.com', 'support@example.com'], $this->addresses($mails[0]->getTo()));
        self::assertSame([self::WRITER], $this->addresses($mails[0]->getReplyTo()));
        self::assertStringContainsString('I would like a quote', (string) $mails[0]->getTextBody());
    }

    public function testTheMailLinksToTheAnswerItWasKeptAs(): void
    {
        $this->createForm(recipients: 'sales@example.com');

        $this->post($this->answers(), '203.0.113.15');

        $kept = CmsFormSubmissionQuery::create()->filterByFormId($this->form()->getId())->findOne();
        self::assertNotNull($kept);
        self::assertSame('new', $kept->getStatus(), 'An answer arrives as new.');

        $link = \sprintf('/admin/cms/forms/%d/submissions#cms-submission-%d', $this->form()->getId(), $kept->getId());
        $mails = $this->mails();
        self::assertStringContainsString($link, (string) $mails[0]->getTextBody());
        self::assertStringContainsString($link, (string) $mails[0]->getHtmlBody());
        self::assertMatchesRegularExpression('#https?://[^/\s]+/admin/cms/forms/#', (string) $mails[0]->getTextBody(), 'The link is absolute: it is read in a mail client.');
    }

    public function testTheSeventhMessageFromOneAddressWithinTheHourIsRefused(): void
    {
        $this->createForm();

        for ($sent = 1; $sent <= 6; ++$sent) {
            self::assertTrue($this->post($this->answers(), '203.0.113.15')['sent'] ?? false, \sprintf('Message %d was refused.', $sent));
        }

        $seventh = $this->post($this->answers(), '203.0.113.15');
        $elsewhere = $this->post($this->answers(), '203.0.113.16');

        self::assertNotEmpty($seventh['refused'] ?? '');
        self::assertTrue($elsewhere['sent'] ?? false, 'Another sender is not held back by the first one.');
        self::assertSame(7, $this->kept());
    }

    /**
     * The address of the copy is typed by whoever posts the form: anything the
     * copy repeats of the answers is text the shop sends, in its own name, to
     * an address somebody else chose.
     */
    public function testTheCopyCarriesTheTextOfTheSiteAndNoneOfTheAnswers(): void
    {
        $this->createForm(sendReceipt: true, receiptBody: 'We answer within two days.');

        $this->post([...$this->answers(), 'message' => 'Buy cheap watches at https://spam.example'], '203.0.113.17');

        $receipt = $this->receiptsTo(self::WRITER);
        self::assertCount(1, $receipt);
        self::assertStringContainsString('We answer within two days.', (string) $receipt[0]->getTextBody());
        self::assertStringContainsString('We answer within two days.', (string) $receipt[0]->getHtmlBody());

        foreach (['spam.example', 'Jeanne', self::WRITER] as $answer) {
            self::assertStringNotContainsString($answer, (string) $receipt[0]->getTextBody());
            self::assertStringNotContainsString($answer, (string) $receipt[0]->getHtmlBody());
        }
    }

    public function testWithoutATextOfItsOwnTheCopyOnlySaysTheMessageArrived(): void
    {
        $this->createForm(sendReceipt: true);

        $this->post($this->answers(), '203.0.113.18');

        $receipt = $this->receiptsTo(self::WRITER);
        self::assertCount(1, $receipt);
        self::assertStringNotContainsString('I would like a quote', (string) $receipt[0]->getTextBody());
        self::assertStringNotContainsString('copy of what you sent', (string) $receipt[0]->getTextBody());
    }

    /**
     * Three copies a day to one address, whoever posts: the message itself is
     * still kept and the recipients still told, only the copy is skipped.
     */
    public function testAnAddressReceivesThreeCopiesADayWhoeverSendsThem(): void
    {
        $this->createForm(recipients: 'sales@example.com', sendReceipt: true);

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4'] as $sender) {
            self::assertTrue($this->post([...$this->answers(), 'email' => 'Victim@Example.com'], $sender)['sent'] ?? false);
        }
        $this->post([...$this->answers(), 'email' => 'victim@example.com'], '198.51.100.5');

        self::assertCount(3, $this->receiptsTo('victim@example.com'));
        self::assertSame(5, $this->kept());
        self::assertCount(5, $this->receiptsTo('sales@example.com'));
    }

    /**
     * Each display of the form gets its own stamp, and a stamp is spent by the
     * message it carried: the same post sent again is not a second message.
     */
    public function testAStampCarriesOneMessage(): void
    {
        $this->createForm();
        $stamp = $this->stamp();

        $first = $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => $stamp], '203.0.113.19');
        $again = $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => $stamp], '203.0.113.19');
        $elsewhere = $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => $stamp], '203.0.113.20');

        self::assertTrue($first['sent'] ?? false);
        self::assertTrue($again['sent'] ?? false, 'A replay is told what a robot is told.');
        self::assertTrue($elsewhere['sent'] ?? false);
        self::assertSame(1, $this->kept());
    }

    public function testTwoDisplaysOfTheSameFormInTheSameSecondCarryTwoMessages(): void
    {
        $this->createForm();
        $servedAt = new \DateTimeImmutable('-10 seconds');

        $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => $this->stamp($servedAt)], '203.0.113.21');
        $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => $this->stamp($servedAt)], '203.0.113.22');

        self::assertSame(2, $this->kept());
    }

    /**
     * A message sent back for a mistyped field goes again with the stamp of
     * the page it was typed on: only a message that is kept spends it.
     */
    public function testAMessageSentBackForAFieldGoesThroughWithTheSameStamp(): void
    {
        $this->createForm();
        $stamp = $this->stamp();

        $this->post([...$this->answers(), 'email' => 'not-an-address', AntiSpam::STAMP_FIELD => $stamp], '203.0.113.23');
        $outcome = $this->post([...$this->answers(), AntiSpam::STAMP_FIELD => $stamp], '203.0.113.23');

        self::assertTrue($outcome['sent'] ?? false);
        self::assertSame(1, $this->kept());
    }

    private function createForm(
        bool $active = true,
        string $recipients = '',
        bool $sendReceipt = false,
        string $receiptBody = '',
    ): void {
        $writer = $this->getService(CmsFormWriter::class);
        $form = new CmsForm();

        $writer->save($form, $this->locale(), new FormSettings(
            code: self::CODE,
            active: $active,
            recipients: $recipients,
            storeSubmissions: true,
            retentionDays: 365,
            sendReceipt: $sendReceipt,
            privacyPolicyPageId: null,
            leadEvent: false,
            title: 'Contact',
            description: '',
            submitLabel: '',
            successMessage: '',
            legalNotice: '',
            receiptSubject: '',
            receiptBody: $receiptBody,
        ));

        $writer->saveField($form, null, $this->locale(), new FieldData(FieldType::Text, 'name', 'Name', true, '', '', '', 0));
        $writer->saveField($form, null, $this->locale(), new FieldData(FieldType::Email, 'email', 'Email', true, '', '', '', 0));
        $writer->saveField($form, null, $this->locale(), new FieldData(FieldType::Textarea, 'message', 'Message', true, '', '', '', 4));
    }

    private function form(): CmsForm
    {
        $form = $this->getService(\TheliaCMS\Form\FormCatalog::class)->record(self::CODE);
        self::assertInstanceOf(CmsForm::class, $form);

        return $form;
    }

    /**
     * @return array<string, string>
     */
    private function answers(): array
    {
        return [
            'name' => 'Jeanne',
            'email' => self::WRITER,
            'message' => 'I would like a quote',
            AntiSpam::TRAP_FIELD => '',
            AntiSpam::STAMP_FIELD => $this->stamp(),
        ];
    }

    private function stamp(?\DateTimeImmutable $servedAt = null): string
    {
        return $this->getService(AntiSpam::class)->stamp(self::CODE, $servedAt ?? new \DateTimeImmutable('-10 seconds'));
    }

    /**
     * Posts the form as a visitor from the given address, and reads what the
     * page would show them on the way back.
     *
     * @param array<string, string> $input
     *
     * @return array<string, mixed>
     */
    private function post(array $input, string $ipAddress): array
    {
        $request = Request::create('/cms-form/'.self::CODE, 'POST', $input, server: [
            'REMOTE_ADDR' => $ipAddress,
            'HTTP_REFERER' => 'http://localhost/contact',
        ]);
        $request->setSession($this->session());

        $this->getService(CmsFormSubmitController::class)($request, self::CODE);

        return $this->getService(SubmissionFlash::class)->take(self::CODE);
    }

    /**
     * The flash is written to the session of the main request, which the
     * kernel of the suite may already hold.
     */
    private function session(): Session
    {
        $requests = $this->getService(RequestStack::class);
        $main = $requests->getMainRequest();

        if (null === $main) {
            $main = $this->pushed = Request::create('/contact');
            $requests->push($main);
        }

        if (!$main->hasSession()) {
            $main->setSession(new Session(new MockArraySessionStorage()));
        }

        $session = $main->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }

    private function kept(): int
    {
        return CmsFormSubmissionQuery::create()->filterByFormId($this->form()->getId())->count();
    }

    private function mailLog(): MessageLoggerListener
    {
        $log = static::getContainer()->get('mailer.message_logger_listener');
        self::assertInstanceOf(MessageLoggerListener::class, $log);

        return $log;
    }

    /**
     * @return list<Email>
     */
    private function mails(): array
    {
        return array_values(array_filter(
            $this->mailLog()->getEvents()->getMessages(),
            static fn (object $message): bool => $message instanceof Email,
        ));
    }

    /**
     * @return list<Email>
     */
    private function receiptsTo(string $address): array
    {
        return array_values(array_filter(
            $this->mails(),
            fn (Email $mail): bool => [strtolower($address)] === array_map(strtolower(...), $this->addresses($mail->getTo())),
        ));
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_map(static fn (Address $address): string => $address->getAddress(), array_values($addresses));
    }
}
