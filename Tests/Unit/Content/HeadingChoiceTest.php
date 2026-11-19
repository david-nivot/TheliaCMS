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
use TheliaCMS\Content\ColumnsSection;
use TheliaCMS\Content\HeadingChoice;
use TheliaCMS\Content\Theme\ThemeComponentSections;
use TheliaCMS\Partial\PartialPropType;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The heading that opens a section of the module (columns, a list of pages, a
 * form) takes the level and the look the editor chooses, as a heading on its
 * own does; the headings under it follow, one level below.
 */
final class HeadingChoiceTest extends TestCase
{
    use InTheme;

    public function testTheHeadingsUnderAHeadingAreOneLevelBelowIt(): void
    {
        $choice = new HeadingChoice();

        self::assertSame('h3', $choice->childLevel('h2'));
        self::assertSame('h4', $choice->childLevel('h3'));
        self::assertSame('h5', $choice->childLevel('h4'));
        self::assertSame('h3', $choice->childLevel('h1'), 'A level not offered is the first one.');
        self::assertSame('h2', $choice->level('h6'));
    }

    public function testOnFlexyAHeadingInAStyleTakesItsClassesAndTheModifierThatKeepsTheSheetOff(): void
    {
        $this->inTheme('flexy', null, static function (ThemeComponentSections $theme): void {
            $choice = new HeadingChoice(null, $theme);

            self::assertSame('cms-form__title cms-form__title--styled h1', $choice->classes('cms-form__title', 'h1'));
            self::assertSame('cms-form__title', $choice->classes('cms-form__title', HeadingChoice::LEVEL_STYLE));
            self::assertSame('cms-form__title', $choice->classes('cms-form__title', 'display'), 'A style the theme does not declare is the look of the level.');
            self::assertSame(['tag' => 'h3', 'child_tag' => 'h4', 'styled' => true, 'class' => 'h1'], $choice->forTemplate(['title_level' => 'h3', 'title_style' => 'h1']));
            self::assertSame(['tag' => 'h2', 'child_tag' => 'h3', 'styled' => false, 'class' => null], $choice->forTemplate([]));
        });
    }

    public function testAPartialOffersTheSameTwoChoicesAsASection(): void
    {
        $this->inTheme('flexy', null, static function (ThemeComponentSections $theme): void {
            $choice = new HeadingChoice(null, $theme);
            [$level, $style] = $choice->partialProps();

            self::assertSame([HeadingChoice::LEVEL_PROP, PartialPropType::Choice, 'h2'], [$level->name, $level->type, $level->default]);
            self::assertSame(HeadingChoice::LEVELS, array_keys($level->choices));
            self::assertSame([HeadingChoice::STYLE_PROP, PartialPropType::Choice, HeadingChoice::LEVEL_STYLE], [$style->name, $style->type, $style->default]);
            self::assertSame([HeadingChoice::LEVEL_STYLE, 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], array_keys($style->choices));
        });
    }

    public function testTheColumnsTakeTheLevelAndTheLookChosenAndTheirHeadingsTheNextLevel(): void
    {
        $this->inTheme('flexy', null, static function (ThemeComponentSections $theme): void {
            $section = new ColumnsSection(null, $theme);
            $props = $section->normalizeProps(['title' => 'Nos engagements', 'title_level' => 'h3', 'title_style' => 'h1', 'columns' => [['text' => 'Un', 'title' => 'Local'], ['text' => 'Deux', 'title' => 'Durable']]]);
            $html = $section->render($props, 'fr_FR');

            self::assertStringContainsString('<h3 class="cms-columns__title cms-columns__title--styled h1">Nos engagements</h3>', $html);
            self::assertStringContainsString('<h4 class="cms-columns__heading">Local</h4>', $html);
            self::assertStringNotContainsString('<h2', $html);

            $byDefault = $section->render($section->normalizeProps(['title' => 'Nos engagements', 'title_style' => 'display', 'columns' => [['text' => 'Un', 'title' => 'Local'], ['text' => 'Deux']]]), 'fr_FR');

            self::assertStringContainsString('<h2 class="cms-columns__title">Nos engagements</h2>', $byDefault, 'A style no longer declared is the look of the level.');
            self::assertStringContainsString('<h3 class="cms-columns__heading">Local</h3>', $byDefault);
        });
    }

