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

namespace TheliaCMS\Tests\Integration\Security;

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Core\Security\AccessManager;
use Thelia\Model\ProfileQuery;
use Thelia\Model\ProfileResourceQuery;
use Thelia\Model\ResourceQuery;
use Thelia\Test\FixtureFactory;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Page\Admin\CmsPageWriter;
use TheliaCMS\Security\CmsResources;
use TheliaCMS\Settings\EditorProfileSeeder;
use TheliaCMS\TheliaCMS;

/**
 * What shows on the site is decided by the right to publish, apart from the
 * right to change the pages: an editor writes a page and its settings, someone
 * allowed to publish puts it online, takes it offline, shows or hides it,
 * brings it back from the bin or makes it the home page.
 */
final class PagePublishRightTest extends AdminScreenTestCase
{
    private const array EDITOR = [CmsResources::PAGE => [AccessManager::VIEW, AccessManager::UPDATE]];

    private const array PUBLISHER = [CmsResources::PAGE_PUBLISH => [AccessManager::VIEW, AccessManager::UPDATE]];

    public function testAnEditorWithoutTheRightToPublishChangesNothingOfWhatShowsOnTheSite(): void
    {
        $offline = $this->createPage('Editor offline', published: false);
        $online = $this->createPage('Editor online');
        $binned = $this->createPage('Editor binned');
        $this->writer()->moveToTrash($binned);
        $home = (string) TheliaCMS::getConfigValue('home_page_id', '0');

        $this->logInAs($this->adminWith(self::EDITOR));
        $server = $this->withToken();

        self::assertSame(403, $this->post('/%d/publish', $offline, $server), 'publish');
        self::assertSame(403, $this->post('/%d/unpublish', $online, $server), 'unpublish');
        self::assertSame(403, $this->post('/%d/visibility', $online, $server), 'visibility');
        self::assertSame(403, $this->post('/%d/set-as-home', $online, $server), 'set as home');
        self::assertSame(403, $this->post('/%d/restore', $binned, $server), 'restore from the bin');

        self::assertFalse($this->isOnline($offline));
        self::assertTrue($this->isOnline($online));
        self::assertSame(1, $this->reloaded($online)->getVisible());
        self::assertSame($home, (string) TheliaCMS::getConfigValue('home_page_id', '0'));
        self::assertNotNull($this->reloaded($binned)->getDeletedAt());
    }

    /**
     * The visibility and the publication window are offered disabled; posted
     * anyway, they are not read, and "Save and publish" only saves.
     */
    public function testTheSettingsOfAnEditorKeepWhatShowsOnTheSite(): void
    {
        $page = $this->createPage('Editor settings', published: false);
        $this->logInAs($this->adminWith(self::EDITOR));

        $screen = $this->screen($this->settingsOf($page));
        self::assertSame(0, $screen->filter('button[name="save_and_publish"]')->count(), '"Save and publish" is not offered.');
        self::assertSame(0, $screen->filter('[data-testid="cms-page-set-as-home"]')->count(), '"Set as home page" is not offered.');

        foreach (['visible', 'publishAt', 'unpublishAt'] as $field) {
            $inputs = $screen->filter(\sprintf('input[name="cms_page[%s]"]', $field));
            self::assertGreaterThan(0, $inputs->count(), $field);
            self::assertSame($inputs->count(), $inputs->filter('[disabled]')->count(), \sprintf('%s is disabled.', $field));
        }

        $form = $screen->filter('form[name="cms_page"]')->form();
        $values = $form->getPhpValues();
        $values['cms_page']['title'] = 'Editor settings saved';
        $values['cms_page']['visible'] = '0';
        $values['cms_page']['publishAt'] = '2030-01-01T10:00';
        $values['cms_page']['unpublishAt'] = '2020-01-01T10:00';
        $values['save_and_publish'] = '1';

        self::assertSame(302, $this->send('POST', $form->getUri(), $values));

        $saved = $this->reloaded($page);
        self::assertSame('Editor settings saved', $saved->setLocale($this->locale())->getTitle(), 'The settings an editor may change are saved.');
        self::assertSame(1, $saved->getVisible());
        self::assertNull($saved->getPublishAt());
        self::assertNull($saved->getUnpublishAt());
        self::assertFalse($this->isOnline($page), 'Saved, not published.');
    }

    public function testTheScreensOfAnEditorOfferNoPublication(): void
    {
        $page = $this->createPage('Editor screens');
        $this->writer()->moveToTrash($this->createPage('Editor screens binned'));
        $this->logInAs($this->adminWith(self::EDITOR));

        $settings = $this->screen($this->settingsOf($page));
        self::assertSame(0, $this->formsPostingTo($settings, '/publish')->count() + $this->formsPostingTo($settings, '/unpublish')->count(), 'No publish nor unpublish on the settings.');

        $list = $this->screen('/admin/cms/pages?q=Editor');
        self::assertSame(0, $this->formsPostingTo($list, '/visibility')->count(), 'No "Show" nor "Hide" on the list.');

        $trash = $this->screen('/admin/cms/pages/trash');
        self::assertSame(0, $this->formsPostingTo($trash, '/restore')->count(), 'No "Restore" in the bin.');
    }

