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

use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Page\CmsUrlService;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;

/**
 * The home page is served at the root of the site: its settings screen offers
 * no address, saving it keeps the address it has, and the fields come back on a
 * page that stops being the home page.
 */
final class HomePageAddressScreenTest extends AdminScreenTestCase
{
    private const string SLUG = 'field-of-the-home';

    public function testTheScreenOfTheHomePageOffersNoAddress(): void
    {
        $home = $this->givenTheHomePage('Accueil sans adresse');

        $screen = $this->screen($this->editUrl($home));

        self::assertSame(0, $screen->filter('input[name="cms_page[slug]"]')->count());
        self::assertSame(0, $screen->filter('input[name="cms_page[fixedUrl]"]')->count());
        self::assertSame(1, $screen->filter('[data-testid="cms-page-home-address-note"]')->count());
        self::assertGreaterThan(0, $screen->filter('input[name="cms_page[title]"]')->count());
    }

    public function testTheScreenOfAnOrdinaryPageOffersTheAddress(): void
    {
        $page = $this->createPage('Page ordinaire avec adresse');

        $screen = $this->screen($this->editUrl($page));

        self::assertSame(1, $screen->filter('input[name="cms_page[slug]"]')->count());
        self::assertSame(1, $screen->filter('input[name="cms_page[fixedUrl]"]')->count());
        self::assertSame(0, $screen->filter('[data-testid="cms-page-home-address-note"]')->count());
    }

    public function testSavingTheHomePageKeepsItsAddressAndFixedAddress(): void
    {
        $home = $this->givenTheHomePage('Accueil enregistré', self::SLUG);
        $home->setLocale($this->locale())->setFixedUrl('legacy/home.html')->save();
        // As the settings screen stores a fixed address: the address follows it.
        $this->urls()->refresh($home, $this->locale());
        $addressBefore = $this->addressOf($home);

        $form = $this->screen($this->editUrl($home))->filter('form[name="cms_page"]')->form();
        $values = $form->getPhpValues();
        $values['cms_page']['title'] = 'Accueil renommé';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $reloaded = $this->reloaded($home)->setLocale($this->locale());
        self::assertSame('Accueil renommé', $reloaded->getTitle(), 'The save went through, no "required" error.');
        self::assertSame(self::SLUG, $this->urls()->slugOf($reloaded, $this->locale()));
        self::assertSame('legacy/home.html', $this->urls()->fixedAddressOf($reloaded, $this->locale()));
        self::assertSame($addressBefore, $this->addressOf($home));
    }

    public function testTheFieldsComeBackOnAPageThatStopsBeingTheHomePage(): void
    {
        $first = $this->givenTheHomePage('Première accueil', self::SLUG);
        $second = $this->createPage('Seconde accueil');

        $screen = $this->screen($this->editUrl($second));
        $token = $this->tokenOfTheForm($screen, \sprintf('/admin/cms/pages/%d/set-as-home', $second->getId()));

        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/set-as-home', $second->getId()), ['_token' => $token]));

        $screenOfTheFirst = $this->screen($this->editUrl($first));
        self::assertSame(1, $screenOfTheFirst->filter('input[name="cms_page[slug]"]')->count());
        self::assertSame(self::SLUG, $screenOfTheFirst->filter('input[name="cms_page[slug]"]')->attr('value'), 'The address it had is still there.');

        $screenOfTheSecond = $this->screen($this->editUrl($second));
        self::assertSame(0, $screenOfTheSecond->filter('input[name="cms_page[slug]"]')->count());
    }

    private function givenTheHomePage(string $title, ?string $slug = null): CmsPage
    {
        $page = $this->createPage($title);

        if (null !== $slug) {
            $this->urls()->refresh($page, $this->locale(), $slug);
        }

        $this->writer()->setAsHome($page);

        return $page;
    }

    private function editUrl(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d', $page->getId());
    }

    private function addressOf(CmsPage $page): ?string
    {
        return $this->reloaded($page)->getRewrittenUrl($this->locale());
    }

    private function reloaded(CmsPage $page): CmsPage
    {
        CmsPageTableMap::clearInstancePool();
        $reloaded = CmsPageQuery::create()->findPk($page->getId());
        self::assertInstanceOf(CmsPage::class, $reloaded);

        return $reloaded;
    }

    private function urls(): CmsUrlService
    {
        return $this->getService(CmsUrlService::class);
    }

    private function writer(): CmsPageWriter
    {
        return $this->getService(CmsPageWriter::class);
    }
}
