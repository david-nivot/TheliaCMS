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

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Thelia\Api\Bridge\Propel\Service\ApiResourcePropelTransformerService;
use Thelia\Domain\Webhook\WebhookEventCatalog;
use Thelia\Domain\Webhook\WebhookPayloadBuilder;
use Thelia\Domain\Webhook\WebhookStatus;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Webhook\PagePayload;

/**
 * The page payload goes through the builder of the core as a resource of its
 * own would, though no route serves it.
 */
final class PagePayloadTest extends CmsIntegrationTestCase
{
    private const string EVENT = 'cms_page.published';

    public function testTheFullPayloadDescribesThePageInTheLanguagePublished(): void
    {
        $page = $this->createPage('Nos engagements');
        $page->setLocale($this->locale());

        $data = $this->builder()->data(self::EVENT, $page, WebhookStatus::PAYLOAD_FULL);

        $content = CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne();
        self::assertNotNull($content);
        self::assertSame(
            ['id', 'locale', 'title', 'type', 'url', 'publishedAt'],
            array_keys($data),
        );
        self::assertSame($page->getId(), $data['id']);
        self::assertSame($this->locale(), $data['locale']);
        self::assertSame('Nos engagements', $data['title']);
        self::assertSame('default', $data['type']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/nos-engagements$#', $data['url']);
        self::assertSame($content->getPublishedAt()?->format(\DateTimeInterface::RFC3339), $data['publishedAt']);
    }

    public function testTheHomePageIsGivenAtTheRootOfTheSite(): void
    {
        $page = $this->createPage('Accueil');
        $page->setLocale($this->locale());
        $previous = TheliaCMS::getConfigValue('home_page_id');
        TheliaCMS::setConfigValue('home_page_id', (string) $page->getId());

        try {
            $data = $this->builder()->data(self::EVENT, $page, WebhookStatus::PAYLOAD_FULL);
        } finally {
            TheliaCMS::setConfigValue('home_page_id', $previous);
        }

        self::assertMatchesRegularExpression('#^https?://[^/]+/$#', $data['url']);
    }

    public function testTheIdentifiersPayloadIsThePageId(): void
    {
        $page = $this->createPage('Nos engagements');
        $page->setLocale($this->locale());

        self::assertSame(['id' => $page->getId()], $this->builder()->data(self::EVENT, $page, WebhookStatus::PAYLOAD_IDENTIFIERS));
    }

    private function builder(): WebhookPayloadBuilder
    {
        /** @var ApiResourcePropelTransformerService $transformer */
        $transformer = $this->getService(ApiResourcePropelTransformerService::class);
        /** @var NormalizerInterface $normalizer */
        $normalizer = static::getContainer()->get('serializer');

        return new WebhookPayloadBuilder(
            new WebhookEventCatalog([self::EVENT => ['resource' => PagePayload::class, 'groups' => [PagePayload::GROUP]]]),
            $transformer,
            $normalizer,
        );
    }
}
