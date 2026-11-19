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

namespace TheliaCMS\Tests\Integration\Page;

use Thelia\Model\RewritingUrl;
use Thelia\Model\RewritingUrlQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\TheliaCMS;

/**
 * Rows of `rewriting_url` that redirect to an address of a page, in the two
 * layouts the foreign key `redirected` (restricting) turns a bulk `DELETE` of
 * the addresses of the page down on.
 */
trait BuildsRedirections
{
    /**
     * An address of another view that redirects to the first address of the page,
     * such as the former `content` address of a content a page took over.
     */
    protected function redirectFromAnotherView(CmsPage $page, string $url, string $view = 'content', string $viewId = '987654'): RewritingUrl
    {
        return $this->redirection($view, $viewId, $url, $this->firstAddressOf($page));
    }

    /**
     * An address of the page itself, newer than the one it redirects to: the
     * page went back to an earlier address, and the row written last points at
     * the first. A `DELETE` meets the older row first.
     */
    protected function newerAddressRedirectingToTheFirst(CmsPage $page, string $url): RewritingUrl
    {
        return $this->redirection(TheliaCMS::PAGE_VIEW, (string) $page->getId(), $url, $this->firstAddressOf($page));
    }

    protected function firstAddressOf(CmsPage $page): RewritingUrl
    {
        $first = RewritingUrlQuery::create()
            ->filterByView(TheliaCMS::PAGE_VIEW)
            ->filterByViewId((string) $page->getId())
            ->orderById()
            ->findOne();

        self::assertNotNull($first, 'The fixture needs a page that has an address.');

        return $first;
    }

    protected function redirection(string $view, string $viewId, string $url, RewritingUrl $target): RewritingUrl
    {
        $row = (new RewritingUrl())
            ->setUrl($url)
            ->setView($view)
            ->setViewId($viewId)
            ->setViewLocale($this->locale());
        // Written as an update: inserting a row that redirects makes the core
        // point every other row of the same object at it (`RewritingUrl::postInsert()`),
        // which is not the layout of these cases.
        $row->save();
        $row->setRedirected((int) $target->getId())->save();

        return $row;
    }

    protected function addressExists(string $url): bool
    {
        return null !== RewritingUrlQuery::create()->findOneByUrl($url);
    }

    protected function pageHasAddresses(CmsPage $page): bool
    {
        return RewritingUrlQuery::create()
            ->filterByView(TheliaCMS::PAGE_VIEW)
            ->filterByViewId((string) $page->getId())
            ->exists();
    }
}
