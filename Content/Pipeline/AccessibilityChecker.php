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

use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Media\ImageState;
use TheliaCMS\Media\MediaDescriptions;
use TheliaCMS\TheliaCMS;

/**
 * Checks what a page says to someone who cannot see it: images with no
 * description, links with no name, links named "click here".
 *
 * Reads the HTML that goes online. An empty `alt` is ambiguous there (a
 * decorative image and a forgotten one print the same), so an image of the
 * media library is judged by what the library recorded, and an image from
 * anywhere else only by the absence of the attribute.
 */
final readonly class AccessibilityChecker
{
    /**
     * Texts that say nothing about where a link leads, by language, compared to
     * the whole text of a link: "Read more about our method" is a fine one.
     */
    private const array GENERIC_LINK_TEXTS = [
        'en' => ['click here', 'here', 'read more', 'more', 'learn more', 'link', 'this link'],
        'fr' => ['cliquez ici', 'ici', 'lire la suite', 'en savoir plus', 'voir plus', 'plus', 'suite', 'lien'],
    ];

    public function __construct(
        private MediaDescriptions $library,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string $locale the language of the content, which decides the library's description and the list of generic texts
     *
     * @return list<string> readable problems, one per kind with its count, empty when nothing is wrong
     */
    public function check(?string $html, string $locale): array
    {
        return $this->checkMany([0 => $html], $locale)[0];
    }

    /**
     * Several contents at once, the library asked once for all of them: what a
     * list of pages needs, where one question per page would be one statement
     * per page.
     *
     * @param array<array-key, string|null> $htmlByKey
     *
     * @return array<array-key, list<string>> the problems of each, under the same keys
     */
    public function checkMany(array $htmlByKey, string $locale): array
    {
        $inspected = array_map($this->inspect(...), $htmlByKey);
        $addresses = [];

        foreach ($inspected as $found) {
            foreach ($found['blankAlts'] ?? [] as $src) {
                if (1 === preg_match('#^/image-library/\d+/#', $src)) {
                    $addresses[$src] = $src;
                }
            }
        }

        $states = [] === $addresses ? [] : $this->library->statesOf(array_values($addresses), $locale);
        $problems = [];

        foreach ($inspected as $key => $found) {
            $problems[$key] = null === $found ? [] : $this->messages($found, $states, $locale);
        }

        return $problems;
    }

    /**
     * What the markup alone says: nothing the library has to be asked.
     *
     * @return array{missing: int, blankAlts: list<string>, empty: int, generic: list<string>}|null null when there is nothing to read
     */
    private function inspect(?string $html): ?array
    {
        if (null === $html || '' === trim($html)) {
            return null;
        }

        $document = new \DOMDocument();
        $loaded = @$document->loadHTML(
            '<?xml encoding="utf-8" ?><div>'.$html.'</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING,
        );

        if (!$loaded) {
            return null;
        }

        $missing = 0;
        $blankAlts = [];

        foreach ($document->getElementsByTagName('img') as $image) {
            if ($this->isHidden($image)) {
                continue;
            }

            if (!$image->hasAttribute('alt')) {
                ++$missing;
            } elseif ($this->isBlank($image->getAttribute('alt'))) {
                $blankAlts[] = $image->getAttribute('src');
            }
        }

        return ['missing' => $missing, 'blankAlts' => $blankAlts] + $this->linkProblems($document);
    }

    /**
     * @param array{missing: int, blankAlts: list<string>, empty: int, generic: list<string>} $found
     * @param array<string, ImageState>                                                       $states
     *
     * @return list<string>
     */
    private function messages(array $found, array $states, string $locale): array
    {
        $problems = [];
        $undescribed = $found['missing'];

        foreach ($found['blankAlts'] as $src) {
            if (ImageState::Undescribed === ($states[$src] ?? null)) {
                ++$undescribed;
            }
        }

        if ($undescribed > 0) {
            $problems[] = 1 === $undescribed
                ? $this->translator->trans('An image has no description. Describe it in the media library, or mark it decorative.', [], TheliaCMS::DOMAIN_NAME)
                : $this->translator->trans('%count% images have no description. Describe them in the media library, or mark them decorative.', ['%count%' => $undescribed], TheliaCMS::DOMAIN_NAME);
        }

        if ($found['empty'] > 0) {
            $problems[] = 1 === $found['empty']
                ? $this->translator->trans('A link has no text. Give it a label, or remove it.', [], TheliaCMS::DOMAIN_NAME)
                : $this->translator->trans('%count% links have no text. Give each one a label, or remove it.', ['%count%' => $found['empty']], TheliaCMS::DOMAIN_NAME);
        }

        // Generic texts are of a language: the other languages are not guessed.
        $phrases = self::GENERIC_LINK_TEXTS[strtolower(substr($locale, 0, 2))] ?? [];
        $generic = array_values(array_filter($found['generic'], static fn (string $text): bool => \in_array($text, $phrases, true)));

        if ([] !== $generic) {
            $problems[] = 1 === \count($generic)
                ? $this->translator->trans('A link text says nothing about where it leads ("%text%"). Name its destination instead.', ['%text%' => $generic[0]], TheliaCMS::DOMAIN_NAME)
                : $this->translator->trans('%count% link texts say nothing about where they lead (for example "%text%"). Name each destination instead.', ['%count%' => \count($generic), '%text%' => $generic[0]], TheliaCMS::DOMAIN_NAME);
        }

        return $problems;
    }

    /**
     * @return array{empty: int, generic: list<string>} the links with no name, and the distinct texts found that are a candidate for a generic one
     */
    private function linkProblems(\DOMDocument $document): array
    {
        $texts = [];
        $empty = 0;

        foreach ($document->getElementsByTagName('a') as $link) {
            // An anchor target is not a link, and a hidden one is not reached.
            if (!$link->hasAttribute('href') || $this->isHidden($link)) {
                continue;
            }

            if (!$this->isBlank($link->getAttribute('aria-label')) || !$this->isBlank($link->getAttribute('aria-labelledby'))) {
                continue;
            }

            $text = $this->normalize($link->textContent);

            if ('' === $text) {
                if (!$this->holdsADescribedImage($link)) {
                    ++$empty;
                }

                continue;
            }

            $texts[$text] = $text;
        }

        return ['empty' => $empty, 'generic' => array_values($texts)];
    }

    private function holdsADescribedImage(\DOMElement $link): bool
    {
        foreach ($link->getElementsByTagName('img') as $image) {
            if (!$this->isBlank($image->getAttribute('alt'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the element, or one around it, tells assistive technology to
     * skip it.
     */
    private function isHidden(\DOMElement $element): bool
    {
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            if ('true' === strtolower(trim($node->getAttribute('aria-hidden')))) {
                return true;
            }

            if (\in_array(strtolower(trim($node->getAttribute('role'))), ['presentation', 'none'], true)) {
                return true;
            }
        }

        return false;
    }

    private function isBlank(string $text): bool
    {
        return '' === $this->normalize($text);
    }

    /** Lower-cased, with anything that is not a letter or a digit folded into single spaces. */
    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }
}
