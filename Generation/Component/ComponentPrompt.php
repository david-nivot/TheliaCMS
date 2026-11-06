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

use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\Content\Site\SiteTemplateRenderer;
use TheliaCMS\Front\BlockStyles;
use TheliaCMS\Generation\ShopContext;
use TheliaCMS\Skill\SkillRepository;

/**
 * What the model is told to make a component of the site.
 *
 * The system prompt is the same at every call — the rules of the sandbox, the
 * JSON form of the props, the tokens of the stylesheet, the skills on
 * accessibility and on motion, an example — and asks the API to cache it.
 * What changes comes after, in the user message: the language of the site,
 * the components it already has, the request, and the component to change
 * when there is one.
 */
final readonly class ComponentPrompt
{
    /**
     * Elements the filter of a page takes out, named to the model so it
     * draws a movement or a control another way.
     *
     * @var list<string>
     */
    public const array ELEMENTS_TAKEN_OUT = ['input', 'select', 'textarea', 'noscript'];

    /**
     * What a template says of the width of its pictures, and a template
     * that says it: tested against the filter of the site, so the model is
     * never taught an attribute the page would take out.
     */
    public const string IMAGE_WIDTH_RULE = 'Pictures: the site offers each picture of the media library in several widths, and the browser chooses by `sizes`, the width the picture is drawn at. Without a word from the component it assumes the whole viewport. When the component draws its pictures narrower than the page (a fifth or a third of it, a row of logos, a card), put `data-cms-sizes` on the element that holds them, with the `sizes` the browser reads: `(min-width: 64rem) 20vw, 50vw` is a fifth of the page on a wide screen and half of it on a narrow one. Conditions are on `min-width` or `max-width`; widths are in `px`, `rem`, `em`, `vw`, `vh` or `calc()`; nothing else is read. A picture drawn at a fixed height, as wide as its proportions make it (a band of logos), adds `data-cms-height` on the same element, a length such as `5rem`. Both attributes are taken out of the published page; they are the only `data-` attributes a template may hold.';

    public const string IMAGE_WIDTH_EXAMPLE = '<ul class="logos" data-cms-sizes="(min-width: 64rem) 20vw, 50vw">{% for logo in props.logos %}<li class="logos__item"><img src="{{ logo.image }}" alt="{{ logo.name }}"></li>{% endfor %}</ul>';

    public function __construct(
        private SkillRepository $skills,
        private SectionCatalog $catalog,
        private ShopContext $shop,
        private BlockStyles $blocks,
    ) {
    }

    /**
     * @return list<array{type: 'text', text: string, cacheControl?: array{type: 'ephemeral'}}>
     */
    public function system(): array
    {
        return [
            ['type' => 'text', 'text' => self::role()],
            ['type' => 'text', 'text' => $this->rules(), 'cacheControl' => ['type' => 'ephemeral']],
        ];
    }

    public function userMessage(ComponentRequest $request): string
    {
        $shop = $this->shop->describe($request->locale);
        $default = $this->shop->defaultLanguage();
        $lines = [
            '# The site',
            '',
            \sprintf('Shop: %s. Language of the sample: %s (%s). Default language of the shop: %s (%s).', $shop['store_name'], $shop['language'], $shop['locale'], $default['language'], $default['locale']),
            '',
            '# The components the site already has',
            '',
            'Do not make one of these again; make what none of them does.',
            '',
        ];

        foreach ($this->catalog->all() as $name => $definition) {
            if (null !== $request->current && $name === $request->current->name()) {
                continue;
            }

            $lines[] = \sprintf('- `%s` (%s): %s', $name, $definition->label(), $definition->description());
        }

        $lines[] = '';

        if (null !== $request->current) {
            $lines[] = '# The component to change';
            $lines[] = '';
            $lines[] = \sprintf('Keep its code, `%s`. Keep the props pages may already hold unless the request asks otherwise: a page written with a prop the component no longer takes stops being valid.', $request->current->code);
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = self::json($request->current);
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '# The change asked';
        } else {
            $lines[] = '# The component asked';
        }

        $lines[] = '';
        $lines[] = trim($request->text);

        return implode("\n", $lines);
    }

    /**
     * The message that sends a component back for correction.
     *
     * @param list<string> $problems
     */
    public static function correction(array $problems): string
    {
        return "The component you answered with cannot be kept. Fix every problem below and answer with the whole component again:\n\n- ".implode("\n- ", $problems);
    }

    /**
     * The component as the answer writes it: props and sample as JSON strings.
     */
    public static function json(SiteComponent $component): string
    {
        return json_encode([
            'code' => $component->code,
            'label' => $component->label,
            'description' => $component->description,
            'props' => json_encode($component->props, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'sample' => json_encode((object) $component->sample, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            'template' => $component->template,
            'css' => $component->css,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
    }

    /**
     * The JSON schema the answer is constrained to.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $string = static fn (string $description): array => ['type' => 'string', 'description' => $description];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['code', 'label', 'description', 'props', 'sample', 'template', 'css'],
            'properties' => [
                'code' => $string('PascalCase, 2 to 40 letters and digits, saying what the component is: "PriceTable".'),
                'label' => $string('The name of the component in a list, in the default language of the shop: two to four words, such as "Price table". What it does goes in the description.'),
                'description' => $string('One or two sentences, in English, on what the component is for and when a page uses it: what the generator of pages reads to pick it.'),
                'props' => $string('The list of the props, as a JSON array written as a string, in the form the rules describe.'),
                'sample' => $string('The props of the example, as a JSON object written as a string: every prop filled, in the language of the site.'),
                'template' => $string('The Twig template, under the rules of the sandbox.'),
                'css' => $string('The stylesheet of the component; empty when it needs none.'),
            ],
        ];
    }

    private static function role(): string
    {
        return <<<'TEXT'
            You make components for the pages of the website of a shop: a section a page can hold, such as a table of prices, a timeline, a list of steps. An editor asks for one in their own words; you answer with the component, which the site checks, shows them, and keeps once they agree.

            A component is a set of props (what an editor or the generator of pages fills in), an example of them, a Twig template that draws them, and a stylesheet. It is used by many pages, in every language of the site: it holds no wording of its own, only the markup and the props that carry the text.
            TEXT;
    }

    private function rules(): string
    {
        $accessibility = $this->skill('accessibility');
        $motion = $this->skill('motion');

        return implode("\n", [
            '# Props',
            '',
            'A JSON array of objects, each with `name` (lowercase letters, digits and underscores), `type`, `label`, and optionally `help`, `required` and `default`. The types:',
            '',
            '- `text`: a line of plain text, printed escaped. `max` (1 to 2000, 255 by default).',
            '- `html`: markup an editor writes (paragraphs, lists, links), printed as it is.',
            '- `integer`: `min`, `max`, `default`.',
            '- `boolean`: `default` true or false.',
            '- `choice`: `choices`, an object of value => label (values of lowercase letters, digits, hyphens), and a `default` among them. For a variant of the look.',
            '- `image`: a picture of the media library. The template receives its address, or null when there is none.',
            '- `video`: an MP4 video of the media library. The template receives the address of its file, or null when there is none: print it in a `<video>` with `preload="none"`, never playing on its own with sound.',
            '- `url`: a link, a path of the site or an absolute address.',
            '- `items`: a list of objects, `items` being the props of one (any type but `items`), `min` and `max` (at most 24). For rows, cards, steps.',
            '',
            '```json',
            '[{"name": "title", "type": "text", "label": "Heading", "max": 120}, {"name": "steps", "type": "items", "label": "Steps", "min": 2, "max": 8, "items": [{"name": "title", "type": "text", "label": "Step", "required": true, "max": 80}, {"name": "text", "type": "html", "label": "What happens"}]}]',
            '```',
            '',
            'The `label` of the component, and the `label`, the `help` and the labels of the `choices` of every prop, rows included, are written in the default language of the shop, the one the request names, whatever the language of the request and of the sample: an editor reads them in the forms of the back office. The example above is in English for this text only. A `name` stays a key of lowercase letters, digits and underscores, which no editor reads.',
            '',
            'Twenty props at most. Every text the component shows is a prop: a heading, a button, a label next to a price. A label that repeats on every item (such as "per month") is a prop of the component, not of each item.',
            '',
            '# Template',
            '',
            'Twig in a sandbox. The template receives `props` and nothing else (`props.title`, `item.text` in a loop).',
            '',
            \sprintf('- Tags: %s. Filters: %s. Tests: %s. Functions: %s. Nothing else: no include, no macro, no block, no `raw`.', implode(', ', SiteTemplateRenderer::TAGS), implode(', ', SiteTemplateRenderer::FILTERS), implode(', ', SiteTemplateRenderer::TESTS), implode(', ', SiteTemplateRenderer::FUNCTIONS)),
            \sprintf('- At most %d `for` inside each other: loop over a list, and over the list of an item, never deeper.', SiteTemplateRenderer::MAX_LOOP_DEPTH),
            '- Text is escaped when printed; an `html` prop is printed as it is.',
            '- No text of its own outside what it prints: the template writes markup and props, nothing else.',
            '- No `script`, `style`, `iframe`, `object`, `embed`, `form`, `link`, `meta`, no `on…` attribute, no `javascript:`.',
            '- What it draws is filtered like a page that goes online, which keeps the elements of HTML text and layout and their attributes, and takes out the rest without a word: '.implode(', ', array_map(static fn (string $element): string => 'no `'.$element.'`', self::ELEMENTS_TAKEN_OUT)).', no `data-` attribute but the two of the width of the pictures, below. The template is refused when it holds another.',
            '- '.self::IMAGE_WIDTH_RULE.' For instance: `'.self::IMAGE_WIDTH_EXAMPLE.'`',
            '- One root element, a `section` or a `div`. Headings start at `h2`: the page has its own `h1`.',
            '- Test what may be empty before drawing it: `{% if props.image %}`, `{% if props.title is not empty %}`. An image is drawn with its `alt` from a prop.',
            '- Class names describe the parts (`plan`, `plan__price`); the site puts the component inside an element of its own class, which the stylesheet is scoped to.',
            '',
            '# Stylesheet',
            '',
            '- Plain CSS, each rule on its own: no nesting. Selectors are written as if the component were alone: the site puts each of them under the class of the component. `:scope` (or a selector starting with `&`) is that element itself.',
            '- Rules may be grouped in `@media`, `@supports`, `@container`; no other at-rule (`@import`, `@font-face`, `@property`) but `@keyframes`.',
            '- A movement is a `@keyframes` of a plain name with its steps (`from`, `to`, percentages), used by `animation` or `animation-name`; the site names it after the component. A rule that sets an animation sits in `@media (prefers-reduced-motion: no-preference)`, so a visitor who asks for less motion sees the component still; never in a `style` attribute of the template (a prop picks a speed or a direction through a class or a custom property). A movement that lasts more than five seconds stops under the pointer and on focus (`animation-play-state: paused` on `:hover` and `:focus-within`).',
            '- Nothing loaded: no `url()`, no `image-set()`, no backslash escape, no font of its own. Pictures are `image` props drawn with `<img>`; the component uses the fonts of the theme.',
            '- `:scope` and `&` only start a selector, followed by a space or `>`: the stylesheet styles the component and what is inside it, never what is around it.',
            '- Colours come from the tokens of the site, so the component follows its theme: '.implode(', ', array_map(static fn (string $token): string => '`var('.$token.')`', $this->tokens())).'.',
            '- Mobile first: the component reads on a phone, and lays out wider from `@media (min-width: 48rem)`.',
            '',
            '# Sample',
            '',
            'The props of the example: what the toolkit shows and what the generator of pages sees. Fill every prop with realistic text in the language of the site, as many items as a real page would hold. An image is null unless the request gives the `https` address of a picture.',
            '',
            // Each skill opens with its own heading.
            ...array_map(static fn (string $skill): string => trim($skill)."\n", array_filter([$accessibility, $motion])),
            '# Example',
            '',
            'For a shop whose default language is French, asked in French:',
            '',
            '```json',
            '{"code": "ProcessSteps", "label": "Étapes", "description": "The steps of a process in order, each a short title and a sentence. Use it for how an order, a booking or a service goes.", "props": "[{\"name\": \"title\", \"type\": \"text\", \"label\": \"Titre\", \"max\": 120}, {\"name\": \"steps\", \"type\": \"items\", \"label\": \"Étapes\", \"min\": 2, \"max\": 8, \"items\": [{\"name\": \"title\", \"type\": \"text\", \"label\": \"Étape\", \"required\": true, \"max\": 80}, {\"name\": \"text\", \"type\": \"text\", \"label\": \"Ce qui se passe\", \"max\": 300}]}]", "sample": "{\"title\": \"Comment ça marche\", \"steps\": [{\"title\": \"Vous réservez\", \"text\": \"Choisissez un créneau en ligne.\"}, {\"title\": \"Nous confirmons\", \"text\": \"Un e-mail vous le confirme dans l’heure.\"}]}", "template": "<section class=\"steps\">{% if props.title is not empty %}<h2 class=\"steps__title\">{{ props.title }}</h2>{% endif %}<ol class=\"steps__list\">{% for step in props.steps %}<li class=\"step\"><h3 class=\"step__title\">{{ step.title }}</h3>{% if step.text is not empty %}<p class=\"step__text\">{{ step.text }}</p>{% endif %}</li>{% endfor %}</ol></section>", "css": ".steps__list { display: grid; gap: 1.5rem; padding: 0; list-style: none; counter-reset: step; }\n.step { counter-increment: step; padding-top: 1rem; border-top: 2px solid var(--cms-color-accent); }\n.step__title::before { content: counter(step) \". \"; }\n.step__text { color: var(--cms-color-text-muted); }\n@media (min-width: 48rem) { .steps__list { grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); } }"}',
            '```',
        ]);
    }

    /**
     * The text of a skill, null when the site has none of that code or
     * switched it off under CMS > Skills.
     */
    private function skill(string $code): ?string
    {
        $skill = $this->skills->find($code);

        return null !== $skill && $skill->enabled ? $skill->body : null;
    }

    /**
     * The colour tokens of the stylesheet of the module, read from it so the
     * prompt names the ones the site actually defines.
     *
     * @return list<string>
     */
    private function tokens(): array
    {
        $css = is_file($this->blocks->path()) ? (string) file_get_contents($this->blocks->path()) : '';
        preg_match_all('/^\s*(--cms-color-[a-z-]+)\s*:/m', $css, $matches);

        return array_values(array_unique($matches[1]));
    }
}
