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

namespace TheliaCMS\Content\Site;

use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;

/**
 * Says whether a component written by the model can be kept, and gives it
 * back as it will be: the sample normalised by its own props, the stylesheet
 * filtered of what reaches another host.
 *
 * Every problem is listed at once, as the validator of documents does, so
 * the model that wrote the component can fix them all in one turn.
 */
final readonly class SiteComponentValidator
{
    /** An opening tag: its name, then its attributes. */
    private const string TAG = '/<([a-z][a-z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i';

    /** What stands for a print of the template while its markup is read. */
    private const string PRINTED = 'cmsprinted';

    public function __construct(
        private SitePropReader $props = new SitePropReader(),
        private SiteTemplateRenderer $renderer = new SiteTemplateRenderer(),
        private SiteCssScoper $scoper = new SiteCssScoper(),
        private PublishedContentSanitizer $sanitizer = new PublishedContentSanitizer(),
    ) {
    }

    /**
     * @throws InvalidSiteComponentException listing every problem found
     */
    public function validate(SiteComponent $component): SiteComponent
    {
        $problems = [];

        if (1 !== preg_match('/^[A-Z][A-Za-z0-9]{1,39}$/', $component->code)) {
            $problems[] = \sprintf('The code "%s" is not a name in PascalCase of 2 to 40 letters and digits, such as "PriceTable".', $component->code);
        }

        if ('' === trim($component->label) || mb_strlen($component->label) > 120) {
            $problems[] = 'The label is 1 to 120 characters.';
        }

        if ('' === trim($component->description) || mb_strlen($component->description) > 500) {
            $problems[] = 'The description is 1 to 500 characters: what the component is for and when to use it.';
        }

        $schema = null;

        try {
            $schema = $this->props->read($component->props);
        } catch (InvalidSiteComponentException $exception) {
            $problems = [...$problems, ...$exception->problems];
        }

        $templateProblems = $this->renderer->problems($component->template);
        $problems = [...$problems, ...$templateProblems];

        // The publication filters what a page shows, but a draft is drawn in
        // the back office before it: what runs code is refused here already.
        if (1 === preg_match('/<\s*(script|style|iframe|object|embed|form|base|meta|link)\b|\son[a-z]+\s*=|javascript:/i', $component->template, $match)) {
            $problems[] = \sprintf('The template holds "%s": a component draws markup and nothing that runs, loads or submits; its styles go in its stylesheet.%s', $match[0], 1 === preg_match('/^<\s*(?:iframe|embed|object)$/i', $match[0]) ? ' A YouTube, Vimeo or Dailymotion video is an `embed` prop, printed as it is (`{{ props.video }}`): the module draws it and loads the player when the visitor clicks.' : '');
        }

        // The stylesheet names an animation after the component and runs it
        // only for who did not ask for less motion; an attribute does neither.
        if (1 === preg_match('/\sstyle\s*=\s*(?:"[^"]*|\'[^\']*)\banimation(?:-name)?\s*:/i', $component->template)) {
            $problems[] = 'The template sets an animation in a `style` attribute: an animation goes in the stylesheet, which names it after the component and runs it only for the visitors who did not ask for less motion; a prop may still choose it through a class or a custom property.';
        }

        if ([] === $templateProblems && [] !== $dropped = $this->dropped($component->template)) {
            $problems[] = \sprintf('The template draws %s, which a page does not keep: what a component draws is filtered like a page that goes online, and this would be taken out without a word. Draw it with the elements and attributes of HTML text and layout; a movement goes in the stylesheet.', implode(', ', $dropped));
        }

        if ([] === $templateProblems && null !== $wording = self::wording($component->template)) {
            $problems[] = \sprintf('The template writes text of its own ("%s"): every text a component shows is a prop, so that it can be written in every language of the site.', $wording);
        }

        // A stylesheet is printed inside a `style` element where a page shows
        // it inline (the preview): a `<` could close it.
        if (str_contains($component->css, '<')) {
            $problems[] = 'The stylesheet holds "<", which a stylesheet never needs.';
        }

        // Nothing loaded from anywhere: the pictures of a component are props.
        // A backslash is refused with them, since an escape spells `url(` in
        // a way no pattern reads.
        if (1 === preg_match('/\\\\|\b(?:url|image-set|image|cross-fade|element|src)\s*\(/i', $component->css, $match)) {
            $problems[] = \sprintf('The stylesheet holds "%s": a component loads no file and writes no escape; its pictures are props.', $match[0]);
        }

        // Checked as written, so the model is told what it wrote wrong; kept
        // as filtered, so nothing in it reaches another host.
        $css = (string) $this->sanitizer->css($component->css);

        try {
            $this->scoper->scope($component->css, $component->scopeClass());
        } catch (InvalidSiteComponentException $exception) {
            $problems = [...$problems, ...$exception->problems];
        }

        $sample = $component->sample;

        if (null !== $schema) {
            try {
                $sample = $schema->normalize($component->sample);
            } catch (InvalidDocumentException $exception) {
                foreach ($exception->problems as $problem) {
                    $problems[] = 'The sample: '.$problem;
                }
            }
        }

        if ([] !== $problems) {
            throw InvalidSiteComponentException::because($problems);
        }

        $kept = new SiteComponent($component->code, trim($component->label), trim($component->description), $component->props, $sample, $component->template, $css, $component->provenance);
        \assert(null !== $schema);

        if ('' === (new SiteComponentSection($kept, $schema, $this->renderer))->render($sample, 'en_US')) {
            throw InvalidSiteComponentException::because(['The sample draws nothing: it is what the toolkit and the generator are shown, so it fills the component.']);
        }

        return $kept;
    }

    /**
     * What the filter of a page takes out of the template: its elements and
     * the names of their attributes, read in every branch at once, the tags
     * and the prints taken off. Values are made plain, so an attribute is
     * named for what it is, not for what a prop may put in it (the filter
     * still sees to that when it is drawn); a Stimulus attribute keeps its
     * value, which says whether the theme opens its controller. An element
     * the template names when it runs cannot be read here: it stands as a
     * `div`, so what it holds is still read, and is left to that filter too.
     *
     * @return list<string>
     */
    private function dropped(string $template): array
    {
        $markup = (string) preg_replace(['/\{#.*?#\}/s', '/\{%.*?%\}/s', '/\{\{.*?\}\}/s', '#</[^>]*'.self::PRINTED.'[^>]*>#i'], ['', '', self::PRINTED, '</div>'], $template);
        $plain = (string) preg_replace_callback(
            self::TAG,
            static fn (array $tag): string => str_contains(strtolower($tag[1]), self::PRINTED) ? '<div>' : '<'.$tag[1].implode('', array_map(
                static fn (array $attribute): string => ' '.$attribute[0].'="'.(str_starts_with(strtolower($attribute[0]), 'data-') ? htmlspecialchars($attribute[1], \ENT_QUOTES) : 'x').'"',
                self::attributes($tag[2]),
            )).'>',
            $markup,
        );

        $dropped = array_diff(self::parts($plain), self::parts((string) $this->sanitizer->html($plain)));

        // An element taken out is named once, not with each of its attributes.
        return array_values(array_filter(
            $dropped,
            static fn (string $part): bool => 1 !== preg_match('/^".*" of (<.*>)$/', $part, $match) || !\in_array($match[1], $dropped, true),
        ));
    }

    /**
     * The elements of the markup as `<name>`, their attributes as
     * `"name" of <element>`, leaving out the names a print made.
     *
     * @return list<string>
     */
    private static function parts(string $markup): array
    {
        preg_match_all(self::TAG, $markup, $tags, \PREG_SET_ORDER);
        $parts = [];

        foreach ($tags as $tag) {
            $element = strtolower($tag[1]);
            $parts[] = '<'.$element.'>';

            foreach (self::attributes($tag[2]) as [$name]) {
                if (!str_contains(strtolower($name), self::PRINTED)) {
                    $parts[] = \sprintf('"%s" of <%s>', strtolower($name), $element);
                }
            }
        }

        return array_values(array_unique($parts));
    }

    /**
     * @return list<array{string, string}> the name and the value of each attribute
     */
    private static function attributes(string $attributes): array
    {
        preg_match_all('/([^\s"\'=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $attributes, $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [$match[1], ($match[2] ?? '').($match[3] ?? '').($match[4] ?? '')], $matches);
    }

    /**
     * The first text the template writes itself, outside of what it prints,
     * of its tags and of its markup; null when it writes none.
     */
    private static function wording(string $template): ?string
    {
        $text = (string) preg_replace(['/\{#.*?#\}/s', '/\{%.*?%\}/s', '/\{\{.*?\}\}/s', '/<(script|style)\b.*?<\/\1>/is', '/<[^>]*>/s', '/&[a-z0-9#]+;/i'], ' ', $template);

        if (1 !== preg_match('/[^\s]*\p{L}[^\s]*(?:\s+[^\s]*\p{L}[^\s]*){0,4}/u', $text, $match)) {
            return null;
        }

        return mb_substr(trim($match[0]), 0, 60);
    }
}
