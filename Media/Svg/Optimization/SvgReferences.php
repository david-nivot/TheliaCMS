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

namespace TheliaCMS\Media\Svg\Optimization;

/**
 * What in a document points at an identifier.
 */
final readonly class SvgReferences
{
    private const array TOKEN_ATTRIBUTES = [
        'begin', 'end', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns',
        'aria-activedescendant', 'aria-flowto', 'aria-details', 'aria-errormessage',
    ];

    /**
     * Identifiers cited by url(#id), href and xlink:href, and by the
     * attributes that list identifiers; the words of the style sheets are
     * returned apart, an identifier among them counting as cited, and their
     * text for an identifier that is not one word.
     *
     * @return array{ids: array<string, true>, style: string, words: array<string, true>}
     */
    public function in(\DOMDocument $document): array
    {
        $ids = [];
        $style = '';

        foreach ($document->getElementsByTagName('*') as $element) {
            if ('style' === $element->localName) {
                $style .= $element->textContent."\n";
            }

            foreach ($element->attributes ?? [] as $attribute) {
                $this->collect($attribute, $ids);
            }
        }

        preg_match_all('/[A-Za-z0-9_-]+/', $style, $words);

        return ['ids' => $ids, 'style' => $style, 'words' => array_fill_keys($words[0], true)];
    }

    /**
     * Looked up, not searched: a document of many identifiers and a long
     * style sheet stay quick, and `a` is not cited by every word holding
     * that letter.
     *
     * @param array{ids: array<string, true>, style: string, words: array<string, true>} $references
     */
    public function cites(array $references, string $id): bool
    {
        if (isset($references['ids'][$id])) {
            return true;
        }

        return 1 === preg_match('/^[A-Za-z0-9_-]+$/', $id)
            ? isset($references['words'][$id])
            : str_contains($references['style'], $id);
    }

    /**
     * @param array<string, true> $ids
     */
    private function collect(\DOMAttr $attribute, array &$ids): void
    {
        $value = $attribute->value;

        if (preg_match_all('/url\(\s*[\'"]?\s*#([^)\'"\s]+)/i', $value, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[$id] = true;
            }
        }

        if ('href' === $attribute->localName && str_starts_with($trimmed = trim($value), '#')) {
            $ids[substr($trimmed, 1)] = true;
        }

        if (\in_array($attribute->name, self::TOKEN_ATTRIBUTES, true)) {
            foreach (preg_split('/[\s;]+/', $value, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $ids[$token] = true;
                $ids[explode('.', $token)[0]] = true;
            }
        }
    }
}
