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

namespace TheliaCMS\Media\Svg;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TheliaCMS\Media\Svg\Color\SvgColoring;
use TheliaCMS\Media\Svg\Color\SvgPaletteReader;
use TheliaCMS\Media\Svg\Color\SvgRecoloring;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevels;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizer;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Service\Svg\SvgChange;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;

/**
 * What the library will store of an SVG once the editor has seen it.
 *
 * The original is cleaned, optimized without loss, optimized again by the
 * level the editor chose, if any, recolored by the color preset, then
 * cleaned a second time: neither an optimization nor a preset may give back
 * what the cleaning took out. The document shown is the one
 * of that last cleaning, which is the one the library stores, since it cleans
 * what it receives and keeps the bytes when nothing is left to remove. The
 * changes told to the editor are those of the first cleaning, the ones that
 * come from the file.
 */
final readonly class SvgPreparation
{
    public function __construct(
        private SvgSanitizerInterface $sanitizer,
        private SvgOptimizer $optimizer,
        private SvgOptimizationLevels $levels,
        private SvgPaletteReader $paletteReader = new SvgPaletteReader(),
        private SvgRecoloring $recoloring = new SvgRecoloring(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param string|null $levelName a level of `SvgOptimizationLevels`; one that does not exist is ignored
     *
     * @throws UnreadableSvgException
     */
    public function prepare(string $original, ?string $levelName = null, SvgColoring $coloring = new SvgColoring()): SvgPreparationResult
    {
        $cleaned = $this->sanitizer->sanitize($original);
        $optimized = $this->optimizer->optimize($cleaned->content);

        $level = null === $levelName ? null : $this->levels->get($levelName);

        if (null !== $level) {
            $optimized = $level->apply($optimized);
        }

        $palette = $this->paletteReader->read($optimized);
        $coloring = $coloring->forPalette($palette);
        $checked = $this->sanitizer->sanitize($this->recoloring->apply($optimized, $coloring, $palette));

        // Not told to the editor, whose list is that of the file: what the
        // control removes was written by a level or a preset, a defect of ours.
        if ([] !== $checked->changes) {
            $this->logger->warning('The control cleaning of a prepared SVG removed what a level or a color preset wrote.', [
                'level' => $levelName,
                'colors' => $coloring->key(),
                'changes' => array_map(static fn (SvgChange $change): string => $change->kind->value.': '.$change->subject, $checked->changes),
            ]);
        }

        return new SvgPreparationResult(
            content: $checked->content,
            changes: $cleaned->changes,
            originalBytes: \strlen($original),
            resultBytes: \strlen($checked->content),
            width: $checked->width,
            height: $checked->height,
            palette: $palette,
            coloring: $coloring,
        );
    }
}
