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

use Propel\Runtime\ActiveQuery\Criteria as PropelCriteria;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\LangQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\PageAuthorship;
use TheliaCMS\TheliaCMS;

/**
 * The header zone: one hidden page of the CMS (`cms_page.model = 2`) whose
 * published document is drawn in the header of every page of the theme.
 *
 * It is written on the content screen of a page, which gives it drafts,
 * versions and one publication per language for free. Being no page of the
 * site, it has no address, and every read of pages that serves visitors
 * leaves it out on the `model` column (`PublishedPageRepository::isLive()`,
 * `model = 0` in the lists): only `HeaderZoneReader` names it.
 */
final readonly class HeaderZone
{
    /** The value of `cms_page.model` that marks the zone: 0 is a page, 1 the model of a type. */
    public const int MODEL = 2;

    public function __construct(
        private TranslatorInterface $translator,
        private PageAuthorship $authorship,
    ) {
    }

    public function find(?ConnectionInterface $connection = null): ?CmsPage
    {
        return CmsPageQuery::create()
            ->filterByModel(self::MODEL)
            ->filterByDeletedAt(null, PropelCriteria::ISNULL)
            ->orderById()
            ->findOne($connection);
    }

    /**
     * The zone, made when there is none. Written by hand rather than through
     * `CmsPageWriter::saveDraft()`, which gives a page its address.
     */
    public function ensure(): CmsPage
    {
        $zone = $this->find();

        if ($zone instanceof CmsPage) {
            return $zone;
        }

        $zone = (new CmsPage())
            ->setParent(0)
            ->setPosition(0)
            ->setVisible(0)
            ->setPageType('default')
            ->setModel(self::MODEL);

        // Named in every active language, in its own words, so the content screen of each one has a title.
        foreach (LangQuery::create()->filterByActive(true)->find() as $lang) {
            $locale = (string) $lang->getLocale();
            $zone->setLocale($locale)->setTitle($this->translator->trans('Header zone', [], TheliaCMS::DOMAIN_NAME, $locale));
        }

        $this->authorship->stamp($zone)->save();

        return $zone;
    }

    /**
     * @return list<string> the languages the zone is published in
     */
    public function publishedLocales(CmsPage $zone): array
    {
        $locales = CmsPageContentQuery::create()
            ->filterByPageId($zone->getId())
            ->filterByPublishedAt(null, PropelCriteria::ISNOTNULL)
            ->orderByLocale()
            ->select(['Locale'])
            ->find()
            ->toArray();

        return array_values(array_map(strval(...), $locales));
    }
}
