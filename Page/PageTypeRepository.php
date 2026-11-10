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

namespace TheliaCMS\Page;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\CmsPageTypeDefinition;
use TheliaCMS\Model\CmsPageTypeDefinitionQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Field\FieldDefinitions;

/**
 * Reads the page types of the site; PageTypeWriter changes them. Rendering
 * never reads this table: a page carries the code of its type, and the code
 * alone picks its template.
 *
 * Every code read here is checked again: a row written by hand that is not a
 * code would otherwise reach the page form and the routes of the types screen.
 */
final readonly class PageTypeRepository
{
    /**
     * Every code, the default type first and the others in alphabetical order.
     *
     * @return list<string>
     */
    public function codes(?ConnectionInterface $connection = null): array
    {
        $codes = array_map(
            static fn (mixed $code): string => (string) $code,
            CmsPageTypeDefinitionQuery::create()->orderByCode()->select(['Code'])->find($connection)->getData(),
        );

        return array_values(array_unique([PageTypeCode::DEFAULT, ...array_filter($codes, PageTypeCode::isValid(...))]));
    }

    /**
     * Compared as a code, not as the column compares it: `cms_page_type.code`
     * ignores case, so `DEFAULT` would otherwise find `default`.
     */
    public function exists(string $code, ?ConnectionInterface $connection = null): bool
    {
        if (!PageTypeCode::isValid($code)) {
            return false;
        }

        return PageTypeCode::DEFAULT === $code
            || CmsPageTypeDefinitionQuery::create()->filterByCode($code)->exists($connection);
    }

    /**
     * Pages of this type, the binned ones included: a page restored from the
     * bin would otherwise come back with a type the site no longer has. The
     * model of the type is not one of them: it goes with the type.
     */
    public function countPagesOfType(string $code): int
    {
        return CmsPageQuery::create()->filterByPageType($code)->filterByModel(0)->count();
    }

    /**
     * The pages of the type, the binned ones included and the models left
     * out: the rows countPagesOfType() and pageCountsByType() count.
     *
     * @return list<CmsPage>
     */
    public function pagesOfType(string $code): array
    {
        return iterator_to_array(CmsPageQuery::create()->filterByPageType($code)->filterByModel(0)->orderById()->find(), false);
    }

    /**
     * The same count for every type at once, the binned pages included and
     * the models left out.
     *
     * @return array<string, int>
     */
    public function pageCountsByType(): array
    {
        $counts = [];

        $rows = CmsPageQuery::create()
            ->filterByModel(0)
            ->withColumn('COUNT(*)', 'pages')
            ->groupBy(CmsPageTableMap::COL_PAGE_TYPE)
            ->select([CmsPageTableMap::COL_PAGE_TYPE, 'pages'])
            ->find();

        foreach ($rows as $row) {
            $counts[(string) $row[CmsPageTableMap::COL_PAGE_TYPE]] = (int) $row['pages'];
        }

        return $counts;
    }

    /**
     * The type as a template: its names, its brief and its fields. A code
     * the site has no row for — the default type, before anyone describes
     * it — is a type without any of them.
     */
    public function details(string $code, ?ConnectionInterface $connection = null): PageTypeDetails
    {
        $code = PageTypeCode::orDefault($code);
        $type = CmsPageTypeDefinitionQuery::create()->filterByCode($code)->findOne($connection);

        return $type instanceof CmsPageTypeDefinition ? self::detailsOf($type) : PageTypeDetails::plain($code);
    }

    /**
     * The fields of the pages of the type, read alone: what serving a page
     * needs of its type, without the names and the brief.
     */
    public function fieldsOf(string $code): FieldDefinitions
    {
        $json = CmsPageTypeDefinitionQuery::create()
            ->filterByCode(PageTypeCode::orDefault($code))
            ->select(['Fields'])
            ->findOne();

        return FieldDefinitions::fromJson(\is_string($json) ? $json : null);
    }

    /**
     * Every type of the site as a template, in the order of codes().
     *
     * @return array<string, PageTypeDetails> by code
     */
    public function allDetails(): array
    {
        $rows = [];

        foreach (CmsPageTypeDefinitionQuery::create()->find() as $type) {
            if (PageTypeCode::isValid((string) $type->getCode())) {
                $rows[(string) $type->getCode()] = self::detailsOf($type);
            }
        }

        $details = [];

        foreach ($this->codes() as $code) {
            $details[$code] = $rows[$code] ?? PageTypeDetails::plain($code);
        }

        return $details;
    }

    /**
     * The model of the type, out of the bin: the page new pages of the type
     * are copied from.
     */
    public function modelOf(string $code, ?ConnectionInterface $connection = null): ?CmsPage
    {
        return CmsPageQuery::create()
            ->filterByPageType($code)
            ->filterByModel(1)
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->orderById()
            ->findOne($connection);
    }

    /**
     * The id of the model of each type that has one, out of the bin.
     *
     * @return array<string, int> by code
     */
    public function modelIds(): array
    {
        $ids = [];

        $rows = CmsPageQuery::create()
            ->filterByModel(1)
            ->filterByDeletedAt(null, Criteria::ISNULL)
            ->orderById(Criteria::DESC)
            ->select([CmsPageTableMap::COL_PAGE_TYPE, CmsPageTableMap::COL_ID])
            ->find();

        foreach ($rows as $row) {
            $ids[(string) $row[CmsPageTableMap::COL_PAGE_TYPE]] = (int) $row[CmsPageTableMap::COL_ID];
        }

        return $ids;
    }

    private static function detailsOf(CmsPageTypeDefinition $type): PageTypeDetails
    {
        $titles = [];
        $descriptions = [];

        foreach ($type->getCmsPageTypeDefinitionI18ns() as $translation) {
            $locale = (string) $translation->getLocale();

            if ('' !== trim((string) $translation->getTitle())) {
                $titles[$locale] = trim((string) $translation->getTitle());
            }

            if ('' !== trim((string) $translation->getDescription())) {
                $descriptions[$locale] = trim((string) $translation->getDescription());
            }
        }

        return new PageTypeDetails(
            code: (string) $type->getCode(),
            titles: $titles,
            descriptions: $descriptions,
            briefText: $type->getBrief(),
            briefTone: $type->getBriefTone(),
            briefAudience: $type->getBriefAudience(),
            fields: FieldDefinitions::fromJson($type->getFields()),
            instructions: $type->getInstructions(),
        );
    }
}
