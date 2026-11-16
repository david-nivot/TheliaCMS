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

namespace TheliaCMS\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use TheliaCMS\Content\EmbedFacade;
use TheliaCMS\Content\Prop;
use TheliaCMS\Content\PropSchema;
use TheliaCMS\Content\Theme\ThemeComponentSection;
use TheliaCMS\TheliaCMS;

/**
 * The facade of a video reads in the language of the page, German included, and a component of the theme names its
 * video in that language too.
 */
final class EmbedFacadeGermanTest extends TestCase
{
    public function testTheFacadeReadsInGerman(): void
    {
        $markup = (string) (new EmbedFacade($this->translator()))->markup('youtube:zR6O50UbivM', 'Das Video', 'de_DE');

        self::assertStringContainsString('aria-label="Video abspielen: Das Video (YouTube)"', $markup);
        self::assertStringContainsString('Beim Abspielen wird YouTube geladen.', $markup);
        self::assertStringContainsString('Video auf YouTube ansehen</a>', $markup);
    }

    public function testAComponentOfTheThemeNamesItsVideoInTheLanguageOfThePage(): void
    {
        $translator = $this->translator();
        $translator->addResource('array', ['The video' => 'Das Video'], 'de_DE', TheliaCMS::DOMAIN_NAME);
        $translator->addResource('array', ['The video' => 'La vidéo'], 'fr_FR', TheliaCMS::DOMAIN_NAME);
        $received = null;
        $renderer = $this->createStub(ComponentRendererInterface::class);
        $renderer->method('createAndRender')->willReturnCallback(static function (string $name, array $props) use (&$received): string {
            $received = $props;

            return '<div></div>';
        });
        $section = new ThemeComponentSection('Molecules:Video:Base', '', new PropSchema([Prop::embed('video', 'video', true, 'The video')]), ['video' => 'video'], $renderer, facade: new EmbedFacade($translator), translator: $translator);

        $section->render(['video' => 'youtube:zR6O50UbivM'], 'de_DE');
        self::assertIsArray($received);
        self::assertStringContainsString('data-cms-embed-title="Das Video (YouTube)"', (string) $received['video']);

        $section->render(['video' => 'youtube:zR6O50UbivM'], 'fr_FR');
        self::assertStringContainsString('aria-label="Lire la vidéo : La vidéo (YouTube)"', (string) $received['video'], 'The description of the prop goes through the translator, as in the form of the section.');
    }

    private function translator(): Translator
    {
        $translator = new Translator('en_US');
        $translator->addLoader('php', new PhpFileLoader());
        $translator->addLoader('array', new ArrayLoader());
        foreach (['de_DE', 'fr_FR'] as $locale) {
            $translator->addResource('php', \dirname(__DIR__, 3).'/I18n/'.$locale.'.php', $locale, TheliaCMS::DOMAIN_NAME);
        }

        return $translator;
    }
}
