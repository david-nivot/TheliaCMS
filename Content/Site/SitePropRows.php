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

namespace TheliaCMS\Content\Site;

use TheliaCMS\Content\PropSchema;

/**
 * The props of a component as a table of a screen: name, type, label, help,
 * required, and the props of its items. Any schema reads the same way, from
 * the module, the theme, a partial or the site.
 */
final readonly class SitePropRows
{
    public function __construct(
        private SitePropReader $props = new SitePropReader(),
    ) {
    }

    /**
     * @return list<array{name: string, type: string, label: string, help: string|null, required: bool, items: list<string>}>
     */
    public function of(SiteComponent $component): array
    {
        try {
            $schema = $this->props->read($component->props);
        } catch (InvalidSiteComponentException) {
            return [];
        }

        return $this->fromSchema($schema);
    }

    /**
     * @return list<array{name: string, type: string, label: string, help: string|null, required: bool, items: list<string>}>
     */
    public function fromSchema(PropSchema $schema): array
    {
        $rows = [];

        foreach ($schema->props as $prop) {
            $rows[] = [
                'name' => $prop->name,
                'type' => $prop->type->value,
                'label' => $prop->label,
                'help' => $prop->help,
                'required' => $prop->required,
                'items' => array_map(static fn ($item): string => $item->name.' ('.$item->type->value.')', $prop->items),
            ];
        }

        return $rows;
    }
}
