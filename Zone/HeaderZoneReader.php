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

use Propel\Runtime\ActiveQuery\Criteria;
use TheliaCMS\Model\CmsPageContentQuery;

/**
 * The published document of the header zone in a language, in one query.
 *
 * It names `model = 2` on purpose: the one read of pages that wants the zone,
 * where every other leaves it out. Not `PublishedPageRepository::find()`,
 * which reads the page, its tag, its fields and its images: this runs on
 * every page view of the site.
 */
final readonly class HeaderZoneReader
{
    public function publishedHtml(string $locale): ?string
    {
        $rows = CmsPageContentQuery::create()
            ->filterByLocale($locale)
            ->filterByPublishedAt(null, Criteria::ISNOTNULL)
            ->useCmsPageQuery()
                ->filterByModel(HeaderZone::MODEL)
                ->filterByDeletedAt(null, Criteria::ISNULL)
            ->endUse()
            ->select(['PublishedHtml'])
            ->limit(1)
            ->find()
            ->toArray();
        $html = $rows[0] ?? null;

        return \is_string($html) && '' !== trim($html) ? $html : null;
    }
}
