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

namespace TheliaCMS\ImportExport\Step;

use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteMode;
use TheliaCMS\TheliaCMS;

/**
 * The settings of a file, the pages they name being the ones just imported.
 */
final readonly class SettingsStep
{
    public function __construct(
        private CmsSettings $settings,
    ) {
    }

    /**
     * @param array<string, int> $pageIds
     */
    public function import(SiteDocument $document, ImportReport $report, array $pageIds): void
    {
        $settings = $document->settings();

        if ([] === $settings) {
            return;
        }

        $page = static fn (?string $uid): ?int => null !== $uid ? ($pageIds[$uid] ?? null) : null;

        $homePageId = $page($settings['home_page'] ?? null);

        $this->settings->save(
            SiteMode::fromStorage($settings['site_mode'] ?? null),
            $page($settings['404_page'] ?? null),
            $this->settings->isMaintenanceActive(),
            implode("\n", $this->settings->maintenanceAllowlist()),
            $page($settings['maintenance_page'] ?? null),
            (int) ($settings['trash_retention_days'] ?? 30),
            (int) ($settings['http_cache_ttl'] ?? 0),
        );

        TheliaCMS::setConfigValue('home_page_id', (string) ($homePageId ?? ''));

        $report->created('settings');
    }
}
