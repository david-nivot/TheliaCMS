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

namespace TheliaCMS\Tests\Unit\Evaluation\ComponentBench;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Evaluation\ComponentBench\BenchCase;
use TheliaCMS\Evaluation\ComponentBench\ComponentOracle;

/**
 * The oracle of the bench, checked on components and cases written here: each
 * rule of the contract of a case, and the checks every component goes through.
 */
final class ComponentOracleTest extends TestCase
{
    public function testAComponentThatKeepsTheContractPasses(): void
    {
        $verdict = $this->judge(self::case(), self::trial());

        self::assertTrue($verdict['pass'], implode(' ', $verdict['problems']));
        self::assertSame(['outcome' => true, 'valid' => true, 'props' => true, 'markup' => true, 'css' => true, 'no_h1' => true, 'img_alt' => true, 'reduced_motion' => true], $verdict['checks']);
    }

    public function testAnOutcomeTheCaseDoesNotAcceptFails(): void
    {
        $verdict = $this->judge(self::case(['outcome' => ['existing'], 'target' => ['Site:Quote']]), self::trial());

        self::assertFalse($verdict['pass']);
        self::assertFalse($verdict['checks']['outcome']);
    }

    public function testAnExistingComponentMustBeOneOfTheTargets(): void
    {
        $case = self::case(['outcome' => ['existing'], 'target' => ['Site:Quote', 'cms:image']]);
        $answer = ['outcome' => 'existing', 'existing' => 'Site:Quote', 'component' => null];

        self::assertTrue($this->judge($case, $answer)['pass']);
        self::assertSame(['outcome' => true, 'target' => true], $this->judge($case, $answer)['checks'], 'The contract on the markup is for a component: an existing one has none.');

        $wrong = $this->judge($case, ['existing' => 'cms:richtext'] + $answer);
        self::assertFalse($wrong['pass']);
        self::assertFalse($wrong['checks']['target']);
    }

    public function testAComponentWrittenAfterACopyIsACopyWhenTheCaseAcceptsOne(): void
    {
        $case = self::case(['outcome' => ['copy', 'component'], 'target' => ['Site:Quote']]);

        $copy = $this->judge($case, ['based_on' => 'Site:Quote'] + self::trial());
        self::assertTrue($copy['pass']);
        self::assertTrue($copy['checks']['target']);

        $elsewhere = $this->judge($case, ['based_on' => 'Site:Faq'] + self::trial());
        self::assertFalse($elsewhere['pass'], 'A copy of what the case does not name is refused.');
        self::assertFalse($elsewhere['checks']['target']);
    }

    public function testWithoutACopyInTheCaseTheNameCopiedIsCountedApartNotFailed(): void
    {
        $case = self::case(['outcome' => ['component'], 'target' => ['Site:Quote']]);

        $inTarget = $this->judge($case, ['based_on' => 'Site:Quote'] + self::trial());
        self::assertTrue($inTarget['pass']);
        self::assertTrue($inTarget['based_on_in_target']);

        $outside = $this->judge($case, ['based_on' => 'Site:Faq'] + self::trial());
        self::assertTrue($outside['pass']);
        self::assertFalse($outside['based_on_in_target']);
        self::assertNull($this->judge($case, self::trial())['based_on_in_target'], 'No copy, nothing to count.');
    }

    public function testAFailedGenerationFails(): void
    {
        $verdict = $this->judge(self::case(), ['error' => 'The model answered nothing.', 'outcome' => null, 'component' => null]);

        self::assertFalse($verdict['pass']);
        self::assertSame(['generated' => false], $verdict['checks']);
    }

    public function testAComponentTheValidatorRefusesFails(): void
    {
        $verdict = $this->judge(self::case(), self::trial(['template' => '<section>{{ props.missing']));

        self::assertFalse($verdict['pass']);
        self::assertSame(['outcome' => true, 'valid' => false], $verdict['checks']);
    }

    public function testPropsAreCountedByTypeAtTheTopAndInTheItems(): void
    {
        // The component holds 1 image at the top and 1 in the items, 4 texts (quote, author, caption, label) and 1 html.
        $count = static fn (string $type, array $bounds): array => [['type' => $type] + $bounds];

        self::assertTrue($this->propsPass($count('image', ['min' => 2, 'max' => 2])), 'The image of the items counts.');
        self::assertFalse($this->propsPass($count('image', ['min' => 3])));
        self::assertFalse($this->propsPass($count('image', ['max' => 1])));
        self::assertTrue($this->propsPass($count('text|html', ['min' => 5, 'max' => 5])), 'a|b counts either type: four texts and one html.');
        self::assertFalse($this->propsPass($count('text', ['min' => 5])));
        self::assertTrue($this->propsPass($count('video', ['max' => 0])));
    }

