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

namespace TheliaCMS\Evaluation\ComponentBench;

use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentSection;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Content\Site\SitePropReader;
use TheliaCMS\Content\Site\SiteTemplateRenderer;

/**
 * Applies the contract of a case to what the generator answered: no model, no
 * database, nothing the file of a trial does not hold.
 *
 * The trial is the file the run writes (`outcome`, `existing`, `based_on`,
 * `component`, `error`, and `browser` once the browser script has run). A
 * component is judged on what the site would draw: it goes through the
 * validator again, and its sample is rendered by the real renderer of the
 * components of the site.
 *
 * An answer that is a component written after a copy (`based_on` set) counts
 * as `copy` when the case accepts that outcome, and as `component` otherwise,
 * with the name it copied counted apart.
 */
final readonly class ComponentOracle
{
    /** Impacts of axe-core the browser check refuses. */
    private const array BLOCKING_IMPACTS = ['serious', 'critical'];

    private const string TAG = '/<([a-z][a-z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i';

    public function __construct(
        private SiteComponentValidator $validator = new SiteComponentValidator(),
        private SitePropReader $props = new SitePropReader(),
        private SiteTemplateRenderer $renderer = new SiteTemplateRenderer(),
    ) {
    }

    /**
     * @param array<string, mixed> $trial
     *
     * @return array{pass: bool, checks: array<string, bool>, problems: list<string>, based_on_in_target: bool|null}
     */
    public function judge(BenchCase $case, array $trial): array
    {
        $checks = [];
        $problems = [];
        $basedOnInTarget = null;

        if (\is_string($trial['error'] ?? null) && '' !== $trial['error']) {
            return ['pass' => false, 'checks' => ['generated' => false], 'problems' => ['The generation failed: '.$trial['error']], 'based_on_in_target' => null];
        }

        $outcome = (string) ($trial['outcome'] ?? '');
        $basedOn = \is_string($trial['based_on'] ?? null) ? $trial['based_on'] : null;
        $copied = 'component' === $outcome && null !== $basedOn && \in_array('copy', $case->outcomes, true);
        $effective = $copied ? 'copy' : $outcome;

        $checks['outcome'] = \in_array($effective, $case->outcomes, true);

        if (!$checks['outcome']) {
            $problems[] = \sprintf('The outcome is "%s", the case accepts: %s.', $effective, implode(', ', $case->outcomes));
        }

        if (('existing' === $effective || 'copy' === $effective) && [] !== $case->target) {
            $name = 'existing' === $effective ? (string) ($trial['existing'] ?? '') : (string) $basedOn;
            $checks['target'] = \in_array($name, $case->target, true);

            if (!$checks['target']) {
                $problems[] = \sprintf('"%s" is not one of the targets: %s.', $name, implode(', ', $case->target));
            }
        }

        if ('component' === $effective && null !== $basedOn) {
            $basedOnInTarget = \in_array($basedOn, $case->target, true);
        }

        if ('component' === $outcome && \is_array($trial['component'] ?? null)) {
            [$contract, $contractProblems] = $this->judgeComponent($case, $trial['component']);
            $checks += $contract;
            $problems = [...$problems, ...$contractProblems];
        } elseif ('component' === $outcome) {
            $checks['valid'] = false;
            $problems[] = 'The trial holds no component.';
        }

        if (\is_array($trial['browser'] ?? null) && [] !== $trial['browser']) {
            [$browserOk, $browserProblems] = $this->judgeBrowser($trial['browser']);
            $checks['browser'] = $browserOk;
            $problems = [...$problems, ...$browserProblems];
        }

        return ['pass' => !\in_array(false, $checks, true), 'checks' => $checks, 'problems' => $problems, 'based_on_in_target' => $basedOnInTarget];
    }

    /**
     * @param array<mixed> $data the component of the trial
     *
     * @return array{0: array<string, bool>, 1: list<string>}
     */
    private function judgeComponent(BenchCase $case, array $data): array
    {
        try {
            $component = $this->validator->validate(new SiteComponent(
                (string) ($data['code'] ?? ''),
                (string) ($data['label'] ?? ''),
                (string) ($data['description'] ?? ''),
                array_values(array_filter(\is_array($data['props'] ?? null) ? $data['props'] : [], 'is_array')),
                \is_array($data['sample'] ?? null) ? $data['sample'] : [],
                (string) ($data['template'] ?? ''),
                (string) ($data['css'] ?? ''),
            ));
            $markup = (new SiteComponentSection($component, $this->props->read($component->props), $this->renderer))->render($component->sample, 'en_US');
        } catch (InvalidSiteComponentException $exception) {
            return [['valid' => false], $exception->problems];
        }

        $checks = ['valid' => true];
        $problems = [];

        $propsProblems = [];

        foreach ($case->props as $line) {
            $problem = $this->propsProblem($component->props, $line);

            if (null !== $problem) {
                $propsProblems[] = $problem;
            }
        }

        $checks['props'] = [] === $propsProblems;
        $problems = [...$problems, ...$propsProblems];

        [$checks['markup'], $markupProblems] = self::patterns($markup, $case->markup, 'markup');
        [$checks['css'], $cssProblems] = self::patterns($component->css, $case->css, 'stylesheet');
        $problems = [...$problems, ...$markupProblems, ...$cssProblems];

        $checks['no_h1'] = 1 !== preg_match('/<h1\b/i', $markup);

        if (!$checks['no_h1']) {
            $problems[] = 'The component draws an h1: the page holds it.';
        }

        $checks['img_alt'] = true;

        foreach (self::images($markup) as $image) {
            if ('' === trim(self::alt($image) ?? '')) {
                $checks['img_alt'] = false;
                $problems[] = \sprintf('An image has no alternative text: %s', mb_substr($image, 0, 80));
            }
        }

        foreach (self::images($component->template) as $image) {
            if (!str_contains(self::alt($image) ?? '', '{{')) {
                $checks['img_alt'] = false;
                $problems[] = \sprintf('The alternative text of an image of the template is not printed from a prop: %s', mb_substr($image, 0, 80));
            }
        }

        $animated = 1 === preg_match('/@keyframes|\banimation(?:-name)?\s*:/i', $component->css);
        $checks['reduced_motion'] = !$animated || str_contains(strtolower($component->css), 'prefers-reduced-motion');

        if (!$checks['reduced_motion']) {
            $problems[] = 'An animation is not guarded by prefers-reduced-motion.';
        }

        return [$checks, $problems];
    }

    /**
     * @param list<array<string, mixed>> $props the JSON form of the props of the component
     * @param array<string, mixed>       $line  a line of the contract
     */
    private function propsProblem(array $props, array $line): ?string
    {
        $types = explode('|', (string) ($line['type'] ?? ''));
        $with = array_values(array_filter(\is_array($line['with'] ?? null) ? $line['with'] : [], 'is_string'));
        $count = self::count($props, $types, $with);
        $min = isset($line['min']) ? (int) $line['min'] : ([] !== $with ? 1 : null);
        $max = isset($line['max']) ? (int) $line['max'] : null;
        $label = implode('|', $types).([] !== $with ? ' with '.implode(', ', $with) : '');

        if (null !== $min && $count < $min) {
            return \sprintf('Props %s: %d found, %d at least.', $label, $count, $min);
        }

        if (null !== $max && $count > $max) {
            return \sprintf('Props %s: %d found, %d at most.', $label, $count, $max);
        }

        return null;
    }

    /**
     * Props of one of the types, at any level; with `$with`, only the
     * `items` props whose rows hold every one of those types (`a|b`: either).
     *
     * @param list<array<string, mixed>> $props
     * @param list<string>               $types
     * @param list<string>               $with
     */
    private static function count(array $props, array $types, array $with): int
    {
        $count = 0;

        foreach ($props as $prop) {
            $rows = \is_array($prop['items'] ?? null) ? array_values(array_filter($prop['items'], 'is_array')) : [];

            if (\in_array($prop['type'] ?? null, $types, true) && self::holds($rows, $with)) {
                ++$count;
            }

            $count += self::count($rows, $types, $with);
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $with
     */
    private static function holds(array $rows, array $with): bool
    {
        foreach ($with as $wanted) {
            $accepted = explode('|', $wanted);

            if ([] === array_filter($rows, static fn (array $row): bool => \in_array($row['type'] ?? null, $accepted, true))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{require: list<string>, forbid: list<string>} $contract
     *
     * @return array{0: bool, 1: list<string>}
     */
    private static function patterns(string $subject, array $contract, string $name): array
    {
        $problems = [];

        foreach ($contract['require'] as $pattern) {
            if (1 !== preg_match(BenchCase::delimited($pattern), $subject)) {
                $problems[] = \sprintf('The %s lacks /%s/.', $name, $pattern);
            }
        }

        foreach ($contract['forbid'] as $pattern) {
            if (1 === preg_match(BenchCase::delimited($pattern), $subject)) {
                $problems[] = \sprintf('The %s holds /%s/.', $name, $pattern);
            }
        }

        return [[] === $problems, $problems];
    }

    /**
     * @return list<string> the `<img>` tags
     */
    private static function images(string $markup): array
    {
        preg_match_all(self::TAG, $markup, $tags, \PREG_SET_ORDER);

        return array_values(array_map(static fn (array $tag): string => $tag[0], array_filter($tags, static fn (array $tag): bool => 'img' === strtolower($tag[1]))));
    }

    private static function alt(string $tag): ?string
    {
        if (1 !== preg_match('/\salt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $match)) {
            return null;
        }

        return $match[1] ?: ($match[2] ?? '');
    }

    /**
     * @param array<mixed> $browser `{"375": {"scroll": false, "axe": [], "error": "…"}, "1440": {…}}`, `error` being optional
     *
     * @return array{0: bool, 1: list<string>}
     */
    private function judgeBrowser(array $browser): array
    {
        $problems = [];

        foreach ($browser as $width => $measure) {
            if (!\is_array($measure)) {
                continue;
            }

            // The script could not measure the component (drawn without height):
            // nothing is proven at that width, which is no pass.
            if (\is_string($measure['error'] ?? null) && '' !== $measure['error']) {
                $problems[] = \sprintf('Browser, %s px: %s.', $width, $measure['error']);
            }

            if (true === ($measure['scroll'] ?? false)) {
                $problems[] = \sprintf('The page scrolls sideways at %s px.', $width);
            }

            foreach (\is_array($measure['axe'] ?? null) ? $measure['axe'] : [] as $violation) {
                $impact = \is_array($violation) ? (string) ($violation['impact'] ?? '') : '';

                if (\in_array($impact, self::BLOCKING_IMPACTS, true)) {
                    $problems[] = \sprintf('axe-core, %s px: %s (%s).', $width, (string) ($violation['id'] ?? '?'), $impact);
                }
            }
        }

        return [[] === $problems, $problems];
    }
}
