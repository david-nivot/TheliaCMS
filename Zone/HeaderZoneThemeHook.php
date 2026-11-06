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

namespace TheliaCMS\Zone;

use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Domain\Localization\Service\LangService;
use Thelia\Model\Lang;
use TheliaCMS\Partial\PartialRenderer;

/**
 * Draws the header zone of the CMS where a theme fires `layout.header.bottom`
 * (Flexy does on every page, the shop's included).
 *
 * Nothing is drawn until an editor publishes the zone, so there is no setting
 * to turn on. A language with no published zone draws nothing: a banner in
 * another language on every page would be the wrong text.
 */
final readonly class HeaderZoneThemeHook implements ThemeHookInterface
{
    public const string HOOK = 'layout.header.bottom';

    public function __construct(
        private HeaderZoneReader $zone,
        private PartialRenderer $partials,
        private LangService $langService,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return self::HOOK === $hookName;
    }

    public function render(string $hookName, array $parameters): string
    {
        if (self::HOOK !== $hookName) {
            return '';
        }

        $locale = $this->langService->getLang()?->getLocale() ?? Lang::getDefaultLanguage()->getLocale();
        $html = $this->zone->publishedHtml((string) $locale);

        if (null === $html) {
            return '';
        }

        // Its live content (a form, a menu) is drawn when the page is served, as on a page.
        return '<div class="cms-zone cms-zone--header">'.$this->partials->substitute($html, (string) $locale).'</div>';
    }
}