    public function testAnItemsLineAsksForRowsThatHoldTheTypes(): void
    {
        self::assertTrue($this->propsPass([['type' => 'items', 'min' => 1, 'with' => ['image', 'text|html']]]));
        self::assertFalse($this->propsPass([['type' => 'items', 'min' => 1, 'with' => ['video']]]), 'No row holds a video.');
        self::assertFalse($this->propsPass([['type' => 'items', 'min' => 1, 'with' => ['image', 'url']]]), 'Every type asked must be there.');
        self::assertFalse($this->propsPass([['type' => 'items', 'with' => ['url']]]), 'A line with `with` asks for one at least.');
        self::assertTrue($this->propsPass([['type' => 'items', 'min' => 1]]));
        self::assertFalse($this->propsPass([['type' => 'items', 'min' => 2]]));
    }

    public function testMarkupAndStylesheetAreMatchedByPatternsWithoutCaseNorDelimiters(): void
    {
        $markup = static fn (array $require, array $forbid): array => ['markup' => ['require' => $require, 'forbid' => $forbid]];
        $css = static fn (array $require, array $forbid): array => ['css' => ['require' => $require, 'forbid' => $forbid]];

        self::assertTrue($this->judge(self::case($markup(['<BLOCKQUOTE', '<img\s+src'], [])), self::trial())['checks']['markup'], 'Case-insensitive, on the rendered sample.');
        self::assertFalse($this->judge(self::case($markup(['<table'], [])), self::trial())['checks']['markup'], 'A required pattern that does not match.');
        self::assertFalse($this->judge(self::case($markup([], ['<img'])), self::trial())['checks']['markup'], 'A forbidden one that matches.');
        self::assertTrue($this->judge(self::case($markup([], ['<iframe'])), self::trial())['checks']['markup']);
        self::assertTrue($this->judge(self::case($markup(['a~b|<blockquote'], [])), self::trial())['checks']['markup'], 'A tilde in a pattern is not a delimiter.');

        self::assertTrue($this->judge(self::case($css(['grid|flex'], ['display\s*:\s*none'])), self::trial())['checks']['css']);
        self::assertFalse($this->judge(self::case($css(['column-count'], [])), self::trial())['checks']['css']);
        self::assertFalse($this->judge(self::case($css([], ['DISPLAY: GRID'])), self::trial())['checks']['css']);
    }

    public function testTheMarkupIsTheRenderedSampleNotTheTemplate(): void
    {
        // The template holds the image inside a condition the sample does not meet.
        $trial = self::trial(['template' => '<section><blockquote>{{ props.quote }}</blockquote>{% if props.caption is not empty %}<img src="{{ props.portrait }}" alt="{{ props.caption }}">{% endif %}</section>', 'sample' => ['quote' => 'Q', 'author' => 'A', 'portrait' => 'https://example.com/a.jpg', 'caption' => '', 'body' => '<p>b</p>', 'facts' => [['label' => 'l', 'photo' => 'https://example.com/b.jpg']]]]);

        $verdict = $this->judge(self::case(['markup' => ['require' => ['<img'], 'forbid' => []]]), $trial);

        self::assertFalse($verdict['checks']['markup']);
    }

    public function testNoH1(): void
    {
        $verdict = $this->judge(self::case(), self::trial(['template' => '<section><h1>{{ props.quote }}</h1><img src="{{ props.portrait }}" alt="{{ props.author }}"></section>']));

        self::assertFalse($verdict['pass']);
        self::assertFalse($verdict['checks']['no_h1']);
    }

    public function testEveryImageHasAnAltPrintedFromAProp(): void
    {
        $noAlt = $this->judge(self::case(), self::trial(['template' => '<section><img src="{{ props.portrait }}"></section>']));
        self::assertFalse($noAlt['checks']['img_alt'], 'No alt at all.');

        $written = $this->judge(self::case(), self::trial(['template' => '<section><img src="{{ props.portrait }}" alt="Portrait"></section>']));
        self::assertFalse($written['checks']['img_alt'], 'An alt written in the template is not a prop.');

        $emptyAtRender = $this->judge(self::case(), self::trial(['template' => '<section><img src="{{ props.portrait }}" alt="{{ props.caption }}"></section>', 'sample' => ['quote' => 'Q', 'author' => 'A', 'portrait' => 'https://example.com/a.jpg', 'caption' => '', 'body' => '<p>b</p>', 'facts' => [['label' => 'l', 'photo' => 'https://example.com/b.jpg']]]]));
        self::assertFalse($emptyAtRender['checks']['img_alt'], 'A print that comes out empty.');
    }

    public function testAnAnimationMustBeGuardedByReducedMotion(): void
    {
        $animation = '@keyframes pulse { from { opacity: 0; } to { opacity: 1; } }';

        // The validator already refuses a rule that runs an animation outside of the guard:
        // the keyframes alone, which it lets through, are what the check still sees.
        $bare = $this->judge(self::case(), self::trial(['css' => $animation.' .q { display: grid; }']));
        self::assertFalse($bare['checks']['reduced_motion']);
        self::assertFalse($bare['pass']);

        $guarded = $this->judge(self::case(), self::trial(['css' => $animation.' @media (prefers-reduced-motion: no-preference) { .q { animation: pulse 1s infinite; } }']));
        self::assertTrue($guarded['checks']['reduced_motion']);

        self::assertTrue($this->judge(self::case(), self::trial(['css' => '.q { display: grid; }']))['checks']['reduced_motion'], 'No animation, nothing to guard.');
    }

