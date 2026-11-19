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

namespace TheliaCMS\Page\Admin;

use TheliaCMS\Partial\Catalogue\PickablePages;

/**
 * Whether a page can be placed under the page an editor gave: the parent is a
 * number typed or picked by a search, no longer one of the options of a select
 * that left out the page itself, the pages under it, the models and the bin.
 */
final readonly class PageParentCheck
{
    public function __construct(
        private PickablePages $pages,
        private CmsPageAdminRepository $tree,
    ) {
    }

    /**
     * The reason to refuse the parent, untranslated; null when the page can go
     * under it, or at the top level (0).
     */
    public function refusal(?int $pageId, int $parentId, string $locale): ?string
    {
        if (0 === $parentId) {
            return null;
        }

        if (null !== $pageId && ($parentId === $pageId || isset($this->tree->descendantIds($pageId)[$parentId]))) {
            return 'A page cannot go under itself, nor under a page below it.';
        }

        $parent = $parentId > 0 ? $this->pages->find($parentId, $locale) : null;

        return match (true) {
            null === $parent => 'Nothing has this number any more. Search it again by its title.',
            $parent->binned => 'This page is in the bin. Restore it or choose another page.',
            default => null,
        };
    }

    /**
     * The same check for a page an editor chose to show (the 404 page, the page
     * of maintenance, the privacy policy of a form): it has to exist out of
     * the bin. 0 or null is no page.
     */
    public function pageRefusal(?int $pageId, string $locale): ?string
    {
        return null === $pageId || 0 === $pageId ? null : $this->refusal(null, $pageId, $locale);
    }
}
