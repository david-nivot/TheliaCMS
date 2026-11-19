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

use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;

/**
 * Binning removes the addresses of the pages in one `DELETE`, which the
 * restricting key `rewriting_url.redirected` refuses when an address left in
 * the table still points at one of them.
 */
final class PageTrashRedirectedAddressTest extends CmsIntegrationTestCase
{
    use BuildsRedirections;

    /**
     * Sabotage: put back the single `DELETE ... WHERE view = 'cmspage'` in
     * `CmsPageWriter::moveToTrash()`: SQLSTATE 23000 (1451), the page is not binned.
     */
    public function testAPageWhoseFormerAddressOfAnotherViewRedirectsToItIsBinned(): void
    {
        $parent = $this->createPage('Parente');
        $child = $this->createPage('Enfant', parent: (int) $parent->getId());
        $this->redirectFromAnotherView($child, 'ancien-enfant.html');
        $other = $this->createPage('Autre page');
        $this->redirectFromAnotherView($other, 'ancien-autre-contenu.html', viewId: '987655');

        $binned = $this->writer()->moveToTrash($parent);

        self::assertSame([(int) $parent->getId(), (int) $child->getId()], $binned);
        self::assertNotNull(CmsPageQuery::create()->findPk($child->getId())?->getDeletedAt());
        self::assertFalse($this->pageHasAddresses($parent));
        self::assertFalse($this->pageHasAddresses($child));
        self::assertFalse($this->addressExists('ancien-enfant.html'));
        self::assertTrue($this->pageHasAddresses($other), 'Another page keeps its address,');
        self::assertTrue($this->addressExists('ancien-autre-contenu.html'), 'and the redirection to it.');
    }

    /**
     * Sabotage: same as above.
     */
    public function testAPageThatWentBackToAnEarlierAddressIsBinned(): void
    {
        $page = $this->createPage('Page revenue');
        $this->newerAddressRedirectingToTheFirst($page, 'retour-adresse.html');

        $this->writer()->moveToTrash($page);

        self::assertNotNull(CmsPageQuery::create()->findPk($page->getId())?->getDeletedAt());
        self::assertFalse($this->pageHasAddresses($page));
        self::assertFalse($this->addressExists('retour-adresse.html'));
    }
}
