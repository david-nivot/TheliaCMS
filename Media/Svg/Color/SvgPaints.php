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

namespace TheliaCMS\Media\Svg\Color;

/**
 * Where the paints of a document are written, and which one an element ends
 * up with: its `style` attribute, then the rules of the `<style>` elements,
 * then its presentation attribute, then its parent's.
 *
 * The rules read are those of a simple selector (`.class`, `#id`, `tag`,
 * `tag.class`, `*`), the ones editors write; the last matching rule wins.
 * What a mask or a clip paints is a luminance or nothing at all, never a
 * color: their content is left out, and so is a gradient they paint with. A
 * shape kept in `<defs>` or a `<symbol>` is drawn only by a `<use>`, and a
 * shape a `<use>` draws takes from the `<use>` what it does not set itself (a
 * stroke, for a flag that draws its outline that way).
 *
 * Past a number of drawings or of rules, the document is not read at all:
 * the cost would grow with their product.
 */
final class SvgPaints
{
    /** The properties that carry a color, and the opacity that goes with each. */
    public const array PROPERTIES = [
        'fill' => 'fill-opacity',
        'stroke' => 'stroke-opacity',
        'stop-color' => 'stop-opacity',
        'color' => null,
    ];

    /** As many shapes drawn as the library accepts elements. */
    public const int MAXIMUM_DRAWINGS = 50000;

    public const int MAXIMUM_RULES = 1000;

    /** The elements that paint an area or a line. */
    private const array SHAPES = ['path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text'];

    /** @var list<array{sheet: int, block: int, selectors: list<list<string>>, declarations: array<string, string>}> */
    private array $rules = [];

    /** @var array<string, array<string, list<int>>> the rules that set a paint, by paint, then by `.class`, `#id`, tag or `*` */
    private array $index = [];

    /** @var array<int, true> mask and clip content, and the gradients they paint with */
    private array $protected = [];

    /** @var array<string, true> the rules a protected element reads, by `sheet:block` */
    private array $protectedBlocks = [];

    /** @var array<int, array<string, ?string>> what each element sets itself, by property */
    private array $own = [];

    /** @var list<SvgDrawing>|false|null not read yet, or too many */
    private array|false|null $drawings = null;

    /**
     * Every element, held for the life of the reading: PHP makes a new object
     * for a node it no longer holds, and gives a freed object's id to the next
     * one, so the ids the reading is keyed by hold only while the objects do.
     *
     * @var list<\DOMElement>
     */
    private array $all;

    public function __construct(private readonly \DOMDocument $document)
    {
        $this->all = iterator_to_array($document->getElementsByTagName('*'), false);
        $this->readRules();
        $this->protect();
    }

    /** False when the document has too many drawings or rules to be read. */
    public function isReadable(): bool
    {
        return \count($this->rules) <= self::MAXIMUM_RULES && null !== $this->drawings();
    }

