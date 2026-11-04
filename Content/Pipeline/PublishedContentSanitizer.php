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

namespace TheliaCMS\Content\Pipeline;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use TheliaCMS\Content\DocumentRenderer;
use TheliaCMS\Content\Theme\ThemeComponentSections;

/**
 * Filters what is about to go online.
 *
 * The person editing a page is not a trusted author: on a customer site they
 * are whoever the shop gave a back-office account to. What the editor produces
 * is therefore filtered on the server before it is published, so that no
 * client-side setting is the only thing standing between a form field and a
 * stored cross-site scripting payload.
 */
final readonly class PublishedContentSanitizer
{
    /**
     * An iframe calls a third party on every visit, before anyone has agreed
     * to anything: one written in a page is refused unless the author holds
     * the custom code resource.
     */
    private const array EMBED_ELEMENTS = ['iframe'];

    /**
     * Accessibility attributes kept on the way out.
     *
     * The default allow list of the sanitiser drops every `aria-*` attribute,
     * which quietly undid the one thing the block catalogue is careful about:
     * a section named by its own heading, an icon hidden from screen readers,
     * the current entry of a menu. None of them can carry script or a URL.
     */
    private const array ACCESSIBILITY_ATTRIBUTES = [
        'role',
        'aria-label',
        'aria-labelledby',
        'aria-describedby',
        'aria-hidden',
        'aria-current',
        'aria-expanded',
        'aria-controls',
        'aria-live',
        'aria-roledescription',
        'lang',
        'dir',
    ];

    /**
     * The icons a component of the theme draws inline (`ux_icon()`): shapes
     * and the attributes that place and colour them, nothing that loads,
     * links or runs. No `use`, `image`, `a`, `foreignObject`, `style`,
     * `script` or `animate*`, and no event handler or `href`.
     *
     * The sanitizer writes attribute names in lowercase (`viewbox`); the HTML
     * parser of a browser puts the case of `viewBox` back inside an `svg`.
     */
    private const array SVG_ELEMENTS = [
        'svg' => ['xmlns', 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'fill-opacity', 'fill-rule', 'clip-rule', 'opacity'],
        'g' => ['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-opacity', 'fill-opacity', 'fill-rule', 'clip-rule', 'opacity', 'transform'],
        'path' => ['d', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'fill-opacity', 'fill-rule', 'clip-rule', 'opacity', 'transform'],
        'circle' => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'stroke-opacity', 'fill-opacity', 'opacity', 'transform'],
        'ellipse' => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'stroke-opacity', 'fill-opacity', 'opacity', 'transform'],
        'rect' => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width', 'stroke-linejoin', 'stroke-opacity', 'fill-opacity', 'opacity', 'transform'],
        'line' => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-dasharray', 'stroke-opacity', 'opacity', 'transform'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'fill-opacity', 'opacity', 'transform'],
        'polygon' => ['points', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'fill-opacity', 'fill-rule', 'opacity', 'transform'],
    ];

    /**
     * The longest markup read, in bytes. The sanitizer of Symfony stops at
     * 20 000 by default and drops the rest without a word: a page of terms of
     * sale, or a recipe whose components are drawn with their classes, went
     * online cut in the middle of a sentence. A shop that publishes longer
     * pages still passes its own length to the service.
     */
    public const int DEFAULT_MAX_LENGTH = 1_000_000;

    /**
     * What a Stimulus controller reads off an element, after `data-<identifier>-`.
     */
    private const string STIMULUS_ATTRIBUTE = '/^(?:target|[a-z0-9]+(?:-[a-z0-9]+)*-(?:value|param|class|outlet))$/';

    /**
     * @param ThemeComponentSections|null $theme the theme that names the Stimulus controllers its components use; without it, none is kept
     */
    public function __construct(
        private int $maxLength = self::DEFAULT_MAX_LENGTH,
        private ?ThemeComponentSections $theme = null,
    ) {
    }

    public function html(?string $html, bool $allowCustomCode = false): ?string
    {
        if (null === $html || '' === trim($html)) {
            return $html;
        }

        return $this->filterStyleAttributes($this->sanitizerFor($allowCustomCode, $html)->sanitize($html));
    }

    /**
     * The attributes of the Stimulus controllers the theme names, as the
     * markup spells them. The sanitizer only takes exact names, and those of
     * a value or a parameter are made of the name the component gives it.
     *
     * @param list<string> $controllers
     *
     * @return list<string>
     */
    private static function stimulusAttributes(string $html, array $controllers): array
    {
        if ([] === $controllers) {
            return [];
        }

        $prefixes = array_map(static fn (string $controller): string => 'data-'.strtolower($controller).'-', $controllers);
        $attributes = ['data-controller', 'data-action'];
        preg_match_all('/\sdata-[a-z0-9-]+/i', $html, $matches);

        foreach (array_unique(array_map(static fn (string $name): string => strtolower(trim($name)), $matches[0])) as $name) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix) && 1 === preg_match(self::STIMULUS_ATTRIBUTE, substr($name, \strlen($prefix)))) {
                    $attributes[] = $name;

                    break;
                }
            }
        }

        return $attributes;
    }

    /**
     * A `style` attribute is CSS too: what `css()` keeps out of a stylesheet
     * is kept out of it, so that markup cannot load from another host what a
     * stylesheet may not, and no rule opens in it. The sanitizer quotes every
     * attribute with `"` and escapes its value, so the attribute is read back
     * from its output.
     */
    private function filterStyleAttributes(string $html): string
    {
        return (string) preg_replace_callback(
            '/(\sstyle=")([^"]*)(")/i',
            static fn (array $match): string => $match[1]
                .htmlspecialchars((new CssAllowList())->declarations(html_entity_decode($match[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8')), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                .$match[3],
            $html,
        );
    }

    /**
     * How many elements of custom code the markup holds: what a sanitizer
     * without the right to them would take out.
     */
    public function customCodeCount(?string $html): int
    {
        if (null === $html || '' === $html) {
            return 0;
        }

        $count = 0;

        foreach (self::EMBED_ELEMENTS as $element) {
            $count += preg_match_all('/<'.$element.'\b/i', $html);
        }

        return $count;
    }

    /**
     * The stylesheet is not run through the HTML sanitizer — it never reaches
     * the parser as markup. What it must not carry is a request to a third
     * party (a privacy leak, and a bypass of the consent gate) or the legacy
     * Internet Explorer script vector: it keeps what `CssAllowList` reads as
     * drawing only.
     */
    public function css(?string $css): ?string
    {
        if (null === $css || '' === trim($css)) {
            return $css;
        }

        return (new CssAllowList())->stylesheet($css);
    }

    private function sanitizerFor(bool $allowCustomCode, string $html): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            ->withMaxInputLength($this->maxLength)
            ->allowSafeElements()
            ->allowAttribute('class', '*')
            ->allowAttribute('id', '*')
            ->allowAttribute('style', '*')
            ->allowAttribute('loading', ['img', 'iframe'])
            ->allowAttribute('decoding', ['img'])
            ->allowAttribute('fetchpriority', ['img'])
            // Server-rendered partial blocks: the renderer validates the name
            // against its registry and the props against a schema.
            ->allowAttribute('data-cms-partial', '*')
            ->allowAttribute('data-props', '*')
            // The section an element renders, which the content screen
            // reads to point at it in the preview.
            ->allowAttribute(DocumentRenderer::SECTION_ATTRIBUTE, '*')
            // `open` is what an author toggles on a question that should start
            // expanded; without it a `<details>` always comes back closed.
            ->allowAttribute('open', ['details'])
            // Controls a script reveals start hidden; without the attribute
            // they show, and do nothing, until it runs.
            ->allowAttribute('hidden', '*')
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            // No data: URI — an SVG smuggled into one carries script.
            ->allowMediaSchemes(['https', 'http'])
            ->allowRelativeLinks()
            ->allowRelativeMedias();

        foreach (self::ACCESSIBILITY_ATTRIBUTES as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        // A component of the theme wires its behaviour with Stimulus. The
        // attributes of the controllers the theme names are kept, and their
        // values are held to those controllers: written text cannot wire one
        // the theme never opened to pages.
        $controllers = $this->theme?->declaration()->controllers ?? [];

        foreach (self::stimulusAttributes($html, $controllers) as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        if ([] !== $controllers) {
            $config = $config->withAttributeSanitizer(new StimulusAttributeSanitizer($controllers));
        }

        foreach (self::SVG_ELEMENTS as $element => $attributes) {
            // An element with a list of its own loses the attributes allowed
            // on every element: the common ones are named again.
            $config = $config->allowElement($element, [...$attributes, 'class', 'id', 'style', ...self::ACCESSIBILITY_ATTRIBUTES]);
        }

        // `<image>` is read as `<img>` by the sanitizer and would load a
        // picture from wherever it is told to.
        $config = $config->dropElement('image');

        $config = $config->withAttributeSanitizer(new SvgAttributeSanitizer());

        foreach (self::EMBED_ELEMENTS as $element) {
            $config = $allowCustomCode
                ? $config->allowElement($element, ['src', 'title', 'width', 'height', 'allow', 'allowfullscreen', 'loading'])
                : $config->blockElement($element);
        }

        return new HtmlSanitizer($config);
    }
}
