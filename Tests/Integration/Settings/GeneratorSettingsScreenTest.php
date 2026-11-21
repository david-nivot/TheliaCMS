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

use Symfony\Component\DomCrawler\Form;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Generation\GeneratorSettings;
use TheliaCMS\Tests\Integration\Security\AdminScreenTestCase;
use TheliaCMS\TheliaCMS;

/**
 * The "Generator" card of CMS > Settings: the length of an answer is saved
 * from the screen. The provider, the key, the model, the budget and the
 * reasoning effort belong to the AI settings of the core: the card sends there.
 */
final class GeneratorSettingsScreenTest extends AdminScreenTestCase
{
    private const string SCREEN = '/admin/cms/settings';

    public function testTheCardShowsTheSettingsAndSendsTheProviderToTheAiSettingsOfTheCore(): void
    {
        $card = $this->screen(self::SCREEN)->filter('[data-testid="cms-settings-generator"]');

        self::assertSame(1, $card->count(), 'The card is rendered.');
        self::assertSame(0, $card->filter('input[name="cms_settings[generatorModel]"], input[name="cms_settings[textModel]"]')->count(), 'The model is chosen in the AI settings of the core.');
        self::assertSame(0, $card->filter('select[name*="ffort"]')->count(), 'The reasoning effort is set in the AI settings of the core.');
        self::assertSame(1, $card->filter('input[name="cms_settings[generatorMaxTokens]"]')->count());
        self::assertSame(1, $card->filter('input[type="checkbox"][name="cms_settings[generatorWebFetch]"]')->count());
        self::assertStringContainsString('Off by default', $card->filter('[data-testid="cms-settings-web-fetch-help"]')->text(''), 'The box says where a page read goes, which the theme of the back office leaves out of a checkbox.');
        self::assertSame(1, $card->filter('input[type="checkbox"][name="cms_settings[generatorComponentMaking]"]')->count());
        self::assertStringContainsString('Off by default', $card->filter('[data-testid="cms-settings-component-making-help"]')->text(''), 'The box says what a component made by a brief costs and where it goes.');
        self::assertSame(0, $card->filter('input[type="password"], input[name*="Key"], input[name*="key"]')->count(), 'No field takes the API key.');
        self::assertStringNotContainsString('ANTHROPIC', $card->text(''));
        self::assertStringContainsString('AI settings of Thelia', $card->text(''), 'The card says where the provider, the key, the model and the budget are set.');
    }

    /**
     * Sabotage: delete the paragraph `cms-settings-effort-help` of the
     * template, or point its link at another route.
     */
    public function testTheCardSendsTheEffortToTheAiSettingsOfTheCore(): void
    {
        foreach (['en_US' => 'reasoning effort', 'fr_FR' => 'effort de réflexion'] as $locale => $expected) {
            $help = $this->screen(self::SCREEN.'?lang='.$locale)->filter('[data-testid="cms-settings-effort-help"]');

            self::assertSame(1, $help->count(), $locale);
            self::assertStringContainsString($expected, $help->text(''), $locale);
            self::assertSame('/admin/configuration/ai/usages', $help->filter('a')->attr('href'), 'The link opens the usages of the AI settings of Thelia.');
        }
    }

    public function testPostingTheFormChangesWhatTheGeneratorReads(): void
    {
        $form = $this->settingsForm();
        $form['cms_settings[generatorMaxTokens]'] = '4096';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        ModuleConfigQuery::resetConfigCache();
        self::assertSame(4096, $this->settings()->maxTokens());
        self::assertNull(TheliaCMS::getConfigValue('generator_effort', null), 'The effort is no longer written by the module.');
        self::assertNull(TheliaCMS::getConfigValue('generator_text_effort', null));
    }

    public function testLettingABriefMakeAComponentIsOffUntilTheSiteTurnsItOn(): void
    {
        self::assertFalse($this->settings()->componentMaking(), 'Off by default: a component made by a brief is a call the editor did not ask for in so many words.');

        $form = $this->settingsForm();
        self::assertFalse($form['cms_settings[generatorComponentMaking]']->hasValue());
        $form['cms_settings[generatorComponentMaking]']->tick();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        ModuleConfigQuery::resetConfigCache();
        self::assertTrue($this->settings()->componentMaking());
        self::assertTrue($this->settingsForm()['cms_settings[generatorComponentMaking]']->hasValue(), 'The box shows the setting as saved.');
    }

    public function testReadingTheWebPagesABriefCitesIsOffUntilTheSiteTurnsItOn(): void
    {
        self::assertFalse($this->settings()->webFetch(), 'Off by default: a page read leaves the server for the provider.');

        $form = $this->settingsForm();
        self::assertFalse($form['cms_settings[generatorWebFetch]']->hasValue(), 'The box shows the setting unticked.');
        $form['cms_settings[generatorWebFetch]']->tick();

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        ModuleConfigQuery::resetConfigCache();
        self::assertTrue($this->settings()->webFetch());

        $form = $this->settingsForm();
        self::assertTrue($form['cms_settings[generatorWebFetch]']->hasValue(), 'The box shows the setting as saved.');
        $form['cms_settings[generatorWebFetch]']->untick();
        $this->send('POST', $form->getUri(), $form->getPhpValues());

        ModuleConfigQuery::resetConfigCache();
        self::assertFalse($this->settings()->webFetch());
    }

    /**
     * A short text written from a brief has a length of its own: a paragraph
     * does not need the length of a whole page.
     */
    public function testTheTextsWrittenFromABriefHaveTheirOwnSettings(): void
    {
        $card = $this->screen(self::SCREEN)->filter('[data-testid="cms-settings-generator-text"]');

        self::assertSame(0, $card->filter('select')->count(), 'No effort to choose here.');
        self::assertSame(1, $card->filter('input[name="cms_settings[textMaxTokens]"]')->count());

        $settings = $this->settings();
        self::assertSame(GeneratorSettings::DEFAULT_TEXT_MAX_TOKENS, $settings->textMaxTokens());

        $form = $this->settingsForm();
        $form['cms_settings[textMaxTokens]'] = '3000';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        ModuleConfigQuery::resetConfigCache();
        $settings = $this->settings();
        self::assertSame(3000, $settings->textMaxTokens());

        $form = $this->settingsForm();
        $form['cms_settings[textMaxTokens]'] = '10';
        $this->send('POST', $form->getUri(), $form->getPhpValues());

        ModuleConfigQuery::resetConfigCache();
        $settings = $this->settings();
        self::assertSame(GeneratorSettings::DEFAULT_TEXT_MAX_TOKENS, $settings->textMaxTokens(), 'A length too short for a paragraph comes back to the default.');
    }

    private function settingsForm(): Form
    {
        return $this->screen(self::SCREEN)->filter('form[name="cms_settings"]')->form();
    }

    private function settings(): GeneratorSettings
    {
        $settings = $this->getService(GeneratorSettings::class);
        \assert($settings instanceof GeneratorSettings);

        return $settings;
    }
}
