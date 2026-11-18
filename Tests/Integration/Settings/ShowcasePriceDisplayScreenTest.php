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

namespace TheliaCMS\Tests\Integration\Settings;

use Thelia\Model\ConfigQuery;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Settings\SiteMode;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Vitrine\ShowcasePriceDisplay;

/**
 * A showcase site shows no price: saving showcase mode on CMS > Settings sets the setting of
 * the core `price_display_mode` to `on_request` (read by the core 3.4, the theme and SEOne),
 * and saving shop mode puts back the value the shop had. The update of the module does the
 * same for a site already in showcase mode.
 */
final class ShowcasePriceDisplayScreenTest extends AdminScreenTestCase
{
    private const string SCREEN = '/admin/cms/settings';

    protected function tearDown(): void
    {
        parent::tearDown();

        // The rollback takes back the setting, not the copy the core keeps in memory.
        ConfigQuery::resetCache();
    }

    public function testTheScreenSaysThatAShowcaseSiteShowsNoPrice(): void
    {
        self::assertStringContainsString('shows no price, no cart and no currency', $this->screen(self::SCREEN)->text(''));
        self::assertStringContainsString('ni prix, ni panier, ni devise', $this->screen(self::SCREEN.'?lang=fr_FR')->text(''));
    }

    public function testShowcaseModeHidesThePricesAndShopModePutsThemBack(): void
    {
        $this->setCoreSetting('visible');
        $this->saveMode(SiteMode::Commerce);

        $this->saveMode(SiteMode::Showcase);
        self::assertSame('on_request', $this->coreSetting());

        $this->saveMode(SiteMode::Commerce);
        self::assertSame('visible', $this->coreSetting());
        self::assertSame('', $this->keptAside(), 'nothing is left to put back');
    }

    public function testTheModeOfTheShopIsPutBackAsItWas(): void
    {
        $this->setCoreSetting('logged_in');
        $this->saveMode(SiteMode::Commerce);

        $this->saveMode(SiteMode::Showcase);
        $this->saveMode(SiteMode::Showcase);
        self::assertSame('on_request', $this->coreSetting());

        $this->saveMode(SiteMode::Commerce);
        self::assertSame('logged_in', $this->coreSetting(), 'a second save in showcase mode does not keep on_request aside');
    }

    public function testACoreWithoutTheSettingShowsItsPricesAgainAfterShowcaseMode(): void
    {
        ConfigQuery::create()->filterByName(ShowcasePriceDisplay::CORE_SETTING)->delete();
        ConfigQuery::resetCache();
        $this->saveMode(SiteMode::Commerce);

        $this->saveMode(SiteMode::Showcase);
        self::assertSame('on_request', $this->coreSetting());

        $this->saveMode(SiteMode::Commerce);
        self::assertSame('visible', $this->coreSetting());
    }

    public function testAShopThatAsksForItsPricesOnRequestKeepsIt(): void
    {
        $this->setCoreSetting('on_request');
        $this->saveMode(SiteMode::Commerce);

        $this->saveMode(SiteMode::Showcase);
        $this->saveMode(SiteMode::Commerce);

        self::assertSame('on_request', $this->coreSetting());
    }

    public function testAValueChangedByHandInShowcaseModeIsNotOverwritten(): void
    {
        $this->setCoreSetting('logged_in');
        $this->saveMode(SiteMode::Commerce);
        $this->saveMode(SiteMode::Showcase);

        $this->setCoreSetting('visible');
        $this->saveMode(SiteMode::Commerce);

        self::assertSame('visible', $this->coreSetting());
    }

    public function testTheUpdateHidesThePricesOfASiteAlreadyInShowcaseMode(): void
    {
        $this->setCoreSetting('visible');
        TheliaCMS::setConfigValue(CmsSettings::SITE_MODE, SiteMode::Showcase->value);
        TheliaCMS::setConfigValue(ShowcasePriceDisplay::VALUE_BEFORE_SHOWCASE, '');
        ModuleConfigQuery::resetConfigCache();

        (new TheliaCMS())->update('2.32.0', '2.33.0', $this->getPropelConnection());

        self::assertSame('on_request', $this->coreSetting());
        self::assertSame('visible', $this->keptAside());
    }

    private function saveMode(SiteMode $mode): void
    {
        $form = $this->screen(self::SCREEN)->filter('form[name="cms_settings"]')->form();
        $form['cms_settings[siteMode]'] = $mode->value;

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        ModuleConfigQuery::resetConfigCache();
        ConfigQuery::resetCache();
    }

    private function setCoreSetting(string $value): void
    {
        ConfigQuery::write(ShowcasePriceDisplay::CORE_SETTING, $value);
        ConfigQuery::resetCache();
    }

    private function coreSetting(): string
    {
        return (string) ConfigQuery::read(ShowcasePriceDisplay::CORE_SETTING, '', true);
    }

    private function keptAside(): string
    {
        return (string) TheliaCMS::getConfigValue(ShowcasePriceDisplay::VALUE_BEFORE_SHOWCASE, '');
    }
}
