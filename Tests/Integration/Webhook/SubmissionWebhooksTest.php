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

namespace TheliaCMS\Tests\Integration\Webhook;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Domain\Webhook\WebhookStatus;
use Thelia\Model\WebhookSubscription;
use Thelia\Model\WebhookSubscriptionQuery;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FieldData;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\Answer;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Form\FormCatalog;
use TheliaCMS\Form\SubmissionStore;
use TheliaCMS\Form\ValidatedSubmission;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormSubmissionQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * A message kept from a form reaches the subscribers of the webhooks, with
 * its answers or by its id alone; a message the form does not keep does not.
 */
final class SubmissionWebhooksTest extends CmsIntegrationTestCase
{
    private const string CODE = 'contact-webhook';

    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();
        WebhookSubscriptionQuery::create()->deleteAll($this->getPropelConnection());
        $this->received = [];

        static::getContainer()->set('thelia.webhook.http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);
            $this->received[] = ['type' => $body['type'], 'data' => $body['data']];

            return new MockResponse('ok', ['http_code' => 200]);
        }));
    }

    public function testAKeptMessageIsSentWithItsAnswers(): void
    {
        $this->subscribe();
        $form = $this->createForm(keepsAnswers: true);

        $this->keep($form, consent: true);

        $kept = CmsFormSubmissionQuery::create()->findOneByFormId($form->getId());
        self::assertNotNull($kept);
        self::assertCount(1, $this->received);
        self::assertSame('cms_form.submitted', $this->received[0]['type']);
        $data = $this->received[0]['data'];
        self::assertSame($kept->getId(), $data['id']);
        self::assertSame(self::CODE, $data['form']);
        self::assertSame($this->locale(), $data['locale']);
        self::assertSame('jeanne@example.com', $data['email']);
        self::assertTrue($data['consent']);
        self::assertSame(
            [['name' => 'email', 'value' => 'jeanne@example.com'], ['name' => 'message', 'value' => 'I would like a quote'], ['name' => 'contact_me', 'value' => true]],
            array_map(static fn (array $answer): array => ['name' => $answer['name'], 'value' => $answer['value']], $data['answers']),
        );
        self::assertArrayHasKey('granted_at', $data['answers'][2]);
        self::assertNotEmpty($data['createdAt']);
    }

    public function testAnAgreementRefusedIsSaid(): void
    {
        $this->subscribe();
        $form = $this->createForm(keepsAnswers: true);

        $this->keep($form, consent: false);

        self::assertFalse($this->received[0]['data']['consent']);
    }

    public function testASubscriberOfIdentifiersGetsTheIdOfTheMessage(): void
    {
        $this->subscribe(WebhookStatus::PAYLOAD_IDENTIFIERS);
        $form = $this->createForm(keepsAnswers: true);

        $this->keep($form, consent: true);

        $kept = CmsFormSubmissionQuery::create()->findOneByFormId($form->getId());
        self::assertNotNull($kept);
        self::assertSame([['type' => 'cms_form.submitted', 'data' => ['id' => $kept->getId()]]], $this->received);
    }

    public function testAFormThatKeepsNoAnswersSendsNothing(): void
    {
        $this->subscribe();
        $form = $this->createForm(keepsAnswers: false);

        $this->keep($form, consent: true);

        self::assertSame([], $this->received);
    }

    private function keep(CmsForm $form, bool $consent): void
    {
        $answers = [
            new Answer('email', FieldType::Email, 'Email', 'jeanne@example.com'),
            new Answer('message', FieldType::Textarea, 'Message', 'I would like a quote'),
            new Answer('contact_me', FieldType::Consent, 'You may contact me', $consent),
        ];

        $this->getService(SubmissionStore::class)->keep(
            $form,
            $this->getService(FormCatalog::class)->definitionOf($form, $this->locale()),
            new ValidatedSubmission($answers, [], []),
            $this->locale(),
            '203.0.113.10',
        );
    }

    private function createForm(bool $keepsAnswers): CmsForm
    {
        $writer = $this->getService(CmsFormWriter::class);
        $form = new CmsForm();

        $writer->save($form, $this->locale(), new FormSettings(
            code: self::CODE,
            active: true,
            recipients: '',
            storeSubmissions: $keepsAnswers,
            retentionDays: 365,
            sendReceipt: false,
            privacyPolicyPageId: null,
            leadEvent: false,
            title: 'Contact',
            description: '',
            submitLabel: '',
            successMessage: '',
            legalNotice: '',
            receiptSubject: '',
            receiptBody: '',
        ));

        $writer->saveField($form, null, $this->locale(), new FieldData(FieldType::Email, 'email', 'Email', true, '', '', '', 0));
        $writer->saveField($form, null, $this->locale(), new FieldData(FieldType::Textarea, 'message', 'Message', true, '', '', '', 4));
        $writer->saveField($form, null, $this->locale(), new FieldData(FieldType::Consent, 'contact_me', 'You may contact me', false, '', '', '', 0));

        return $form;
    }

    private function subscribe(string $payloadMode = WebhookStatus::PAYLOAD_FULL): void
    {
        (new WebhookSubscription())
            ->setTitle('Integration')
            ->setUrl('https://hooks.example.com/in')
            ->setSecret('whsec_integration')
            ->setEvents(json_encode(['cms_form.submitted'], \JSON_THROW_ON_ERROR))
            ->setPayloadMode($payloadMode)
            ->setStatus(WebhookStatus::SUBSCRIPTION_ACTIVE)
            ->save($this->getPropelConnection());
    }
}
