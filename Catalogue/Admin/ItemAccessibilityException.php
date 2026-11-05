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

namespace TheliaCMS\Catalogue\Admin;

use TheliaCMS\Catalogue\CatalogueItemType;

/**
 * The document of an item holds an image nobody described, or a link that says
 * nothing, and the site keeps such a document offline
 * (`accessibility_check_mode` set to `block`).
 */
final class ItemAccessibilityException extends \DomainException
{
    /**
     * @param list<string> $problems readable, one per kind of problem
     */
    private function __construct(string $message, public readonly array $problems)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $problems
     */
    public static function for(CatalogueItemType $type, int $id, string $locale, array $problems): self
    {
        return new self(\sprintf('The CMS content of the %s #%d cannot be published in %s: %s', $type->value, $id, $locale, implode(' ', $problems)), $problems);
    }
}
