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
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevel;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevels;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizer;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Service\Svg\SvgChange;
use TheliaLibrary\Service\Svg\SvgChangeKind;
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
 *
 * What comes before the colors (the cleaning changes, the base optimization,
 * the output of each level) is kept by the bytes of the file, so that a
 * change of colors redoes the colors and the control only.
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
        // Null: nothing is kept between two preparations.
        private ?SvgStageCache $stages = null,
    ) {
    }

    /**
     * @param string|null $levelName a level of `SvgOptimizationLevels`; one that does not exist is ignored
     *
     * @throws UnreadableSvgException
     */
    public function prepare(string $original, ?string $levelName = null, SvgColoring $coloring = new SvgColoring()): SvgPreparationResult
    {
        [$changes, $base] = $this->base($original);
        $level = null === $levelName ? null : $this->levels->get($levelName);

        return $this->finished($original, $changes, null === $level ? $base : $this->leveled($original, $base, $level), $levelName, $coloring);
    }

    /**
     * Every level applied on its own to the same base optimization, computed
     * once: the levels are not compared with one another.
     *
     * @return array<string, SvgPreparationResult> by level name, in the order of the levels
     *
     * @throws UnreadableSvgException
     */
    public function prepareLevels(string $original, SvgColoring $coloring = new SvgColoring()): array
    {
        [$changes, $base] = $this->base($original);
        $results = [];

        foreach ($this->levels->all() as $level) {
            $results[$level->name()] = $this->finished($original, $changes, $this->leveled($original, $base, $level), $level->name(), $coloring);
        }

        return $results;
    }

    /**
     * The changes of the cleaning of the file sent and its base optimization,
     * kept for the next preparation of the same bytes.
     *
     * @return array{list<SvgChange>, string}
     *
     * @throws UnreadableSvgException
     */
    private function base(string $original): array
    {
        $key = SvgStageCache::key($original);
        $kept = $this->stages?->get($key, 'base');
        $changes = $this->stages?->get($key, 'changes');
        $decoded = null === $changes ? null : json_decode($changes, true);

        if (null !== $kept && \is_array($decoded)) {
            return [array_map(static fn (array $change): SvgChange => new SvgChange(SvgChangeKind::from($change[0]), $change[1]), $decoded), $kept];
        }

        $cleaned = $this->sanitizer->sanitize($original);
        $base = $this->optimizer->optimize($cleaned->content);
        $this->stages?->put($key, 'changes', json_encode(array_map(static fn (SvgChange $change): array => [$change->kind->value, $change->subject], $cleaned->changes), \JSON_THROW_ON_ERROR));
        $this->stages?->put($key, 'base', $base);

        return [$cleaned->changes, $base];
    }

    /** The output of a level on the base, kept as the base is: before any color. */
    private function leveled(string $original, string $base, SvgOptimizationLevel $level): string
    {
        $key = SvgStageCache::key($original);
        $stage = 'level-'.$level->name();
        $kept = $this->stages?->get($key, $stage);

        if (null !== $kept) {
            return $kept;
        }

        $output = $level->apply($base);
        $this->stages?->put($key, $stage, $output);

        return $output;
    }

    /**
     * @param list<SvgChange> $changes those of the first cleaning
     */
    private function finished(string $original, array $changes, string $optimized, ?string $levelName, SvgColoring $coloring): SvgPreparationResult
    {
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
            changes: $changes,
            originalBytes: \strlen($original),
            resultBytes: \strlen($checked->content),
            width: $checked->width,
            height: $checked->height,
            palette: $palette,
            coloring: $coloring,
        );
    }
}
