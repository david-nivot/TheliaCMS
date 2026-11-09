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

namespace TheliaCMS\Tests\Integration\Front;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\Lang;
use Thelia\Test\FixtureFactory;
use Thelia\Tools\URL;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Preview\PreviewLink;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The button that takes an administrator from a CMS page of the site to its
 * content screen, requested through the whole kernel: the page, its theme,
 * the hook at the bottom of the body and the cache headers written last.
 */
final class EditButtonTest extends AdminScreenTestCase
{
    private const string BUTTON = 'a.cms-edit-button';

    public function testAnAdministratorWhoMayChangePagesIsTakenToTheContentScreen(): void
    {
        $page = $this->createPage('Nos ateliers');

        $button = $this->pageAt($this->addressOf($page))->filter(self::BUTTON);

        self::assertSame(1, $button->count(), 'One button on the page.');
        self::assertSame($this->screenOf($page), $button->attr('href'));
        self::assertSame($this->getService(TranslatorInterface::class)->trans('Edit this page', [], TheliaCMS::DOMAIN_NAME, $this->locale()), trim($button->text()));
    }

    public function testAPageWithTheButtonIsNobodyElses(): void
    {
        $page = $this->createPage('Nos horaires');

        $this->pageAt($this->addressOf($page));
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    public function testAVisitorSeesNoButton(): void
    {
        $page = $this->createPage('Nos tarifs');
        $this->logOut();

        self::assertSame(0, $this->pageAt($this->addressOf($page))->filter(self::BUTTON)->count());
    }

    public function testAnAdministratorWhoMayOnlyReadThePagesSeesNoButton(): void
    {
        $page = $this->createPage('Notre équipe');
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([
            CmsResources::PAGE => [AccessManager::VIEW],
        ]));

        self::assertSame(0, $this->pageAt($this->addressOf($page))->filter(self::BUTTON)->count());
    }

    public function testTheHomePageOnTheRootOfTheSiteLeadsToItsOwnScreen(): void
    {
        $home = $this->createPage('Accueil');
        TheliaCMS::setConfigValue('home_page_id', (string) $home->getId());

        self::assertSame($this->screenOf($home), $this->pageAt('/')->filter(self::BUTTON)->attr('href'));
    }

    public function testThePageShownOnAMissingAddressLeadsToItsOwnScreen(): void
    {
        $notFound = $this->createPage('Page introuvable');
        TheliaCMS::setConfigValue('404_page_id', (string) $notFound->getId());

        $crawler = $this->client->request('GET', '/une-adresse-qui-n-existe-pas');

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Still a missing address.');
        self::assertSame($this->screenOf($notFound), $crawler->filter(self::BUTTON)->attr('href'));
    }

    public function testThePreviewOfADraftInTheContentScreenShowsNoButton(): void
    {
        $page = $this->createPage('Nos services');
        $preview = $this->getService(PreviewLink::class)->urlFor((int) $page->getId(), $this->locale());

        self::assertSame(0, $this->pageAt($preview)->filter(self::BUTTON)->count());
    }

    private function pageAt(string $address): Crawler
    {
        $crawler = $this->client->request('GET', $address);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), \sprintf('%s answers %d.', $address, $this->client->getResponse()->getStatusCode()));

        return $crawler;
    }

    private function addressOf(CmsPage $page): string
    {
        $url = URL::getInstance()->retrieve(TheliaCMS::PAGE_VIEW, $page->getId(), $this->locale())->toString();

        return (string) parse_url($url, \PHP_URL_PATH);
    }

    private function screenOf(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d/content?edit_language_id=%d', $page->getId(), Lang::getDefaultLanguage()->getId());
    }
}
