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

namespace TheliaCMS\Zone\Admin;

use Thelia\Model\LangQuery;
use Thelia\Model\ModuleQuery;
use TheliaCMS\Http\Admin\AdminControllerSupport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Zone\HeaderZone;

/**
 * What the content screen says when it writes the header zone: where it is
 * drawn, in which languages it is online, and that HeaderHighlights draws in
 * the same place while it is active.
 */
final readonly class ZoneScreenNotes
{
    /** The code of the module whose banner the zone replaces. */
    private const string HEADER_HIGHLIGHTS = 'HeaderHighlights';

    public function __construct(
        private AdminControllerSupport $support,
        private HeaderZone $zones,
    ) {
    }

    /**
     * @return list<string>
     */
    public function for(CmsPage $zone): array
    {
        $notes = [
            $this->support->translate('This is the header zone: what is published here is drawn under the header of every page of the site, the shop included. Any section can go in it; a section of highlights is the usual one.'),
        ];

        $locales = $this->zones->publishedLocales($zone);
        $notes[] = [] === $locales
            ? $this->support->translate('The zone is online in no language: nothing is drawn.')
            : $this->support->translate('The zone is online in: %languages%. A language without a published zone draws nothing.', ['%languages%' => implode(', ', $this->languageNames($locales))]);

        if ($this->headerHighlightsIsActive()) {
            $notes[] = $this->support->translate('The module HeaderHighlights is active and draws in the same place. Once the zone is published, empty its highlights or deactivate the module, or the header shows both.');
        }

        return $notes;
    }

    /**
     * The name the shop gives each language (`lang.title`), in the order of
     * the locales; a locale with no language left keeps its code.
     *
     * @param list<string> $locales
     *
     * @return list<string>
     */
    private function languageNames(array $locales): array
    {
        $names = [];

        foreach (LangQuery::create()->filterByLocale($locales)->find() as $lang) {
            $names[(string) $lang->getLocale()] = (string) $lang->getTitle();
        }

        return array_map(static fn (string $locale): string => '' !== ($names[$locale] ?? '') ? $names[$locale] : $locale, $locales);
    }

    private function headerHighlightsIsActive(): bool
    {
        return ModuleQuery::create()->filterByCode(self::HEADER_HIGHLIGHTS)->filterByActivate(1)->count() > 0;
    }
}
