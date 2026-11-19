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

namespace TheliaCMS\Content\Kind;

/**
 * A page that cannot become a content of a kind in place: the one thing
 * adopting must not do is leave a page of the site, or the pages under it,
 * without a place.
 */
final class PageCannotBeAdoptedException extends \DomainException
{
    public static function notAnOrdinaryPage(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is the model of a type or already a content of a kind: only an ordinary page can be adopted.', $pageId));
    }

    public static function inTheBin(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is in the bin: restore it before adopting it.', $pageId));
    }

    public static function hasChildren(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d has pages under it, binned ones included: move or delete them before adopting it.', $pageId));
    }

    public static function offlineWithAPublishedVersion(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is offline (hidden or outside its publication window) and holds a published version, which would come out published as a content: put it online or unpublish it first.', $pageId));
    }

    public static function isTheNotFoundPage(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is the page shown for an address that does not exist: choose another one in the settings before adopting it.', $pageId));
    }

    public static function isTheMaintenancePage(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is the page shown during maintenance: choose another one in the settings before adopting it.', $pageId));
    }

    public static function isAPrivacyPolicy(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is the privacy policy of a form: choose another page in the form before adopting it.', $pageId));
    }

    public static function isTheHomePage(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is the home page of the site: choose another one before adopting it.', $pageId));
    }
}
