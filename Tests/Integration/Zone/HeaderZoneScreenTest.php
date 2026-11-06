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

namespace TheliaCMS\Tests\Integration\Zone;

use Thelia\Core\Security\AccessManager;
use Thelia\Model\ModuleQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Content\Document;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\Zone\HeaderZone;

/**
 * The header zone as the editor and the visitor meet it: the entry of the
 * back office, its rights, its screen, and the front of the shop.
 */
final class HeaderZoneScreenTest extends AdminScreenTestCase
{
    private const string ENTRY = '/admin/cms/zones/header';

    public function testTheEntryMakesTheZoneOnceAndOpensItsContentScreen(): void
    {
        $screen = $this->screen(self::ENTRY);
        self::assertNull($this->zone(), 'Looking at the entry writes nothing.');
        $token = $this->tokenOfTheForm($screen, self::ENTRY);

        self::assertSame(302, $this->send('POST', self::ENTRY, ['_token' => $token]));
        $zone = $this->zone();
        self::assertNotNull($zone, 'The button makes the zone.');
        self::assertStringContainsString(\sprintf('/admin/cms/pages/%d/content', $zone->getId()), (string) $this->client->getResponse()->headers->get('Location'));

        self::assertSame(302, $this->send('POST', self::ENTRY, ['_token' => $token]));
        self::assertSame(1, CmsPageQuery::create()->filterByModel(HeaderZone::MODEL)->count(), 'A second visit reuses the zone.');

        // The link now goes straight to the screen of the zone.
        self::assertSame(302, $this->send('GET', self::ENTRY));
        self::assertStringContainsString(\sprintf('/admin/cms/pages/%d/content', $zone->getId()), (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testTheContentScreenOfTheZoneTellsWhereItIsDrawnAndInWhichLanguages(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $url = \sprintf('/admin/cms/pages/%d/content', $zone->getId());

        $notes = $this->screen($url)->filter('[data-testid="cms-content-notes"]')->text();
        self::assertStringContainsString('header zone', $notes);
        self::assertStringContainsString('online in no language', $notes);

        $writer = $this->writer();
        $writer->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Un bandeau.</p>'));
        $writer->publish($zone, $this->locale());

        self::assertStringContainsString('online in: '.$this->locale(), $this->screen($url)->filter('[data-testid="cms-content-notes"]')->text());
    }

    public function testTheScreenWarnsWhileHeaderHighlightsIsActive(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $url = \sprintf('/admin/cms/pages/%d/content', $zone->getId());

        ModuleQuery::create()->filterByCode('HeaderHighlights')->update(['Activate' => 0]);
        self::assertStringNotContainsString('HeaderHighlights', $this->screen($url)->filter('[data-testid="cms-content-notes"]')->text());

        ModuleQuery::create()->filterByCode('HeaderHighlights')->update(['Activate' => 1]);
        self::assertStringContainsString('HeaderHighlights', $this->screen($url)->filter('[data-testid="cms-content-notes"]')->text());
    }

    public function testTheSideNavigationAndTheSettingsLeadToTheZone(): void
    {
        self::assertSame(1, $this->screen('/admin/cms/pages')->filter('[data-testid="bo-nav-cms-header-zone"]')->count());
        self::assertSame(1, $this->screen('/admin/cms/settings')->filter('[data-testid="cms-settings-header-zone"]')->count());
    }

    public function testTheZoneCannotBeTrashedNorSetOnlineFromTheScreens(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $token = $this->tokenOfTheScripts($this->screen('/admin/cms/pages'));

        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/delete', $zone->getId()), ['_token' => $token]));
        self::assertSame(302, $this->send('POST', \sprintf('/admin/cms/pages/%d/visibility', $zone->getId()), ['_token' => $token]));

        CmsPageTableMap::clearInstancePool();
        $stored = CmsPageQuery::create()->findPk($zone->getId());
        self::assertNull($stored?->getDeletedAt(), 'The zone is not in the bin.');
        self::assertSame(0, $stored?->getVisible(), 'The zone is not visible.');
    }

    public function testWithoutTheRightToChangePagesTheZoneCanBeSeenButNotMade(): void
    {
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([CmsResources::PAGE => [AccessManager::VIEW]]));
        $token = $this->tokenOfTheScripts($this->screen('/admin/cms/pages'));

        self::assertSame(200, $this->send('GET', self::ENTRY), 'The entry is a read.');
        self::assertSame(403, $this->send('POST', self::ENTRY, ['_token' => $token]), 'Making the zone takes the right to change pages.');
        self::assertNull($this->zone());
    }

    public function testWithoutTheRightToSeePagesTheEntryIsRefused(): void
    {
        $this->logInAs((new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([CmsResources::SETTINGS => [AccessManager::VIEW]]));

        self::assertSame(403, $this->send('GET', self::ENTRY));
    }

    public function testThePublishedZoneIsDrawnOnAPageOfTheCmsAndOnAPageOfTheShop(): void
    {
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, $this->locale(), Document::richText('<h2>Soldes</h2><p>Bandeau cyanotype de la zone.</p>'));
        $page = $this->createPage('Page qui porte la zone');
        $path = (string) parse_url((string) $page->getUrl($this->locale()), \PHP_URL_PATH);

        // Draft only: drawn nowhere.
        $this->client->request('GET', $path);
        self::assertStringNotContainsString('cms-zone--header', (string) $this->client->getResponse()->getContent());

        $this->writer()->publish($zone, $this->locale());

        $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Bandeau cyanotype de la zone.', (string) $this->client->getResponse()->getContent(), 'A page of the CMS carries the zone.');
        self::assertStringContainsString('cms-zone cms-zone--header', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Bandeau cyanotype de la zone.', (string) $this->client->getResponse()->getContent(), 'A page of the shop carries it too.');
    }

    public function testThePageOfTheZoneAnswers404AtItsAddress(): void
    {
        $page = $this->createPage('Zone qui a une adresse');
        $path = (string) parse_url((string) $page->getUrl($this->locale()), \PHP_URL_PATH);

        $this->client->request('GET', $path);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Online, the page answers: what follows is the zone flag.');

        $this->getPropelConnection()->exec(\sprintf('UPDATE cms_page SET model = 2, visible = 1 WHERE id = %d', $page->getId()));
        CmsPageTableMap::clearInstancePool();

        $this->client->request('GET', $path);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'A visible zone holding a published version is no page of the site.');
    }

    private function zone(): ?CmsPage
    {
        CmsPageTableMap::clearInstancePool();

        return $this->getService(HeaderZone::class)->find();
    }

    private function writer(): \TheliaCMS\Page\Admin\CmsPageWriter
    {
        return $this->getService(\TheliaCMS\Page\Admin\CmsPageWriter::class);
    }
}
