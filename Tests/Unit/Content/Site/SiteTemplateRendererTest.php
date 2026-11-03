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
use TheliaCMS\Content\Site\SiteTemplateRenderer;
use Twig\Markup;
use Twig\Sandbox\SecurityError;

/**
 * The Twig of the components of the site: sandboxed, given the props alone.
 */
final class SiteTemplateRendererTest extends TestCase
{
    public function testTextIsEscapedAndMarkupPrintedAsItIs(): void
    {
        $html = (new SiteTemplateRenderer())->render(
            '{% for item in props.items %}<p class="{{ loop.last ? "last" }}">{{ item.title|upper }} {{ item.body }}</p>{% endfor %}{{ max(1, 2) }}',
            ['items' => [['title' => '<b>a</b>', 'body' => new Markup('<em>b</em>', 'UTF-8')], ['title' => 'c', 'body' => '<i>d</i>']]],
        );

        self::assertSame('<p class="">&lt;B&gt;A&lt;/B&gt; <em>b</em></p><p class="last">C &lt;i&gt;d&lt;/i&gt;</p>2', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'an include' => ['{% include "base.html.twig" %}'];
        yield 'the raw filter' => ['{{ props.title|raw }}'];
        yield 'a function of the site' => ['{{ path("admin") }}'];
        yield 'a macro' => ['{% macro m() %}{% endmacro %}'];
        yield 'a block' => ['{% block b %}{% endblock %}'];
        yield 'a filter running code' => ['{{ props.items|map(i => i) }}'];
        yield 'the constant test' => ['{% if props.a is constant("PHP_VERSION") %}x{% endif %}'];
        yield 'a syntax error' => ['{% if %}'];
        yield 'a template it uses' => ['{% use "base.html.twig" %}'];
        yield 'a template it extends' => ['{% extends "base.html.twig" %}'];
        yield 'a template it extends, trimming spaces' => ['{%~ extends "base.html.twig" %}'];
        yield 'a range without a bound' => ['{% for i in range(1, 100000000) %}{% endfor %}'];
        yield 'a range written as dots' => ['{% for i in 1..100000000 %}{% endfor %}'];
        yield 'three loops inside each other' => ['{% for a in props.a %}{% for b in props.b %}{% for c in props.c %}x{% endfor %}{% endfor %}{% endfor %}'];
        yield 'three loops with a test between the second and the third' => ['{% for a in props.a %}{% for b in props.b %}{% if b %}{% for c in props.c %}x{% endfor %}{% endif %}{% endfor %}{% endfor %}'];
    }

    #[DataProvider('refused')]
    public function testWhatTheSandboxRefusesIsAProblemBeforeAnyRendering(string $template): void
    {
        self::assertNotSame([], (new SiteTemplateRenderer())->problems($template));
    }

    public function testTheCapIsOnTheDepthOfTheLoopsAndNotOnTheirCount(): void
    {
        $renderer = new SiteTemplateRenderer();

        self::assertSame([], $renderer->problems('{% for a in props.a %}{% for b in props.b %}x{% endfor %}{% endfor %}'));
        self::assertSame([], $renderer->problems('{% for a in props.a %}x{% endfor %}{% for b in props.b %}x{% endfor %}{% for c in props.c %}x{% endfor %}'));
        self::assertSame([], $renderer->problems('{% for a in props.a %}{% for b in props.b %}x{% endfor %}{% for c in props.c %}x{% endfor %}{% endfor %}'));
    }

    public function testTheWordForInATextOrAStringDoesNotCountAsALoop(): void
    {
        $template = '{# {% for a in x %}{% for b in x %}{% for c in x %} #}{% for a in props.a %}{% for b in props.b %}{{ "{% for c in props.c %}" }}{% endfor %}{% endfor %}';

        self::assertSame([], (new SiteTemplateRenderer())->problems($template));
    }

    public function testTheMessageNamesTheLineOfTheThirdLoop(): void
    {
        $problems = (new SiteTemplateRenderer())->problems("{% for a in props.a %}\n{% for b in props.b %}\n{% for c in props.c %}x{% endfor %}\n{% endfor %}\n{% endfor %}");

        self::assertCount(1, $problems);
        self::assertStringContainsString('line 3', $problems[0]);
    }

    public function testARefusedTemplateIsNeverRendered(): void
    {
        $this->expectException(SecurityError::class);

        (new SiteTemplateRenderer())->render('{% include "base.html.twig" %}', []);
    }
}
