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

use TheliaCMS\Media\Svg\Optimization\SvgOptimizationLevels;
use TheliaCMS\Media\Svg\Optimization\SvgOptimizer;
use TheliaLibrary\Exception\UnreadableSvgException;
use TheliaLibrary\Service\Svg\SvgSanitizerInterface;

/**
 * What the library will store of an SVG once the editor has seen it.
 *
 * The original is cleaned, optimized without loss, optimized again by the
 * level the editor chose, if any, then cleaned a second time: an optimization
 * must not give back what the cleaning took out. The document shown is the one
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
    ) {
    }

    /**
     * @param string|null $levelName a level of `SvgOptimizationLevels`; one that does not exist is ignored
     *
     * @throws UnreadableSvgException
     */
    public function prepare(string $original, ?string $levelName = null): SvgPreparationResult
    {
        $cleaned = $this->sanitizer->sanitize($original);
        $optimized = $this->optimizer->optimize($cleaned->content);

        $level = null === $levelName ? null : $this->levels->get($levelName);

        if (null !== $level) {
            $optimized = $level->apply($optimized);
        }

        $checked = $this->sanitizer->sanitize($optimized);

        return new SvgPreparationResult(
            content: $checked->content,
            changes: $cleaned->changes,
            originalBytes: \strlen($original),
            resultBytes: \strlen($checked->content),
            width: $checked->width,
            height: $checked->height,
        );
    }
}
