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

namespace TheliaCMS\Settings;

use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use TheliaCMS\Http\PageCachePolicy;
use TheliaCMS\Page\TrashRetention;
use TheliaCMS\TheliaCMS;

/**
 * The settings of the module, read as values rather than as strings.
 *
 * They live in `module_config`, so they are scoped to the module and go away
 * when it is uninstalled. Everything that reads them goes through here: a
 * listener asking `'1' === getConfigValue(...)` in its own way is how a flag
 * ends up meaning two different things in two places.
 *
 * Routes read them too, through `service('theliacms_settings')` in their
 * condition: a route that only exists on a showcase site is one the router
 * skips on a shop, so the next route claiming the path answers instead.
 */
#[AsRoutingConditionService(alias: CmsSettings::ROUTING_ALIAS)]
final readonly class CmsSettings
{
    /** Name routes call these settings by in their condition. */
    public const string ROUTING_ALIAS = 'theliacms_settings';

    public const string SITE_MODE = 'site_mode';
    public const string NOT_FOUND_PAGE = '404_page_id';
    public const string MAINTENANCE_ACTIVE = 'maintenance_active';
    public const string MAINTENANCE_ALLOWLIST = 'maintenance_allowlist';
    public const string MAINTENANCE_PAGE = 'maintenance_page_id';
    public const string TRASH_RETENTION_DAYS = 'trash_retention_days';
    public const string HTTP_CACHE_TTL = PageCachePolicy::TTL_SETTING;
    public const string HEADING_CHECK_MODE = 'heading_check_mode';
    public const string ACCESSIBILITY_CHECK_MODE = 'accessibility_check_mode';

    /**
     * How long a client is asked to wait before coming back. Long enough for a
     * deployment, short enough that a crawler comes back the same day — and it
     * is what keeps a maintenance page from being indexed in place of the site.
     */
    public const int RETRY_AFTER = 3600;

    public function siteMode(): SiteMode
    {
        return SiteMode::fromStorage(TheliaCMS::getConfigValue(self::SITE_MODE));
    }

    public function isShowcase(): bool
    {
        return $this->siteMode()->isShowcase();
    }

    public function notFoundPageId(): ?int
    {
        return $this->pageId(self::NOT_FOUND_PAGE);
    }

    public function maintenancePageId(): ?int
    {
        return $this->pageId(self::MAINTENANCE_PAGE);
    }

    /**
     * How many days a deleted page waits in the bin before it goes for good.
     * Zero means it waits until somebody deletes it by hand.
     */
    public function trashRetentionDays(): int
    {
        $raw = TheliaCMS::getConfigValue(self::TRASH_RETENTION_DAYS);

        // Never configured is not the same as configured to zero: an unset
        // setting takes the default, a zero says "keep them".
        if (null === $raw || '' === trim((string) $raw)) {
            return TrashRetention::DEFAULT_DAYS;
        }

        return TrashRetention::normalize((int) $raw);
    }

    /** Seconds a shared cache may keep a page. Zero means it may not. */
    public function httpCacheTtl(): int
    {
        return max(0, min(
            (int) TheliaCMS::getConfigValue(self::HTTP_CACHE_TTL, (string) PageCachePolicy::DEFAULT_TTL),
            PageCachePolicy::MAX_TTL,
        ));
    }

    /**
     * Whether a page whose heading structure is broken is kept offline, rather
     * than published with a warning to its author.
     */
    public function headingCheckBlocks(): bool
    {
        return 'block' === (string) TheliaCMS::getConfigValue(self::HEADING_CHECK_MODE, 'warn');
    }

    /**
     * Whether a page with an undescribed image or a link that says nothing is
     * kept offline, rather than published with a warning to its author. Its
     * own setting: a site that keeps broken headings offline did not ask for
     * this one.
     */
    public function accessibilityCheckBlocks(): bool
    {
        return 'block' === (string) TheliaCMS::getConfigValue(self::ACCESSIBILITY_CHECK_MODE, 'warn');
    }

    public function isMaintenanceActive(): bool
    {
        return '1' === (string) TheliaCMS::getConfigValue(self::MAINTENANCE_ACTIVE, '0');
    }

    /**
     * Addresses that reach the site while it is under maintenance: single IPs,
     * v4 or v6, and CIDR ranges.
     *
     * @return list<string>
     */
    public function maintenanceAllowlist(): array
    {
        $raw = (string) TheliaCMS::getConfigValue(self::MAINTENANCE_ALLOWLIST, '');

        // Typed by a human in a textarea: one per line, or separated by commas,
        // or both.
        $entries = preg_split('/[\s,;]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $entries), static fn (string $entry): bool => '' !== $entry));
    }

    public function save(
        SiteMode $mode,
        ?int $notFoundPageId,
        bool $maintenanceActive,
        string $maintenanceAllowlist,
        ?int $maintenancePageId,
        ?int $trashRetentionDays,
        ?int $httpCacheTtl,
    ): void {
        TheliaCMS::setConfigValue(self::SITE_MODE, $mode->value);
        TheliaCMS::setConfigValue(self::NOT_FOUND_PAGE, (string) ($notFoundPageId ?? ''));
        TheliaCMS::setConfigValue(self::MAINTENANCE_ACTIVE, $maintenanceActive ? '1' : '0');
        TheliaCMS::setConfigValue(self::MAINTENANCE_ALLOWLIST, trim($maintenanceAllowlist));
        TheliaCMS::setConfigValue(self::MAINTENANCE_PAGE, (string) ($maintenancePageId ?? ''));
        TheliaCMS::setConfigValue(self::TRASH_RETENTION_DAYS, (string) TrashRetention::normalize($trashRetentionDays));
        TheliaCMS::setConfigValue(self::HTTP_CACHE_TTL, (string) max(0, min((int) $httpCacheTtl, PageCachePolicy::MAX_TTL)));
    }

    private function pageId(string $key): ?int
    {
        $id = (int) TheliaCMS::getConfigValue($key, '0');

        return $id > 0 ? $id : null;
    }
}
