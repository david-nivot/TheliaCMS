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

/**
 * The events the module offers to the webhooks of the core, named as the core
 * names its own (`<resource>.<verb>`) and prefixed like the tables of the
 * module, so that no event of the core can ever take one of these names.
 */
final class WebhookEvents
{
    public const string PAGE_PUBLISHED = 'cms_page.published';
    public const string PAGE_UNPUBLISHED = 'cms_page.unpublished';

    /**
     * What `WebhookEventCatalog` reads from `thelia.webhook.events`: the class
     * serialized for a full payload, and its groups.
     *
     * @return array<string, array{resource: class-string, groups: list<string>}>
     */
    public static function definitions(): array
    {
        $page = ['resource' => PagePayload::class, 'groups' => [PagePayload::GROUP]];

        return [
            self::PAGE_PUBLISHED => $page,
            self::PAGE_UNPUBLISHED => $page,
        ];
    }

    private function __construct()
    {
    }
}
