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

namespace TheliaCMS\Catalogue;

use Thelia\Core\Hook\Theme\ThemeHookInterface;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Twig\ItemContentExtension;

/**
 * Prints the document of a product or a category on its page, on a theme that
 * never calls `cms_item_content()`: on a product, after its description and
 * before the products related to it (`product.content`); on a category, at the
 * bottom (`category.bottom`).
 *
 * Without it, a document written and published in the back office shows
 * nowhere on such a theme: Flexy fires these hooks but has no idea the
 * document exists. A Flexy older than `product.content` shows no document on a
 * product. A content, a folder and a store get no hook from Flexy, so their
 * theme still prints the document itself.
 *
 * On by default, unlike `footer_menu_hook`: a document is written for its
 * page, and one that goes online without showing looks like a fault of the
 * module. A theme that already calls `cms_item_content()` turns
 * `item_content_hook` off, or prints the document twice.
 */
final readonly class ItemContentThemeHook implements ThemeHookInterface
{
    public const string SETTING = 'item_content_hook';

    /** The hook of each page, and the parameter the theme passes the item in. */
    private const array HOOKS = [
        'product.content' => [CatalogueItemType::Product, 'product'],
        'category.bottom' => [CatalogueItemType::Category, 'category'],
    ];

    public function __construct(
        private ItemContentExtension $itemContent,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return isset(self::HOOKS[$hookName]);
    }

    public function render(string $hookName, array $parameters): string
    {
        if (!isset(self::HOOKS[$hookName]) || !filter_var(TheliaCMS::getConfigValue(self::SETTING, '1'), \FILTER_VALIDATE_BOOLEAN)) {
            return '';
        }

        [$type, $parameter] = self::HOOKS[$hookName];

        $html = $this->itemContent->itemContent($type->value, self::idOf($parameters[$parameter] ?? null));

        // Spaced from the sections of the theme around it (`blocks.css`).
        return '' === $html ? '' : '<div class="cms-item-hook">'.$html.'</div>';
    }

    /**
     * Flexy passes the item as the API resource reads it, an array; a theme
     * of its own may pass the model.
     */
    private static function idOf(mixed $item): int|string|null
    {
        $id = match (true) {
            \is_array($item) => $item['id'] ?? null,
            \is_object($item) && method_exists($item, 'getId') => $item->getId(),
            default => null,
        };

        return \is_int($id) || \is_string($id) ? $id : null;
    }
}
