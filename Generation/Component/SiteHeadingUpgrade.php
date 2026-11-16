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

namespace TheliaCMS\Generation\Component;

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Content\HeadingChoice;
use TheliaCMS\Content\HeadingSection;
use TheliaCMS\Content\Site\InvalidSiteComponentException;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteComponentRepository;
use TheliaCMS\Content\Site\SiteComponentValidator;
use TheliaCMS\Generation\GenerationLog;
use TheliaCMS\Generation\Usage;
use TheliaCMS\TheliaCMS;

/**
 * The components of the site written before their heading was the heading of
 * the site (ADR 17, 18) draw their own `h2`, at a level and in a look nobody
 * can change. The ones that draw it exactly as the generator wrote it are
 * written again, without a call to a model: the heading of the site, and two
 * props for the editor to set its level and its look.
 *
 * Each one is a version to look at and keep, like the answer of a request: a
 * component changes on no page before the editor keeps it. Any other way of
 * drawing the heading is left as it is. The rule of the stylesheet that
 * styled the old heading no longer reaches it: the version says so.
 */
final readonly class SiteHeadingUpgrade
{
    /** The heading the generator wrote: `title` tested, then drawn in an `h2` of one class. */
    private const string OWN_HEADING = '/\{% if props\.title is not empty %\}<h2 class="([A-Za-z0-9_ -]+)">\{\{ props\.title \}\}<\/h2>\{% endif %\}/';

    public function __construct(
        private SiteComponentRepository $components,
        private SiteComponentValidator $validator,
        private ComponentCandidates $candidates,
        private GenerationLog $log,
        private HeadingChoice $heading,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * The component with the heading of the site, and the class its own
     * heading had; null when it does not draw its heading as the generator
     * wrote it, or already lets the editor set it.
     *
     * @return array{component: SiteComponent, class: string}|null
     */
    public function rewrite(SiteComponent $component, string $locale): ?array
    {
        if (1 !== preg_match_all(self::OWN_HEADING, $component->template, $matches)) {
            return null;
        }

        $names = array_map(static fn (array $prop): mixed => $prop['name'] ?? null, $component->props);
        $title = array_search('title', $names, true);

        if (false === $title || 'text' !== ($component->props[$title]['type'] ?? null) || array_intersect([HeadingChoice::LEVEL_PROP, HeadingChoice::STYLE_PROP], $names)) {
            return null;
        }

        $props = $component->props;
        array_splice($props, (int) $title + 1, 0, [
            ['name' => HeadingChoice::LEVEL_PROP, 'type' => 'choice', 'label' => $this->trans('Level of the heading', $locale), 'choices' => $this->heading->levelChoices($locale), 'default' => HeadingChoice::LEVELS[0]],
            ['name' => HeadingChoice::STYLE_PROP, 'type' => 'choice', 'label' => $this->trans('Appearance of the heading', $locale), 'choices' => $this->heading->styleChoices($locale), 'default' => HeadingChoice::LEVEL_STYLE],
        ]);

        $call = \sprintf("{%% if props.title is not empty %%}{{ cms_component('%s', {text: props.title, level: props.%s, style: props.%s}) }}{%% endif %%}", HeadingSection::NAME, HeadingChoice::LEVEL_PROP, HeadingChoice::STYLE_PROP);

        return [
            'component' => new SiteComponent(
                $component->code,
                $component->label,
                $component->description,
                $props,
                $component->sample + [HeadingChoice::LEVEL_PROP => HeadingChoice::LEVELS[0], HeadingChoice::STYLE_PROP => HeadingChoice::LEVEL_STYLE],
                (string) preg_replace(self::OWN_HEADING, $call, $component->template, 1),
                $component->css,
            ),
            'class' => $matches[1][0],
        ];
    }

    /**
     * Writes a version to keep for each component of the site that draws
     * its own heading, once: a component whose latest version is already
     * this one is not offered it again.
     *
     * @return list<array{code: string, class: string, candidate: int|null, problem: string|null}> one row per component rewritten; no candidate when only listed or refused
     */
    public function propose(string $locale, bool $write = true): array
    {
        $proposed = [];

        foreach ($this->components->ready() as $component) {
            $rewritten = $this->rewrite($component, $locale);

            if (null === $rewritten) {
                continue;
            }

            $componentId = $this->components->idOf($component->code);
            $json = ComponentPrompt::json($rewritten['component']);

            if ($this->alreadyOffered($componentId, $rewritten['component'])) {
                continue;
            }

            try {
                $this->validator->validate($rewritten['component']);
            } catch (InvalidSiteComponentException $exception) {
                $proposed[] = ['code' => $component->code, 'class' => $rewritten['class'], 'candidate' => null, 'problem' => implode(' ', $exception->problems)];

                continue;
            }

            $candidate = !$write ? null : $this->log->recordComponent(
                $componentId,
                $locale,
                ComponentRequest::KIND_CHANGE,
                $this->trans('The heading of the component becomes the heading of the site: the editor sets its level and its look. The rule of the stylesheet for ".%class%" no longer styles it.', $locale, ['%class%' => str_replace(' ', '.', trim($rewritten['class']))]),
                GenerationLog::STATUS_DONE,
                '',
                new Usage(),
                0,
                0,
                0.0,
                null,
                component: $json,
            );

            $proposed[] = ['code' => $component->code, 'class' => $rewritten['class'], 'candidate' => $candidate, 'problem' => null];
        }

        return $proposed;
    }

    private function alreadyOffered(int $componentId, SiteComponent $rewritten): bool
    {
        foreach ($this->candidates->history($componentId, 5) as $candidate) {
            if (null !== $candidate->component && $candidate->component->template === $rewritten->template) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $message, string $locale, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, TheliaCMS::DOMAIN_NAME, $locale);
    }
}
