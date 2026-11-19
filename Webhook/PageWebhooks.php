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

namespace TheliaCMS\Webhook;

use Thelia\Domain\Webhook\WebhookPublisher;
use TheliaCMS\Model\CmsPage;

/**
 * Tells the subscribers of the webhooks that a page went online or offline in
 * a language. Called once the change is committed; the publisher of the core
 * never throws, so a receiver that fails never fails the publication.
 */
final readonly class PageWebhooks
{
    public function __construct(
        private WebhookPublisher $publisher,
    ) {
    }

    public function published(CmsPage $page, string $locale): void
    {
        $this->publish(WebhookEvents::PAGE_PUBLISHED, $page, $locale);
    }

    public function unpublished(CmsPage $page, string $locale): void
    {
        $this->publish(WebhookEvents::PAGE_UNPUBLISHED, $page, $locale);
    }

    /**
     * The payload reads the page at the locale published: the title and the
     * address of that language. The caller keeps its page at the locale it
     * had.
     */
    private function publish(string $event, CmsPage $page, string $locale): void
    {
        $previous = $page->getLocale();
        $page->setLocale($locale);

        try {
            $this->publisher->publish($event, $page);
        } finally {
            $page->setLocale($previous);
        }
    }
}
