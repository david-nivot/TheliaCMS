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

/**
 * The draft holds an image nobody described, or a link that says nothing, and
 * the site asked for such a page to be kept offline
 * (`accessibility_check_mode` set to `block`).
 */
final class AccessibilityException extends \DomainException
{
    /**
     * @param list<string> $problems readable, one per kind of problem
     */
    private function __construct(
        public readonly int $pageId,
        public readonly string $locale,
        public readonly array $problems,
    ) {
        parent::__construct(\sprintf('CMS page #%d cannot be published in %s: %s', $pageId, $locale, implode(' ', $problems)));
    }

    /**
     * @param list<string> $problems
     */
    public static function for(int $pageId, string $locale, array $problems): self
    {
        return new self($pageId, $locale, $problems);
    }
}