    public function testTheRightToPublishPutsOnlineWithoutTheRightToChange(): void
    {
        $offline = $this->createPage('Reviewer offline', published: false);
        $online = $this->createPage('Reviewer online');
        $binned = $this->createPage('Reviewer binned');
        $this->writer()->moveToTrash($binned);

        $this->logInAs($this->adminWith([CmsResources::PAGE => [AccessManager::VIEW], ...self::PUBLISHER]));
        $server = $this->withToken();

        self::assertSame(302, $this->post('/%d/publish', $offline, $server), 'publish');
        self::assertSame(302, $this->post('/%d/visibility', $online, $server), 'visibility');
        self::assertSame(302, $this->post('/%d/unpublish', $online, $server), 'unpublish');
        self::assertSame(302, $this->post('/%d/restore', $binned, $server), 'restore from the bin');

        self::assertTrue($this->isOnline($offline));
        self::assertFalse($this->isOnline($online));
        self::assertSame(0, $this->reloaded($online)->getVisible());
        self::assertNull($this->reloaded($binned)->getDeletedAt());
    }

    /**
     * Split from the pages, the right to publish starts with what a profile had
     * there: a reader of the pages has VIEW on it, which publishes nothing.
     */
    public function testTheRightToPublishOnViewOnlyPublishesNothing(): void
    {
        $page = $this->createPage('Reader offline', published: false);
        $this->logInAs($this->adminWith([...self::EDITOR, CmsResources::PAGE_PUBLISH => [AccessManager::VIEW]]));

        $settings = $this->screen($this->settingsOf($page));
        self::assertSame(0, $settings->filter('button[name="save_and_publish"]')->count(), '"Save and publish" is not offered.');
        self::assertSame(0, $this->formsPostingTo($settings, '/publish')->count(), 'Nor "Publish".');

        self::assertSame(403, $this->post('/%d/publish', $page, $this->withToken()));
        self::assertFalse($this->isOnline($page));
    }

    public function testTheSeededEditorProfilePublishes(): void
    {
        // The update seeds the rights first, as on a site that updates.
        $migrations = TheliaCMS::everyMigration();
        $version = basename((string) end($migrations), '.sql');
        (new TheliaCMS())->update($version, $version, $this->getPropelConnection());
        ProfileQuery::create()->filterByCode(EditorProfileSeeder::CODE)->delete();
        self::assertTrue((new EditorProfileSeeder())->seed());

        $profile = ProfileQuery::create()->findOneByCode(EditorProfileSeeder::CODE);
        self::assertNotNull($profile);
        $granted = ProfileResourceQuery::create()
            ->filterByProfileId($profile->getId())
            ->filterByResourceId(ResourceQuery::create()->findOneByCode(CmsResources::PAGE_PUBLISH)?->getId())
            ->findOne();

        self::assertTrue((new AccessManager((int) $granted?->getAccess()))->can(AccessManager::UPDATE), 'The profile handed to the client publishes.');
    }

    /**
     * @param array<string, list<string>> $grants
     */
    private function adminWith(array $grants): \Thelia\Model\Admin
    {
        return (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin($grants);
    }

    /**
     * @return array<string, string>
     */
    private function withToken(): array
    {
        return [...self::SAME_SITE, 'HTTP_X_CSRF_TOKEN' => $this->tokenOfTheScripts($this->screen('/admin/cms/pages'))];
    }

    /**
     * @param array<string, string> $server
     */
    private function post(string $action, CmsPage $page, array $server): int
    {
        return $this->send('POST', '/admin/cms/pages'.\sprintf($action, $page->getId()), [], $server);
    }

    private function settingsOf(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d', $page->getId());
    }

    private function formsPostingTo(Crawler $screen, string $ending): Crawler
    {
        return $screen->filter('form')->reduce(
            static fn (Crawler $form): bool => str_ends_with((string) parse_url((string) $form->attr('action'), \PHP_URL_PATH), $ending),
        );
    }

    private function isOnline(CmsPage $page): bool
    {
        return null !== CmsPageContentQuery::create()->filterByPageId($page->getId())->filterByLocale($this->locale())->findOne()?->getPublishedAt();
    }

    private function reloaded(CmsPage $page): CmsPage
    {
        CmsPageTableMap::clearInstancePool();
        $reloaded = CmsPageQuery::create()->findPk($page->getId());
        self::assertInstanceOf(CmsPage::class, $reloaded);

        return $reloaded;
    }

    private function writer(): CmsPageWriter
    {
        return $this->getService(CmsPageWriter::class);
    }
}
