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
 * The colors a drawing is painted with.
 *
 * A color is counted once per element it paints (once more for each
 * `<use>` that draws the element again), the fill and the stroke apart: the most used color is the one of the most elements, not of the
 * largest area. A shape that sets no fill is painted black, and counts so.
 * The stops of the gradients are counted apart: they paint no element alone.
 */
final readonly class SvgPaletteReader
{
    public function read(string $svg): SvgPalette
    {
        $document = SvgDocument::load($svg);

        if (null === $document) {
            return new SvgPalette();
        }

        $paints = new SvgPaints($document);
        $drawings = $paints->drawings();

        if (null === $drawings || !$paints->isReadable()) {
            return new SvgPalette(readable: false);
        }

        /** @var array<string, array{fills: int, strokes: int, stops: int}> $counts */
        $counts = [];
        $followsText = 0;
        $count = static function (?string $paint, string $usage) use (&$counts, &$followsText): void {
            if (null !== $paint && 'currentcolor' === strtolower($paint)) {
                ++$followsText;

                return;
            }

            $key = null === $paint ? null : SvgColor::key($paint);

            if (null === $key) {
                return;
            }

            $counts[$key] ??= ['fills' => 0, 'strokes' => 0, 'stops' => 0];
            ++$counts[$key][$usage];
        };

        foreach ($drawings as $drawing) {
            if ($drawing->paintsAnArea()) {
                $count($paints->resolve($drawing, 'fill') ?? SvgColor::BLACK, 'fills');
            }

            $count($paints->resolve($drawing, 'stroke'), 'strokes');
        }

        $gradients = 0;

        foreach ($paints->elements() as $element) {
            $name = strtolower($element->localName ?? '');

            if ('stop' === $name) {
                $stop = $paints->resolve(new SvgDrawing($element), 'stop-color');
                $count($stop ?? SvgColor::BLACK, 'stops');
            }

            if (\in_array($name, ['lineargradient', 'radialgradient'], true)) {
                ++$gradients;
            }
        }

        $colors = array_map(
            static fn (string $key, array $usage): PaletteColor => new PaletteColor((string) $key, $usage['fills'], $usage['strokes'], $usage['stops']),
            array_keys($counts),
            $counts,
        );

        // The most painted first; between equals, the first met in the file.
        $order = array_flip(array_keys($counts));
        usort($colors, static fn (PaletteColor $one, PaletteColor $other): int => [$other->painted(), $order[$one->key]] <=> [$one->painted(), $order[$other->key]]);

        return new SvgPalette($colors, $gradients, $followsText);
    }
}
