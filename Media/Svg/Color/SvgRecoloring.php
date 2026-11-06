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

use TheliaCMS\Media\Svg\Optimization\SvgDocument;

/**
 * Applies a color preset: each color the preset makes follow the text is
 * written `currentColor`, wherever it is written (presentation attribute,
 * `style` attribute, rule of a `<style>`), with a lower opacity where the
 * preset asks for one or the color was transparent. A shape that sets no fill
 * gets one when its black follows the text.
 *
 * The stops of a gradient follow the same rule: in monochrome, a gradient
 * becomes one color. A mask or a clip is never touched, nor a gradient or a
 * rule it reads: its colors are a luminance, not something the visitor sees.
 * A visible shape painted by such a rule is given its new paint by its own
 * `style`. A drawing too complex to read is left as it is.
 */
final readonly class SvgRecoloring
{
    public function apply(string $svg, SvgColoring $coloring, SvgPalette $palette): string
    {
        $coloring = $coloring->forPalette($palette);

        if (!$palette->readable || $coloring->changesNothing() || ($coloring->usesMain() && null === $coloring->main)) {
            return $svg;
        }

        $document = SvgDocument::load($svg);

        if (null === $document) {
            return $svg;
        }

        $paints = new SvgPaints($document);
        $drawings = $paints->drawings();

        if (null === $drawings || !$paints->isReadable()) {
            return $svg;
        }

        // Read before anything is written.
        $unpainted = $this->unpainted($paints, $drawings);
        $byProtectedRules = $this->paintedByProtectedRules($paints);

        foreach ($paints->elements() as $element) {
            $this->recolorAttributes($element, $coloring);
            $this->recolorStyleAttribute($element, $coloring);
        }

        foreach ($paints->styleSheets() as $sheet => $element) {
            $element->textContent = $this->recolorSheet($element->textContent, $sheet, $paints, $coloring);
        }

        foreach ($byProtectedRules as [$element, $property, $value]) {
            $found = $this->target($value, $coloring);

            if (null !== $found) {
                $opacityProperty = SvgPaints::PROPERTIES[$property];
                $style = trim($element->getAttribute('style'), " \t\n;");
                $declaration = $property.':'.$found[0]
                    .(null !== $opacityProperty && $found[1] < 1.0 ? ';'.$opacityProperty.':'.SvgCss::lowered($element->getAttribute($opacityProperty) ?: null, $found[1]) : '');
                $element->setAttribute('style', '' === $style ? $declaration : $style.';'.$declaration);
            }
        }

        $black = $this->opacity(SvgColor::BLACK, $coloring);

        foreach (null === $black ? [] : $unpainted as $shape) {
            $shape->setAttribute('fill', 'currentColor');
            self::lowerAttributeOpacity($shape, 'fill-opacity', $black);
        }

        return SvgDocument::serialize($document);
    }

    /**
     * The shapes painted black because nothing sets their fill, wherever they
     * are drawn. A shape a `<use>` fills in one place and not in another keeps
     * what it has.
     *
     * @param list<SvgDrawing> $drawings
     *
     * @return list<\DOMElement>
     */
    private function unpainted(SvgPaints $paints, array $drawings): array
    {
        $shapes = [];
        $painted = [];

        foreach ($drawings as $drawing) {
            if (!$drawing->paintsAnArea()) {
                continue;
            }

            $identifier = spl_object_id($drawing->shape);
            $shapes[$identifier] = $drawing->shape;

            if (null !== $paints->resolve($drawing, 'fill')) {
                $painted[$identifier] = true;
            }
        }

        return array_values(array_diff_key($shapes, $painted));
    }

    /**
     * @return list<array{\DOMElement, string, string}> the element, the property and the value a protected rule gives it
     */
    private function paintedByProtectedRules(SvgPaints $paints): array
    {
        $painted = [];

        foreach ($paints->elements() as $element) {
            foreach (array_keys(SvgPaints::PROPERTIES) as $property) {
                $value = $paints->protectedRuleValue($element, $property);

                if (null !== $value) {
                    $painted[] = [$element, $property, SvgPaints::withoutImportance($value)];
                }
            }
        }

        return $painted;
    }

    /**
     * What a paint value becomes: null when it stays, else `currentColor` and
     * the opacity it is drawn with (its own transparency included).
     *
     * @return array{string, float}|null
     */
    private function target(string $value, SvgColoring $coloring): ?array
    {
        $key = SvgColor::key($value);
        $opacity = null === $key ? null : $this->opacity($key, $coloring);

        return null === $opacity ? null : ['currentColor', $opacity];
    }

    private function opacity(string $key, SvgColoring $coloring): ?float
    {
        $white = SvgColor::WHITE === $key;

        $opacity = match ($coloring->preset) {
            SvgColorPreset::Keep => null,
            SvgColorPreset::Monochrome => $white && $coloring->keepWhite ? null : 1.0,
            SvgColorPreset::Main => match (true) {
                $key === $coloring->main => 1.0,
                !$coloring->softens(), $white && $coloring->keepWhite => null,
                default => SvgColoring::SOFTENED_OPACITY,
            },
        };

        return null === $opacity ? null : $opacity * SvgColor::alpha($key);
    }

    private function recolorAttributes(\DOMElement $element, SvgColoring $coloring): void
    {
        foreach (SvgPaints::PROPERTIES as $property => $opacityProperty) {
            if (!$element->hasAttribute($property)) {
                continue;
            }

            $found = $this->target(SvgPaints::withoutImportance($element->getAttribute($property)), $coloring);

            if (null === $found) {
                continue;
            }

            $element->setAttribute($property, $found[0]);

            if (null !== $opacityProperty) {
                self::lowerAttributeOpacity($element, $opacityProperty, $found[1]);
            }
        }
    }

    private function recolorStyleAttribute(\DOMElement $element, SvgColoring $coloring): void
    {
        if (!$element->hasAttribute('style')) {
            return;
        }

        // An opacity the element sets as an attribute is the one the style lowers.
        $outside = [];

        foreach (SvgPaints::PROPERTIES as $opacityProperty) {
            if (null !== $opacityProperty && $element->hasAttribute($opacityProperty)) {
                $outside[$opacityProperty] = $element->getAttribute($opacityProperty);
            }
        }

        $style = $element->getAttribute('style');
        $recolored = SvgCss::rewrite($style, fn (string $value): ?array => $this->target($value, $coloring), $outside);

        if ($recolored !== $style) {
            $element->setAttribute('style', $recolored);
        }
    }

    private function recolorSheet(string $text, int $sheet, SvgPaints $paints, SvgColoring $coloring): string
    {
        // From the last rule, so that each place still points where it did.
        foreach (array_reverse(SvgCss::blocks($text), true) as $block => $found) {
            if ($paints->isProtectedBlock($sheet, $block)) {
                continue;
            }

            $content = substr($text, $found['start'], $found['length']);
            $recolored = SvgCss::rewrite($content, fn (string $value): ?array => $this->target($value, $coloring));
            $text = substr_replace($text, $recolored, $found['start'], $found['length']);
        }

        return $text;
    }

    private static function lowerAttributeOpacity(\DOMElement $element, string $property, float $opacity): void
    {
        if ($opacity >= 1.0) {
            return;
        }

        $element->setAttribute($property, SvgCss::lowered($element->hasAttribute($property) ? $element->getAttribute($property) : null, $opacity));
    }
}
