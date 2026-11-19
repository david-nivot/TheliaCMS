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

use Propel\Runtime\Propel;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Page\PageTypeWriter;
use TheliaCMS\Page\TrashPurger;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The callers of `PageAddressRemover` that `adopt()` and the bin do not cover:
 * a redirection from another view to an address of the page no longer stops
 * them.
 */
final class PageAddressRemovalCallersTest extends CmsIntegrationTestCase
{
    use BuildsRedirections;

    /**
     * Sabotage: put back the single `DELETE ... WHERE view = 'cmspage'` in
     * `TheliaCMS::preDeactivation()`: SQLSTATE 23000 (1451).
     */
    public function testDeactivatingTheModuleRemovesTheAddressesAndTheRedirectionsToThem(): void
    {
        $page = $this->createPage('Page');
        $this->redirectFromAnotherView($page, 'ancien-contenu.html');

        (new TheliaCMS())->preDeactivation(Propel::getConnection('TheliaMain'));

        self::assertFalse($this->pageHasAddresses($page));
        self::assertFalse($this->addressExists('ancien-contenu.html'));
    }

    /**
     * Sabotage: put back the `DELETE` of the addresses of each page in
     * `TrashPurger::purge()`: SQLSTATE 23000 (1451). The page reached the bin
     * by another route, so it still has its address.
     */
    public function testThePurgeOfTheBinDeletesAPageWhoseAddressIsRedirectedTo(): void
    {
        $page = $this->createPage('Page jetée');
        $this->redirectFromAnotherView($page, 'ancien-contenu.html');
        CmsPageQuery::create()->findPk($page->getId())?->setDeletedAt(new \DateTimeImmutable('-10 years'))->save();

        $purged = $this->getService(TrashPurger::class)->purge();

        self::assertSame(1, $purged);
        self::assertNull(CmsPageQuery::create()->findPk($page->getId()));
        self::assertFalse($this->addressExists('ancien-contenu.html'));
    }

    /**
     * Sabotage: put back the `DELETE` of the addresses of the model in
     * `PageTypeWriter::delete()`: SQLSTATE 23000 (1451).
     */
    public function testDeletingATypeDeletesItsModelWhoseAddressIsRedirectedTo(): void
    {
        $writer = $this->getService(PageTypeWriter::class);
        $writer->add('recipe');
        $model = $this->writer()->createModel('recipe', $this->locale(), 'Modèle de recette');
        $address = $this->firstAddressOf($model);
        $this->redirection('content', '987654', 'ancien-modele.html', $address);

        $writer->delete('recipe');

        self::assertNull(CmsPageQuery::create()->findPk($model->getId()));
        self::assertFalse($this->pageHasAddresses($model));
        self::assertFalse($this->addressExists('ancien-modele.html'));
    }
}
