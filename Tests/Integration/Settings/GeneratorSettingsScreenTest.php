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

/**
 * The "Generator" card of CMS > Settings: the effort and the length of an
 * answer are saved from the screen. The provider, the key, the model and the
 * budget belong to the AI settings of the core: the card sends there.
 */
final class GeneratorSettingsScreenTest extends AdminScreenTestCase
{
    private const string SCREEN = '/admin/cms/settings';

    public function testTheCardShowsTheSettingsAndSendsTheProviderToTheAiSettingsOfTheCore(): void
    {
        $card = $this->screen(self::SCREEN)->filter('[data-testid="cms-settings-generator"]');

        self::assertSame(1, $card->count(), 'The card is rendered.');
        self::assertSame(0, $card->filter('input[name="cms_settings[generatorModel]"], input[name="cms_settings[textModel]"]')->count(), 'The model is chosen in the AI settings of the core.');
        self::assertSame(1, $card->filter('select[name="cms_settings[generatorEffort]"]')->count());
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
     * Only the adapter of Anthropic reads the effort: the help says so on
     * both fields, rather than promise a time spent thinking that the models
     * only take at the two highest efforts.
     */
    public function testTheEffortSaysWhichModelsReadIt(): void
    {
        foreach (['en_US' => 'Anthropic models only', 'fr_FR' => 'modèles Anthropic'] as $locale => $expected) {
            $screen = $this->screen(self::SCREEN.'?lang='.$locale);

            foreach (['generatorEffort', 'textEffort'] as $field) {
                // The theme of the back office prints the help inside the label.
                $help = $screen->filter('label[for="cms_settings_'.$field.'"] .bo-form-label__help')->text('');

                self::assertStringContainsString($expected, $help, $field.' in '.$locale);
                self::assertStringNotContainsString('How long the model thinks', $help);
            }
        }
    }

    public function testPostingTheFormChangesWhatTheGeneratorReads(): void
    {
        $form = $this->settingsForm();
        $form['cms_settings[generatorEffort]'] = 'low';
        $form['cms_settings[generatorMaxTokens]'] = '4096';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        ModuleConfigQuery::resetConfigCache();
        $settings = $this->settings();
        self::assertSame('low', $settings->effort());
        self::assertSame(4096, $settings->maxTokens());
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
     * A short text written from a brief has settings of its own: a paragraph
     * does not need the effort, nor the length, of a whole page.
     */
    public function testTheTextsWrittenFromABriefHaveTheirOwnSettings(): void
    {
        $card = $this->screen(self::SCREEN)->filter('[data-testid="cms-settings-generator-text"]');

        self::assertSame(1, $card->filter('select[name="cms_settings[textEffort]"]')->count());
        self::assertSame(1, $card->filter('input[name="cms_settings[textMaxTokens]"]')->count());

        $settings = $this->settings();
        self::assertSame('low', $settings->textEffort());
        self::assertSame(GeneratorSettings::DEFAULT_TEXT_MAX_TOKENS, $settings->textMaxTokens());

        $form = $this->settingsForm();
        $form['cms_settings[textEffort]'] = 'medium';
        $form['cms_settings[textMaxTokens]'] = '3000';

        self::assertSame(302, $this->send('POST', $form->getUri(), $form->getPhpValues()));

        ModuleConfigQuery::resetConfigCache();
        $settings = $this->settings();
        self::assertSame('medium', $settings->textEffort());
        self::assertSame(3000, $settings->textMaxTokens());

        $form = $this->settingsForm();
        $form['cms_settings[textMaxTokens]'] = '10';
        $this->send('POST', $form->getUri(), $form->getPhpValues());

        ModuleConfigQuery::resetConfigCache();
        $settings = $this->settings();
        self::assertSame(GeneratorSettings::DEFAULT_TEXT_MAX_TOKENS, $settings->textMaxTokens(), 'A length too short for a paragraph comes back to the default.');
    }

    public function testAnEffortTheApiDoesNotKnowIsRefused(): void
    {
        $before = $this->settings()->effort();
        $form = $this->settingsForm();
        $values = $form->getPhpValues();
        $values['cms_settings']['generatorEffort'] = 'infinite';

        self::assertSame(200, $this->send('POST', $form->getUri(), $values));

        self::assertGreaterThan(0, $this->client->getCrawler()->filter('.invalid-feedback, .form-error-message')->count(), 'The refusal is shown.');
        ModuleConfigQuery::resetConfigCache();
        self::assertSame($before, $this->settings()->effort());
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