    public function testTheBrowserBlockIsTakenWhenThereIsOne(): void
    {
        $clean = ['375' => ['scroll' => false, 'axe' => []], '1440' => ['scroll' => false, 'axe' => [['id' => 'region', 'impact' => 'moderate']]]];

        $verdict = $this->judge(self::case(), ['browser' => $clean] + self::trial());
        self::assertTrue($verdict['pass'], 'A minor violation does not fail.');
        self::assertTrue($verdict['checks']['browser']);

        $scrolls = $this->judge(self::case(), ['browser' => ['375' => ['scroll' => true, 'axe' => []]] + $clean] + self::trial());
        self::assertFalse($scrolls['checks']['browser']);

        foreach (['serious', 'critical'] as $impact) {
            $axe = $this->judge(self::case(), ['browser' => ['1440' => ['scroll' => false, 'axe' => [['id' => 'color-contrast', 'impact' => $impact]]]]] + self::trial());
            self::assertFalse($axe['checks']['browser'], $impact);
            self::assertFalse($axe['pass']);
        }

        self::assertArrayNotHasKey('browser', $this->judge(self::case(), self::trial())['checks'], 'No browser block, no browser check.');
    }

    public function testEveryCaseOfTheCorpusLoadsAndItsPatternsCompile(): void
    {
        $cases = BenchCase::loadAll(\dirname(__DIR__, 4).'/docs/evaluation/components/cases');

        self::assertCount(28, $cases);
        self::assertSame('N71', $cases['compose-steps-faq']->gap);
        self::assertSame(['copy', 'component'], $cases['quote-dark']->outcomes);
    }

    public function testAPatternThatIsNotARegularExpressionIsRefusedWhenTheCaseLoads(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::case(['markup' => ['require' => ['(<img'], 'forbid' => []]]);
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function propsPass(array $lines): bool
    {
        return $this->judge(self::case(['props' => $lines]), self::trial())['checks']['props'];
    }

    /**
     * @param array<string, mixed> $trial
     *
     * @return array{pass: bool, checks: array<string, bool>, problems: list<string>, based_on_in_target: bool|null}
     */
    private function judge(BenchCase $case, array $trial): array
    {
        return (new ComponentOracle())->judge($case, $trial);
    }

    /**
     * @param array<string, mixed> $oracle what the contract says, over a permissive one
     */
    private static function case(array $oracle = []): BenchCase
    {
        return BenchCase::fromArray([
            'id' => 'quote-dark',
            'family' => 'reuse',
            'locale' => 'fr_FR',
            'request' => 'Une citation sur fond sombre.',
            'oracle' => $oracle + ['outcome' => ['component'], 'target' => [], 'props' => [], 'markup' => ['require' => [], 'forbid' => []], 'css' => ['require' => [], 'forbid' => []]],
        ]);
    }

    /**
     * The file of a trial that made a component, the fields of the component overridden.
     *
     * @param array<string, mixed> $component
     *
     * @return array<string, mixed>
     */
    private static function trial(array $component = []): array
    {
        return [
            'outcome' => 'component',
            'existing' => null,
            'based_on' => null,
            'error' => null,
            'component' => $component + [
                'code' => 'QuoteDark',
                'label' => 'Citation sombre',
                'description' => 'A quote with its author and a portrait, on a dark background.',
                'props' => [
                    ['name' => 'quote', 'type' => 'text', 'label' => 'Quote', 'required' => true, 'max' => 300],
                    ['name' => 'author', 'type' => 'text', 'label' => 'Author', 'max' => 80],
                    ['name' => 'portrait', 'type' => 'image', 'label' => 'Portrait'],
                    ['name' => 'caption', 'type' => 'text', 'label' => 'Caption', 'max' => 80],
                    ['name' => 'body', 'type' => 'html', 'label' => 'Body'],
                    ['name' => 'facts', 'type' => 'items', 'label' => 'Facts', 'min' => 0, 'max' => 4, 'items' => [
                        ['name' => 'label', 'type' => 'text', 'label' => 'Label', 'max' => 60],
                        ['name' => 'photo', 'type' => 'image', 'label' => 'Photo'],
                    ]],
                ],
                'sample' => ['quote' => 'Q', 'author' => 'A', 'portrait' => 'https://example.com/a.jpg', 'caption' => 'C', 'body' => '<p>b</p>', 'facts' => [['label' => 'l', 'photo' => 'https://example.com/b.jpg']]],
                'template' => '<section class="q"><blockquote>{{ props.quote }}</blockquote><img src="{{ props.portrait }}" alt="{{ props.author }}">{{ props.body }}{% for fact in props.facts %}<img src="{{ fact.photo }}" alt="{{ fact.label }}">{% endfor %}</section>',
                'css' => '.q { display: grid; background: var(--cms-color-text); }',
            ],
        ];
    }
}
