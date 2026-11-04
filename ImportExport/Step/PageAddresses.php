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

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\TheliaCMS;

/**
 * Where the pages of a file are on this site already, and the address they take.
 */
final readonly class PageAddresses
{
    public function __construct(
        private CmsUrlService $urls,
    ) {
    }

    /**
     * The page already published at the address this document describes, if any.
     *
     * Identity is the address rather than the title: two pages of the same site
     * may well be called "Contact", and none of them may be at the same place
     * in the tree.
     *
     * @param array<string, mixed> $translations
     */
    public function existing(array $translations, int $parentId): ?CmsPage
    {
        foreach ($translations as $locale => $translation) {
            $slug = trim((string) ($translation['slug'] ?? ''));
            $fixed = trim((string) ($translation['fixed_url'] ?? ''), ' /');

            if ('' === $slug && '' === $fixed) {
                continue;
            }

            $url = '' !== $fixed ? $fixed : $this->addressUnder($parentId, (string) $locale, $slug);

            $existing = RewritingUrlQuery::create()
                ->filterByUrl($url)
                ->filterByView(TheliaCMS::PAGE_VIEW)
                ->filterByViewLocale((string) $locale)
                ->filterByRedirected(null, Criteria::ISNULL)
                ->findOne();

            if (null === $existing) {
                continue;
            }

            $page = CmsPageQuery::create()
                ->filterByDeletedAt(null, Criteria::ISNULL)
                ->findPk((int) $existing->getViewId());

            if ($page instanceof CmsPage) {
                return $page;
            }
        }

        return null;
    }

    private function addressUnder(int $parentId, string $locale, string $slug): string
    {
        if (0 === $parentId) {
            return $slug;
        }

        $parent = CmsPageQuery::create()->findPk($parentId);
        $parentUrl = $parent?->getRewrittenUrl($locale);

        return null !== $parentUrl && '' !== $parentUrl ? $parentUrl.'/'.$slug : $slug;
    }

    public function refresh(CmsPage $page, string $locale, ?string $slug, ImportReport $report): void
    {
        try {
            $this->urls->refresh($page, $locale, null !== $slug && '' !== $slug ? $slug : null);
        } catch (\InvalidArgumentException $exception) {
            // A slug this site reserves — the address of its own back office,
            // for one — or a fixed address another object answers on. The page
            // is kept, under an address derived from its title, rather than the
            // whole import being refused.
            $page->setLocale($locale)->setFixedUrl(null)->save();
            $this->urls->refresh($page, $locale);
            $report->warn(\sprintf('Page "%s" could not keep its address in %s: %s', $page->getTitle(), $locale, $exception->getMessage()));
        }
    }
}
