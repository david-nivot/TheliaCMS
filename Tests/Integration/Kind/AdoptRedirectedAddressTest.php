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

namespace TheliaCMS\Tests\Integration\Kind;

use TheliaCMS\Page\Kind\KindContents;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Tests\Integration\Page\BuildsRedirections;
use TheliaCMS\Tests\Support\RecordingCachePurger;

/**
 * `adopt()` removes the addresses of the page in one `DELETE`, which the
 * restricting key `rewriting_url.redirected` refuses when an address left in
 * the table still points at one of them: the transaction rolled back and the
 * page stayed a page.
 */
final class AdoptRedirectedAddressTest extends CmsIntegrationTestCase
{
    use BuildsRedirections;
    use DeclaresTestKinds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->declareTestKinds();
    }

    /**
     * Sabotage: put back the single `DELETE ... WHERE view = 'cmspage'` in `KindContents::adopt()`:
     * SQLSTATE 23000 (1451), the page is not adopted.
     */
    public function testAPageWhoseFormerAddressOfAnotherViewRedirectsToItIsAdopted(): void
    {
        $page = $this->createPage('Guide des tailles');
        $this->redirectFromAnotherView($page, 'ancien-contenu.html');
        $other = $this->createPage('Autre page');
        $this->redirectFromAnotherView($other, 'ancien-autre-contenu.html', viewId: '987655');

        $adopted = $this->purgingWith(KindContents::class, new RecordingCachePurger())->adopt($page, 'test_guide');

        self::assertSame('test_guide', $adopted->getContentKind());
        self::assertFalse($this->pageHasAddresses($page), 'The page has no address.');
        self::assertFalse($this->addressExists('ancien-contenu.html'), 'The address that redirected to it leads nowhere and goes too.');
        self::assertTrue($this->pageHasAddresses($other), 'Another page keeps its address,');
        self::assertTrue($this->addressExists('ancien-autre-contenu.html'), 'and the redirection to it.');
    }

    /**
     * Sabotage: same as above.
     */
    public function testAPageThatWentBackToAnEarlierAddressIsAdopted(): void
    {
        $page = $this->createPage('Guide des tailles');
        $this->newerAddressRedirectingToTheFirst($page, 'retour-adresse.html');

        $adopted = $this->purgingWith(KindContents::class, new RecordingCachePurger())->adopt($page, 'test_guide');

        self::assertSame('test_guide', $adopted->getContentKind());
        self::assertFalse($this->pageHasAddresses($page));
        self::assertFalse($this->addressExists('retour-adresse.html'));
    }
}
