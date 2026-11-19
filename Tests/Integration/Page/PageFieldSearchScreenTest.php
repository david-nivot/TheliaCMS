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

use Symfony\Component\DomCrawler\Crawler;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Model\CmsFormQuery;
use TheliaCMS\Model\CmsPage;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsFormTableMap;
use TheliaCMS\Model\Map\CmsPageTableMap;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The screens where a page is designated, besides the menus: the parent of a
 * page, the 404 and maintenance pages of the settings, the privacy policy page
 * of a form. Each searches the page rather than listing them all, and the
 * server refuses a page the field cannot take.
 */
final class PageFieldSearchScreenTest extends AdminScreenTestCase
{
    private const string SETTINGS = '/admin/cms/settings';

    private ?int $notFoundPageBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notFoundPageBefore = $this->cmsSettings()->notFoundPageId();
    }

    protected function tearDown(): void
    {
        TheliaCMS::setConfigValue(CmsSettings::NOT_FOUND_PAGE, (string) ($this->notFoundPageBefore ?? ''));

        parent::tearDown();
    }

    public function testTheParentOfAPageIsSearchedLeavingOutThePageAndThePagesUnderIt(): void
    {
        $page = $this->createPage('Page dont on cherche le parent', false);
        $screen = $this->screen($this->pageAddress($page));

        $field = $screen->filter('input[name="cms_page[parent]"]');
        self::assertSame('number', $field->attr('type'), 'Without the script, the number is typed.');
        self::assertSame('/admin/cms/partials/sources/pages?locale='.$this->locale().'&except='.$page->getId(), $field->attr('data-cms-picker'), 'Named in the language edited.');
        self::assertNotNull($field->attr('data-cms-picker-searchable'));
        self::assertSame(0, $screen->filter('select[name="cms_page[parent]"]')->count(), 'The pages are no longer listed.');
        self::assertSame(1, $screen->filter('script[src*="record-picker.js"]')->count());
        self::assertSame('/admin/cms/partials/sources/pages?locale='.$this->locale(), $this->screen('/admin/cms/pages/new')->filter('input[name="cms_page[parent]"]')->attr('data-cms-picker'), 'A new page has nothing to leave out.');
    }

    public function testAPageIsPlacedUnderAnotherAndBackAtTheTopLevelWhenEmptied(): void
    {
        $parent = $this->createPage('Rubrique choisie par recherche', false);
        $page = $this->createPage('Article à ranger', false);

        $this->savePage($page, (string) $parent->getId());
        self::assertSame((int) $parent->getId(), $this->reload($page)->getParent());

        $this->savePage($page, '');
        self::assertSame(0, $this->reload($page)->getParent(), 'An emptied field puts the page back at the top level.');
    }

    public function testAPageCannotGoUnderItselfNorUnderAPageBelowIt(): void
    {
        $page = $this->createPage('Rubrique à ne pas boucler', false);
        $child = $this->createPage('Sous-rubrique', false);
        $child->setParent((int) $page->getId())->save();

        foreach ([(int) $page->getId(), (int) $child->getId()] as $parent) {
            $screen = $this->savePage($page, (string) $parent);

            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'The form is shown again.');
            self::assertStringContainsString('A page cannot go under itself, nor under a page below it.', $screen->text());
            self::assertSame(0, $this->reload($page)->getParent());
        }

        $this->savePage($page, '999999999');
        self::assertStringContainsString('Nothing has this number any more', (string) $this->client->getResponse()->getContent());
    }

    public function testThe404PageIsSearchedAndAPageInTheBinIsRefusedRatherThanForgotten(): void
    {
        $kept = $this->createPage('Page 404 gardée');
        $binned = $this->createPage('Page 404 partie à la corbeille');
        $screen = $this->screen(self::SETTINGS);

        foreach (['notFoundPageId', 'maintenancePageId'] as $name) {
            $field = $screen->filter(\sprintf('input[name="cms_settings[%s]"]', $name));
            self::assertSame('/admin/cms/partials/sources/pages?locale='.$this->locale(), $field->attr('data-cms-picker'), $name);
            self::assertSame(0, $screen->filter(\sprintf('select[name="cms_settings[%s]"]', $name))->count(), $name.' still lists the pages.');
        }

        self::assertSame(1, $screen->filter('script[src*="record-picker.js"]')->count());

        self::assertSame(302, $this->saveSettings((string) $kept->getId()));
        self::assertSame((int) $kept->getId(), $this->cmsSettings()->notFoundPageId());

        $binned->setDeletedAt(new \DateTimeImmutable())->save();
        TheliaCMS::setConfigValue(CmsSettings::NOT_FOUND_PAGE, (string) $binned->getId());

        $form = $this->screen(self::SETTINGS)->filter('form[name="cms_settings"]')->form();
        self::assertSame((string) $binned->getId(), $form['cms_settings[notFoundPageId]']->getValue(), 'The page in the bin is still the one held, not an empty choice.');
        self::assertSame(200, $this->send('POST', $form->getUri(), $form->getPhpValues()), 'Saving another setting is refused while the page is in the bin.');
        self::assertStringContainsString('This page is in the bin', (string) $this->client->getResponse()->getContent());
        self::assertSame((int) $binned->getId(), $this->cmsSettings()->notFoundPageId(), 'It is not forgotten silently.');

        self::assertSame(302, $this->saveSettings(''));
        self::assertNull($this->cmsSettings()->notFoundPageId(), 'Emptied, the theme answers.');
    }

    public function testThePrivacyPolicyPageOfAFormIsSearchedAndAPageInTheBinIsRefused(): void
    {
        $page = $this->createPage('Politique de confidentialité recherchée');
        $form = (new CmsForm())->setCode('privacy-search-'.bin2hex(random_bytes(4)))->setPrivacyPolicyPageId((int) $page->getId());
        $form->save();
        $address = '/admin/cms/forms/'.$form->getId();

        $settings = $this->formSettings($this->screen($address));
        self::assertSame('/admin/cms/partials/sources/pages?locale='.$this->locale(), $settings->filter('input[name$="[privacyPolicyPageId]"]')->attr('data-cms-picker'));
        self::assertSame(1, $this->screen($address)->filter('script[src*="record-picker.js"]')->count());

        $page->setDeletedAt(new \DateTimeImmutable())->save();
        $fields = $this->formSettings($this->screen($address))->form();

        self::assertSame(200, $this->send('POST', $fields->getUri(), $fields->getPhpValues()));
        self::assertStringContainsString('This page is in the bin', (string) $this->client->getResponse()->getContent());
        CmsFormTableMap::clearInstancePool();
        self::assertSame((int) $page->getId(), (int) CmsFormQuery::create()->findPk($form->getId())?->getPrivacyPolicyPageId());
    }

    private function savePage(CmsPage $page, string $parent): Crawler
    {
        $form = $this->screen($this->pageAddress($page))->filter('[data-testid="cms-page-save"]')->form();
        $form['cms_page[parent]'] = $parent;

        return $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], self::SAME_SITE);
    }

    private function saveSettings(string $notFoundPage): int
    {
        $form = $this->screen(self::SETTINGS)->filter('form[name="cms_settings"]')->form();
        $form['cms_settings[notFoundPageId]'] = $notFoundPage;

        return $this->send('POST', $form->getUri(), $form->getPhpValues());
    }

    private function formSettings(Crawler $screen): Crawler
    {
        $form = $screen->filter('form')->reduce(static fn (Crawler $node): bool => $node->filter('input[name$="[privacyPolicyPageId]"]')->count() > 0);
        self::assertSame(1, $form->count(), 'The screen of the form has no settings form.');

        return $form;
    }

    private function pageAddress(CmsPage $page): string
    {
        return \sprintf('/admin/cms/pages/%d', $page->getId());
    }

    private function reload(CmsPage $page): CmsPage
    {
        CmsPageTableMap::clearInstancePool();
        $reloaded = CmsPageQuery::create()->findPk($page->getId());
        self::assertInstanceOf(CmsPage::class, $reloaded);

        return $reloaded;
    }

    private function cmsSettings(): CmsSettings
    {
        ModuleConfigQuery::resetConfigCache();

        return $this->getService(CmsSettings::class);
    }
}
