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

namespace TheliaCMS\Tests\Unit\Content\Site;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\PropType;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Content\Site\SiteTemplateRenderer;

/**
 * Whether a component written by the model can be kept, and as what.
 */
final class SiteComponentValidatorTest extends TestCase
{
    public function testAComponentThatHoldsIsGivenBackWithItsSampleNormalised(): void
    {
        $kept = (new SiteComponentValidator())->validate(PriceTable::component(css: '.plan { padding: 1rem; }'));

        self::assertSame('Site:PriceTable', $kept->name());
        self::assertSame('cms-site--price-table', $kept->scopeClass());
        self::assertSame('light', $kept->sample['tone'], 'The sample gets the defaults it left out.');
        self::assertSame('.plan { padding: 1rem; }', $kept->css);
    }

    public function testEveryProblemIsListedAtOnce(): void
    {
        $component = new SiteComponent('price-table', '', 'A table.', [['name' => 'title', 'type' => 'reference', 'label' => 'Title']], [], '<div onclick="steal()">{{ props.title|raw }}</div>', '@import url(x.css);');

        try {
            (new SiteComponentValidator())->validate($component);
            self::fail('A component that does not hold is refused.');
        } catch (InvalidSiteComponentException $exception) {
            $problems = implode("\n", $exception->problems);

            self::assertStringContainsString('PascalCase', $problems);
            self::assertStringContainsString('label', $problems);
            self::assertStringContainsString('"reference"', $problems);
            self::assertStringContainsString('"raw"', $problems);
            self::assertStringContainsString('onclick=', $problems);
            self::assertStringContainsString('@import', $problems);
        }
    }

