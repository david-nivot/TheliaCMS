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

namespace TheliaCMS\Menu\Admin;

use TheliaCMS\Menu\MenuTargetType;
use TheliaCMS\Partial\Catalogue\PickableCategories;
use TheliaCMS\Partial\Catalogue\PickableContents;
use TheliaCMS\Partial\Catalogue\PickableFolders;
use TheliaCMS\Partial\Catalogue\PickableItem;
use TheliaCMS\Partial\Catalogue\PickablePages;
use TheliaCMS\Partial\Catalogue\PickableProducts;

/**
 * Whether the record a menu entry is given can be its target: the form takes
 * a number typed or picked by a search, no longer one of the options of a
 * select, so the server checks what the select used to.
 *
 * An offline record is a target: the menu leaves the entry out until it goes
 * online. A page in the bin is not: it is restored first, or another chosen.
 */
final readonly class MenuTargetCheck
{
    public function __construct(
        private PickablePages $pages,
        private PickableContents $contents,
        private PickableFolders $folders,
        private PickableCategories $categories,
        private PickableProducts $products,
    ) {
    }

    /**
     * The reason to refuse the record, untranslated; null when it is a target.
     */
    public function refusal(MenuTargetType $type, int $id, string $locale): ?string
    {
        $item = $this->find($type, $id, $locale);

        return match (true) {
            null === $item => 'Nothing has this number any more. Search it again by its title.',
            $item->binned => 'This page is in the bin. Restore it or choose another page.',
            default => null,
        };
    }

    private function find(MenuTargetType $type, int $id, string $locale): ?PickableItem
    {
        if ($id <= 0) {
            return null;
        }

        return match ($type) {
            MenuTargetType::Page => $this->pages->find($id, $locale),
            MenuTargetType::Content => $this->contents->find($id, $locale),
            MenuTargetType::Folder => $this->folders->find($id, $locale),
            MenuTargetType::Category => $this->categories->find($id, $locale),
            MenuTargetType::Product => $this->products->find($id, $locale),
            MenuTargetType::Url, MenuTargetType::None => null,
        };
    }
}