    /**
     * @return list<\DOMElement> the elements outside a mask or a clip, and outside a gradient they paint with
     */
    public function elements(): array
    {
        $elements = [];

        foreach ($this->all as $element) {
            if (!isset($this->protected[spl_object_id($element)])) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Each time a shape is drawn: where it stands, and once more for each
     * `<use>` that draws it, with that `<use>` and the element it points at.
     *
     * @return list<SvgDrawing>|null null past the number of drawings read
     */
    public function drawings(): ?array
    {
        if (null === $this->drawings) {
            $this->drawings = $this->readDrawings() ?? false;
        }

        return false === $this->drawings ? null : $this->drawings;
    }

    /**
     * The paint a drawing ends up with, null when nothing sets it (the
     * initial value then applies: black for a fill, nothing for a stroke).
     */
    public function resolve(SvgDrawing $drawing, string $property): ?string
    {
        for ($node = $drawing->shape; $node instanceof \DOMElement; $node = $node->parentNode) {
            $value = $this->own($node, $property);

            if (null !== $value) {
                return $value;
            }

            // Past the element it points at, a drawn copy inherits from the use.
            if ($node === $drawing->target && null !== $drawing->use) {
                return $this->resolve(new SvgDrawing($drawing->use), $property);
            }
        }

        return null;
    }

    /**
     * The paint an element takes from a rule a mask or a clip also reads:
     * that rule stays, so the element is given the paint by its own style.
     */
    public function protectedRuleValue(\DOMElement $element, string $property): ?string
    {
        if (isset(SvgCss::values($element->getAttribute('style'))[$property])) {
            return null;
        }

        $rule = $this->ruleFor($element, $property);

        if (null === $rule || !$this->isProtectedBlock($this->rules[$rule]['sheet'], $this->rules[$rule]['block'])) {
            return null;
        }

        return $this->rules[$rule]['declarations'][$property];
    }

    public function isProtectedBlock(int $sheet, int $block): bool
    {
        return isset($this->protectedBlocks[$sheet.':'.$block]);
    }

    /**
     * @return list<\DOMElement>
     */
    public function styleSheets(): array
    {
        return iterator_to_array($this->document->getElementsByTagName('style'), false);
    }

    public static function withoutImportance(string $value): string
    {
        return trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);
    }

    /** What the element itself sets: its `style`, a rule, its attribute; null for nothing or `inherit`. */
    private function own(\DOMElement $element, string $property): ?string
    {
        $identifier = spl_object_id($element);

        if (\array_key_exists($property, $this->own[$identifier] ?? [])) {
            return $this->own[$identifier][$property];
        }

        $rule = $this->ruleFor($element, $property);
        $value = SvgCss::values($element->getAttribute('style'))[$property]
            ?? (null === $rule ? null : $this->rules[$rule]['declarations'][$property])
            ?? ($element->hasAttribute($property) ? $element->getAttribute($property) : null);

        return $this->own[$identifier][$property] = null === $value || 'inherit' === strtolower(trim($value)) ? null : self::withoutImportance($value);
    }

    /**
     * @return list<SvgDrawing>|null
     */
    private function readDrawings(): ?array
    {
        $elements = $this->elements();
        $drawings = [];
        $byIdentifier = [];

        foreach ($elements as $element) {
            if ($element->hasAttribute('id')) {
                $byIdentifier[$element->getAttribute('id')] ??= $element;
            }

            if (self::isShape($element) && !self::isDefinition($element)) {
                $drawings[] = new SvgDrawing($element);
            }
        }

        /** @var array<int, list<\DOMElement>> $shapesOf */
        $shapesOf = [];

        foreach ($elements as $use) {
            if ('use' !== strtolower($use->localName ?? '')) {
                continue;
            }

            $href = $use->getAttribute('href') ?: $use->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            $target = str_starts_with($href, '#') ? ($byIdentifier[substr($href, 1)] ?? null) : null;

            if (null === $target) {
                continue;
            }

            $shapes = $shapesOf[spl_object_id($target)] ??= array_values(array_filter(
                [$target, ...iterator_to_array($target->getElementsByTagName('*'), false)],
                static fn (\DOMElement $node): bool => self::isShape($node),
            ));

            if (\count($drawings) + \count($shapes) > self::MAXIMUM_DRAWINGS) {
                return null;
            }

            foreach ($shapes as $shape) {
                $drawings[] = new SvgDrawing($shape, $use, $target);
            }
        }

        return \count($drawings) > self::MAXIMUM_DRAWINGS ? null : $drawings;
    }

    private function readRules(): void
    {
        foreach ($this->styleSheets() as $sheet => $element) {
            foreach (SvgCss::blocks($element->textContent) as $block => $found) {
                $selectors = [];

                foreach (explode(',', $found['selectors']) as $selector) {
                    $compiled = self::compiled(trim($selector));

                    if (null !== $compiled) {
                        $selectors[] = $compiled;
                    }
                }

                $rule = \count($this->rules);
                $this->rules[] = [
                    'sheet' => $sheet,
                    'block' => $block,
                    'selectors' => $selectors,
                    'declarations' => SvgCss::values(substr($element->textContent, $found['start'], $found['length'])),
                ];

                foreach (array_intersect(array_keys(self::PROPERTIES), array_keys($this->rules[$rule]['declarations'])) as $property) {
                    foreach ($selectors as $selector) {
                        $this->index[$property][self::indexKey($selector)][] = $rule;
                    }
                }

                if (\count($this->rules) > self::MAXIMUM_RULES) {
                    return;
                }
            }
        }
    }

    /**
     * A simple selector as [kind, name(, class)], null for any other one.
     *
     * @return list<string>|null
     */
    private static function compiled(string $selector): ?array
    {
        return match (true) {
            '*' === $selector => ['*'],
            1 === preg_match('/^\.([\w-]+)\z/', $selector, $found) => ['class', $found[1]],
            1 === preg_match('/^#([\w-]+)\z/', $selector, $found) => ['id', $found[1]],
            1 === preg_match('/^([a-z][\w-]*)\.([\w-]+)\z/i', $selector, $found) => ['tagclass', strtolower($found[1]), $found[2]],
            1 === preg_match('/^([a-z][\w-]*)\z/i', $selector, $found) => ['tag', strtolower($found[1])],
            default => null,
        };
    }

    /**
     * @param list<string> $selector
     */
    private static function indexKey(array $selector): string
    {
        return match ($selector[0]) {
            '*' => '*',
            'class' => '.'.$selector[1],
            'id' => '#'.$selector[1],
            'tagclass' => '.'.$selector[2],
            default => $selector[1],
        };
    }

    /** The last rule that matches the element and sets the property. */
    private function ruleFor(\DOMElement $element, string $property): ?int
    {
        $index = $this->index[$property] ?? [];

        if ([] === $index) {
            return null;
        }

        $tag = strtolower($element->localName ?? '');
        $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $keys = ['*', $tag, '#'.$element->getAttribute('id'), ...array_map(static fn (string $class): string => '.'.$class, $classes)];
        $found = null;

        // Each list is in the order of the rules: its last match is its latest.
        foreach (array_unique($keys) as $key) {
            $rules = $index[$key] ?? [];

            for ($position = \count($rules) - 1; $position >= 0 && (null === $found || $rules[$position] > $found); --$position) {
                if (self::matchesAny($tag, $element->getAttribute('id'), $classes, $this->rules[$rules[$position]]['selectors'])) {
                    $found = $rules[$position];

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<string>       $classes
     * @param list<list<string>> $selectors
     */
    private static function matchesAny(string $tag, string $identifier, array $classes, array $selectors): bool
    {
        foreach ($selectors as $selector) {
            $matches = match ($selector[0]) {
                '*' => true,
                'class' => \in_array($selector[1], $classes, true),
                'id' => $identifier === $selector[1],
                'tagclass' => $selector[1] === $tag && \in_array($selector[2], $classes, true),
                default => $selector[1] === $tag,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * The content of the masks and clips, the gradients it paints with, and
     * the rules it reads.
     */
    private function protect(): void
    {
        $gradients = [];

        foreach ($this->all as $element) {
            if (!self::isInMask($element)) {
                continue;
            }

            $this->protected[spl_object_id($element)] = true;

            foreach (array_keys(self::PROPERTIES) as $property) {
                $rule = $this->ruleFor($element, $property);

                if (null !== $rule) {
                    $this->protectedBlocks[$this->rules[$rule]['sheet'].':'.$this->rules[$rule]['block']] = true;
                }

                $value = $this->own($element, $property) ?? '';

                if (1 === preg_match('/^url\(\s*[\'"]?#([^\'")\s]+)/i', $value, $found)) {
                    $gradients[$found[1]] = true;
                }
            }
        }

        if ([] === $gradients) {
            return;
        }

        // The gradients themselves, and their stops.
        foreach ($this->all as $element) {
            for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
                if (isset($gradients[$node->getAttribute('id')])) {
                    $this->protected[spl_object_id($element)] = true;

                    break;
                }
            }
        }
    }

    private static function isShape(\DOMElement $element): bool
    {
        return \in_array(strtolower($element->localName ?? ''), self::SHAPES, true);
    }

    /** Kept in `<defs>` or a `<symbol>`: drawn by a `<use>` only. */
    private static function isDefinition(\DOMElement $element): bool
    {
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (\in_array(strtolower($node->localName ?? ''), ['defs', 'symbol'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function isInMask(\DOMElement $element): bool
    {
        for ($node = $element; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (\in_array(strtolower($node->localName ?? ''), ['mask', 'clippath'], true)) {
                return true;
            }
        }

        return false;
    }
}
