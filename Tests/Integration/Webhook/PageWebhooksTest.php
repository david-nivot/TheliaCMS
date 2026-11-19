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
use Thelia\Model\WebhookDeliveryQuery;
use Thelia\Model\WebhookSubscription;
use Thelia\Model\WebhookSubscriptionQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\ContentWriter;
use TheliaCMS\Content\Editing\StaleDraftException;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\Kind\DeclaresTestKinds;
use TheliaCMS\Webhook\PageWebhooks;

/**
 * A page that goes online or offline reaches the subscribers of the webhooks
 * of the core, and nothing else does.
 */
final class PageWebhooksTest extends CmsIntegrationTestCase
{
    use DeclaresTestKinds;

    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareTestKinds();
        WebhookSubscriptionQuery::create()->deleteAll($this->getPropelConnection());
        $this->received = [];

        static::getContainer()->set('thelia.webhook.http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);
            $this->received[] = ['type' => $body['type'], 'data' => $body['data']];

            return new MockResponse('ok', ['http_code' => 200]);
        }));
    }

    public function testAPublicationSendsThePageInTheLanguagePublished(): void
    {
        $this->subscribe(['cms_page.published']);

        $page = $this->createPage('Nos engagements');

        self::assertCount(1, $this->received);
        self::assertSame('cms_page.published', $this->received[0]['type']);
        self::assertSame($page->getId(), $this->received[0]['data']['id']);
        self::assertSame($this->locale(), $this->received[0]['data']['locale']);
        self::assertSame('Nos engagements', $this->received[0]['data']['title']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/nos-engagements$#', $this->received[0]['data']['url']);
    }

    public function testAPublicationAgainIsSentAgain(): void
    {
        $page = $this->createPage('Nos engagements');
        $this->subscribe(['cms_page.published']);

        $this->writer()->publish($page, $this->locale());

        self::assertCount(1, $this->received);
    }

    public function testASubscriberOfIdentifiersGetsThePageId(): void
    {
        $this->subscribe(['cms_page.published'], WebhookStatus::PAYLOAD_IDENTIFIERS);

        $page = $this->createPage('Nos engagements');

        self::assertSame([['type' => 'cms_page.published', 'data' => ['id' => $page->getId()]]], $this->received);
    }

    public function testTakingAPageOfflineSendsItsEvent(): void
    {
        $page = $this->createPage('Nos engagements');
        $this->subscribe(['cms_page.unpublished']);

        $this->writer()->unpublish($page, $this->locale());

        self::assertCount(1, $this->received);
        self::assertSame('cms_page.unpublished', $this->received[0]['type']);
        self::assertSame($page->getId(), $this->received[0]['data']['id']);
    }

    public function testTakingOfflineAPageThatWasNotOnlineSendsNothing(): void
    {
        $page = $this->createPage('Brouillon', published: false);
        $this->subscribe(['cms_page.unpublished']);

        $this->writer()->unpublish($page, $this->locale());

        self::assertSame([], $this->received);
    }

    public function testARefusedPublicationSendsNothing(): void
    {
        $page = $this->createPage('Nos engagements', published: false);
        $this->subscribe(['cms_page.published']);

        try {
            $this->writer()->publish($page, $this->locale(), readVersion: 999);
            self::fail('A draft read at another version must not go online.');
        } catch (StaleDraftException) {
        }

        self::assertSame([], $this->received);
    }

    public function testASubscriberOfAnotherEventGetsNothing(): void
    {
        $this->subscribe(['cms_page.unpublished']);

        $this->createPage('Nos engagements');

        self::assertSame([], $this->received);
        self::assertSame(0, WebhookDeliveryQuery::create()->count($this->getPropelConnection()));
    }

    public function testAContentOfAKindSendsNothing(): void
    {
        $this->subscribe(['cms_page.published', 'cms_page.unpublished']);

        $content = $this->kindContent('test_guide', 'Guide des tailles');
        $this->writer()->unpublish($content, $this->locale());

        self::assertSame([], $this->received);
    }

    public function testAnArticleOfTheCatalogueSendsNothing(): void
    {
        $this->subscribe(['cms_page.published', 'cms_page.unpublished']);
        $factory = new FixtureFactory($this->getPropelConnection());
        $content = $factory->content($factory->folder(), ['title' => 'Un contenu', 'locale' => $this->locale()]);
        $target = $this->itemTarget(CatalogueItemType::Content, (int) $content->getId(), $this->locale());
        $writer = $this->getService(ContentWriter::class);
        $writer->saveContent($target, Document::richText('<h2>Un contenu</h2>'));

        $writer->publish($target);
        $writer->unpublish($target);

        self::assertSame([], $this->received);
    }

    public function testTheCallerGetsItsPageBackAtTheLocaleItHad(): void
    {
        $this->subscribe(['cms_page.published']);
        $page = $this->createPage('Nos engagements', published: false);
        $page->setLocale('xx_XX');

        $this->getService(PageWebhooks::class)->published($page, $this->locale());

        self::assertSame('Nos engagements', $this->received[0]['data']['title']);
        self::assertSame('xx_XX', $page->getLocale());
    }

    /**
     * @param list<string> $events
     */
    private function subscribe(array $events, string $payloadMode = WebhookStatus::PAYLOAD_FULL): void
    {
        (new WebhookSubscription())
            ->setTitle('Integration')
            ->setUrl('https://hooks.example.com/in')
            ->setSecret('whsec_integration')
            ->setEvents(json_encode($events, \JSON_THROW_ON_ERROR))
            ->setPayloadMode($payloadMode)
            ->setStatus(WebhookStatus::SUBSCRIPTION_ACTIVE)
            ->save($this->getPropelConnection());
    }
}