    public function testWhatAMarkupPropHoldsIsFilteredWhereverTheComponentIsDrawn(): void
    {
        $component = new SiteComponent('Probe', 'Probe', 'A probe.', [['name' => 'body', 'type' => 'html', 'label' => 'Body']], ['body' => '<p>Texte</p><img src="x" onerror="alert(1)"><script>alert(2)</script>'], '<div class="probe">{{ props.body }}</div>');
        $kept = (new SiteComponentValidator())->validate($component);
        $html = (new SiteComponentSection($kept, (new SitePropReader())->read($kept->props), new SiteTemplateRenderer()))->render($kept->sample, 'fr_FR');

        self::assertStringContainsString('<p>Texte</p>', $html);
        self::assertStringNotContainsString('onerror', $html, 'A draft and a preview are drawn before the publication filters a page.');
        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * A template the patterns of the validator cannot read: the tag, the
     * attribute or the scheme is put together when it runs, or comes from a
     * prop. Whatever it assembles, what is drawn runs nothing and loads
     * nothing from another host.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function assembledAttacks(): iterable
    {
        yield 'a script element named at run time' => ["<div><{{ 'scr' ~ 'ipt' }}>{{ props.title }}</{{ 'scr' ~ 'ipt' }}>{{ props.title }}</div>", '<script'];
        yield 'an event handler named at run time' => ["<div {{ 'on' ~ 'click' }}=\"alert(1)\">{{ props.title }}</div>", 'onclick'];
        yield 'an error handler named at run time' => ["<img src=\"x\" {{ 'one' ~ 'rror' }}=\"alert(1)\"><p>{{ props.title }}</p>", 'onerror'];
        yield 'a javascript scheme split by an expression' => ["<a href=\"java{{ '' }}script:alert(1)\">{{ props.title }}</a>", 'script:'];
        yield 'a javascript scheme written as an entity' => ['<a href="&#106;avascript:alert(1)">{{ props.title }}</a>', 'avascript'];
        yield 'a javascript scheme from a text prop' => ['<a href="{{ props.look }}">{{ props.title }}</a>', 'javascript'];
        yield 'an iframe named at run time' => ["<{{ 'ifr' ~ 'ame' }} srcdoc=\"x\"></{{ 'ifr' ~ 'ame' }}><p>{{ props.title }}</p>", 'iframe'];
        yield 'a form named at run time' => ["<{{ 'fo' ~ 'rm' }} action=\"https://evil.test\"><button>{{ props.title }}</button></{{ 'fo' ~ 'rm' }}><p>{{ props.title }}</p>", 'evil.test'];
        yield 'a picture of another host from a text prop in a style attribute' => ['<div style="{{ props.remote }}">{{ props.title }}</div>', 'evil.test'];
    }

    #[DataProvider('assembledAttacks')]
    public function testWhatATemplateAssemblesWhenItRunsIsFilteredToo(string $template, string $forbidden): void
    {
        $props = [['name' => 'title', 'type' => 'text', 'label' => 'Title'], ['name' => 'look', 'type' => 'text', 'label' => 'Look'], ['name' => 'remote', 'type' => 'text', 'label' => 'Remote']];
        $sample = ['title' => 'Un titre', 'look' => 'javascript:alert(1)', 'remote' => 'background:url(https://evil.test/t.png)'];
        $kept = (new SiteComponentValidator())->validate(new SiteComponent('Probe', 'Probe', 'A probe.', $props, $sample, $template));

        $html = (new SiteComponentSection($kept, (new SitePropReader())->read($kept->props), new SiteTemplateRenderer()))->render($kept->sample, 'fr_FR');

        self::assertStringContainsString('Un titre', $html, 'The component is drawn.');
        self::assertStringNotContainsStringIgnoringCase($forbidden, $html);
    }

    public function testAStylesheetReachingAnotherHostInAnyWayIsRefused(): void
    {
        foreach (['.a { background: url(/x.png) }', '.a { background: u\\72l(https://evil.example/x) }', '.a { background-image: image-set("https://evil.example/x.png" 1x) }'] as $css) {
            try {
                (new SiteComponentValidator())->validate(PriceTable::component(css: $css));
                self::fail(\sprintf('"%s" is refused.', $css));
            } catch (InvalidSiteComponentException $exception) {
                self::assertNotSame([], $exception->problems);
            }
        }
    }

    public function testAStylesheetThatCouldCloseTheElementHoldingItIsRefused(): void
    {
        $this->expectException(InvalidSiteComponentException::class);
        $this->expectExceptionMessage('"<"');

        (new SiteComponentValidator())->validate(PriceTable::component(css: '.plan::after { content: "</style><script>alert(1)</script>"; }'));
    }

    public function testATemplateThatWritesItsOwnTextIsRefused(): void
    {
        $this->expectException(InvalidSiteComponentException::class);
        $this->expectExceptionMessage('"Choose your plan"');

        (new SiteComponentValidator())->validate(PriceTable::component('<section><h2>Choose your plan</h2>{% for plan in props.plans %}<p>{{ plan.name }} – {{ plan.price }}</p>{% endfor %}</section>'));
    }

    public function testASampleThePropsRefuseIsNamedAsSuch(): void
    {
        $this->expectException(InvalidSiteComponentException::class);
        $this->expectExceptionMessage('The sample:');

        (new SiteComponentValidator())->validate(PriceTable::component(sample: ['plans' => [['price' => '9 €']]]));
    }

    public function testASampleThatDrawsNothingIsRefused(): void
    {
        $this->expectException(InvalidSiteComponentException::class);
        $this->expectExceptionMessage('draws nothing');

        (new SiteComponentValidator())->validate(PriceTable::component('{% if props.title == "never" %}<p>{{ props.title }}</p>{% endif %}'));
    }

    /**
     * The page filters what a component draws without a word: an element or
     * an attribute it does not keep is named while the model can still draw
     * it another way, in every branch of the template, whatever the sample
     * shows.
     */
    public function testWhatThePageDoesNotKeepIsRefusedInEveryBranch(): void
    {
        $template = '<div class="scroller">{% if props.title == "never" %}<label class="scroller__control"><input class="scroller__toggle" type="checkbox"> {{ props.title }}</label><select class="scroller__motion"><option>{{ props.title }}</option></select>{% endif %}<p data-state="idle">{{ props.title }}</p></div>';

        try {
            (new SiteComponentValidator())->validate(PriceTable::component($template));
            self::fail('A component the page would draw without its moving part is refused.');
        } catch (InvalidSiteComponentException $exception) {
            $problems = implode("\n", $exception->problems);

            self::assertStringContainsString('<select>', $problems);
            self::assertStringContainsString('<input>', $problems);
            self::assertStringContainsString('"data-state" of <p>', $problems);
            self::assertStringNotContainsString('<label>', $problems, 'What the page keeps is not named.');
            self::assertStringNotContainsString('"class" of <select>', $problems, 'An element taken out is named once, not with each of its attributes.');
        }
    }

    public function testAnAnimationWrittenInTheTemplateIsRefused(): void
    {
        $this->expectException(InvalidSiteComponentException::class);
        $this->expectExceptionMessage('stylesheet');

        (new SiteComponentValidator())->validate(PriceTable::component('<section class="price-table" style="--speed: 20s; animation: pan var(--speed) linear infinite">{{ props.title }}</section>', '@keyframes pan { to { transform: translateX(-50%); } }'));
    }

    public function testATemplateThePageKeepsWholeIsKept(): void
    {
        $template = '<section class="faq">{% if props.title is not empty %}<h2 id="faq-{{ props.tone }}">{{ props.title }}</h2>{% endif %}{% for plan in props.plans %}<details class="faq__item"{{ plan.featured ? " open" }}><summary aria-label="{{ plan.name }}">{{ plan.name }}</summary><img src="{{ plan.price }}" alt="{{ plan.name }}" loading="lazy" width="80" height="80"><a href="{{ plan.price }}" target="_blank" rel="noopener">{{ plan.price }}</a>{{ plan.details }}</details>{% endfor %}</section>';

        self::assertSame($template, (new SiteComponentValidator())->validate(PriceTable::component($template))->template);
    }

    /**
     * A component says the width its pictures are drawn at, as the sections of
     * the module do: the sandbox reads no attribute, and the filter of the page
     * keeps this one for the publication to read.
     */
    public function testAComponentMayDeclareTheWidthOfItsImages(): void
    {
        $template = '<section class="faq" data-cms-sizes="(min-width: 64rem) 20vw, 50vw">{% for plan in props.plans %}<img src="{{ plan.price }}" alt="{{ plan.name }}">{% endfor %}</section>';

        self::assertSame($template, (new SiteComponentValidator())->validate(PriceTable::component($template))->template);
    }

    public function testTheSectionDrawsTheComponentInsideItsClassAndAFailingTemplateDrawsNothing(): void
    {
        $component = PriceTable::component();
        $schema = (new SitePropReader())->read($component->props);
        $section = new SiteComponentSection($component, $schema, new SiteTemplateRenderer());
        $html = $section->render($section->normalizeProps($component->sample), 'fr_FR');

        self::assertStringStartsWith('<div class="cms-site cms-site--price-table"><section class="price-table price-table--light"><h2>Our plans</h2>', $html);
        self::assertStringContainsString('<article class="plan plan--featured"><h3>Pro</h3><p class="plan__price">29 €</p><p>All studios</p></article>', $html, 'Markup props are printed as they are.');
        self::assertSame(PropType::Items, $section->schema()->props[1]->type);

        $broken = new SiteComponentSection(PriceTable::component('{% include "x" %}'), $schema, new SiteTemplateRenderer());
        self::assertSame('', $broken->render($component->sample, 'fr_FR'), 'A page keeps rendering whatever one of its components does.');
    }
}
