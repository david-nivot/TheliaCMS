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

use Thelia\Domain\Webhook\WebhookEventCatalog;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Webhook\PagePayload;

/**
 * The catalog the back office offers subscriptions from, as the container
 * builds it.
 */
final class WebhookCatalogTest extends CmsIntegrationTestCase
{
    public function testTheCatalogOffersThePageEventsBesideThoseOfTheCore(): void
    {
        /** @var WebhookEventCatalog $catalog */
        $catalog = $this->getService(WebhookEventCatalog::class);

        foreach (['order.created', 'order.status_changed', 'order.paid', 'customer.created', 'product.updated'] as $coreEvent) {
            self::assertTrue($catalog->has($coreEvent), $coreEvent);
        }

        foreach (['cms_page.published', 'cms_page.unpublished'] as $event) {
            self::assertTrue($catalog->has($event), $event);
            self::assertSame(PagePayload::class, $catalog->resource($event));
            self::assertSame([PagePayload::GROUP], $catalog->groups($event));
        }
    }
}