    public function testTheListOfPagesDrawsItsHeadingAndItsCardsAtTheLevelsChosen(): void
    {
        $html = $this->twig()->render('page-list.html.twig', [
            'type' => ['code' => 'recipe'],
            'title' => 'Nos recettes',
            'heading' => ['tag' => 'h3', 'child_tag' => 'h4', 'styled' => true, 'class' => 'h1'],
            'pages' => [['card' => ['title' => 'Tarte', 'url' => '/tarte', 'image' => null, 'chapo' => null], 'fields' => []]],
            'empty_message' => '',
        ]);

        self::assertStringContainsString('<h3 class="cms-page-list__title cms-page-list__title--styled h1">Nos recettes</h3>', $html);
        self::assertStringContainsString('<h4 class="cms-page-card__title"><a class="cms-page-card__link" href="/tarte">Tarte</a></h4>', $html);
    }

    public function testTheFormDrawsItsHeadingAtTheLevelChosen(): void
    {
        $html = $this->twig()->render('form.html.twig', [
            'form' => ['anchor' => 'cms-form-contact', 'title' => 'Écrivez-nous', 'code' => 'contact', 'description' => ''],
            'heading' => ['tag' => 'h4', 'child_tag' => 'h5', 'styled' => false, 'class' => null],
            'sent' => true,
            'lead' => false,
            'success_message' => 'Merci',
        ]);

        self::assertStringContainsString('<h4 class="cms-form__title" id="cms-form-contact-title">Écrivez-nous</h4>', $html);
    }

    public function testTheHeadingOfABandOfTheThemeReadsInTheWordsOfTheHeadingOfTheModule(): void
    {
        $band = <<<'TWIG'
            {# @prop title string The heading #}
            {# @prop titleTag 'h2'|'h3'|'h4' The level of the heading #}
            {# @prop titleStyle 'h1'|'h2'|'h3'|'h4'|'h5'|'h6' The look of the heading #}
            {# @prop side 'h2'|'h3' Not a heading #}
            {% props title = '', titleTag = 'h2', titleStyle = 'h4', side = 'h2' %}
            TWIG;
        $twig = new Environment(new ArrayLoader(['@Flexy/Layouts/Band/Base.html.twig' => $band]));

        $this->inTheme('flexy', "sections:\n    components:\n        - Layouts:Band:Base\n", static function (ThemeComponentSections $theme): void {
            $props = [];

            foreach ([...$theme->sections()][0]->schema()->props as $prop) {
                $props[$prop->name] = $prop;
            }

            self::assertSame('Level 3, under a heading of level 2', $props['title_tag']->choices['h3']);
            self::assertSame('Heading 1 of the theme', $props['title_style']->choices['h1']);
            self::assertSame(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], array_keys($props['title_style']->choices), 'The values of the theme, no other.');
            self::assertSame('h4', $props['title_style']->default);
            self::assertSame(['h2' => 'h2', 'h3' => 'h3'], $props['side']->choices, 'Another choice keeps its words.');
        }, $twig);
    }

    /**
     * The module may come after the theme: its sizes would beat the classes
     * of a style, so it sizes these headings only without one.
     */
    public function testTheSheetSizesTheHeadingOfASectionOnlyWithoutAStyle(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/blocks.css');

        foreach (['cms-columns__title', 'cms-page-list__title', 'cms-form__title'] as $class) {
            self::assertSame(0, preg_match('/\.'.$class.'\s*\{[^}]*font-/', $css), \sprintf('No rule sizes every .%s.', $class));
            self::assertMatchesRegularExpression('/\.'.$class.':not\(\.'.$class.'--styled\)\s*\{[^}]*font-size/', $css);

            foreach (['h3', 'h4'] as $level) {
                self::assertMatchesRegularExpression('/'.$level.'\.'.$class.':not\(\.'.$class.'--styled\)\s*\{\s*font-size: var\(--cms-font-size-'.$level.'\);/', $css, \sprintf('Like its level, an %s is sized as one.', $level));
            }
        }

        foreach (['cms-columns__heading', 'cms-page-card__title'] as $class) {
            self::assertMatchesRegularExpression('/h4\.'.$class.',\s*h5\.'.$class.'\s*\{\s*font-size: var\(--cms-font-size-h4\);/', $css, 'The headings below follow their level, down to the last token.');
        }
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 3).'/templates/front/partials'), ['strict_variables' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('cms_image_attributes', static fn (): array => []));
        $twig->addFunction(new TwigFunction('theme_hook', static fn (): string => '', ['is_safe' => ['html']]));

        return $twig;
    }
}
