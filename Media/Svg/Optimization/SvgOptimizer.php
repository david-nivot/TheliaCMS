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

use MathiasReker\PhpSvgOptimizer\Exception\ExceptionInterface as LibraryException;
use MathiasReker\PhpSvgOptimizer\Rule;
use MathiasReker\PhpSvgOptimizer\SvgOptimizer as Library;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The base optimization, lossless on screen, always applied.
 *
 * The risky rules of the library stay off (one of them removes width and
 * height) and so do the transform rewrites, which produce values some
 * readers misparse. Three rules of ours cover what the library does not: the
 * clip of an editor export that clips nothing, the identifiers nobody
 * references, and the placement an Illustrator export writes on the root.
 */
final readonly class SvgOptimizer
{
    private const int MAX_ROUNDS = 4;

    public function __construct(
        private FigmaClipRemover $clipRemover,
        private UnreferencedIdRemover $idRemover,
        private LoggerInterface $logger = new NullLogger(),
        private RootPlacementRemover $placementRemover = new RootPlacementRemover(),
    ) {
    }

    /**
     * A file the library cannot read comes back as the last valid state of
     * the rounds; any other failure is logged as well, so that a defect of
     * ours is not taken for a file with nothing to optimize.
     */
    public function optimize(string $svg): string
    {
        $current = $svg;

        try {
            $current = $this->withoutClip($svg);

            // The library can empty a container and orphan an identifier
            // (and the other way round): rounds until nothing moves, so
            // that a second pass over the output changes nothing.
            for ($round = 0; $round < self::MAX_ROUNDS; ++$round) {
                // The placement goes in each round: whether `xml:space` stays
                // depends on a text the library may have emptied.
                $next = $this->library()->optimizeString($this->withoutUnreferencedIds($this->withoutRootPlacement($current)))->getContent();

                if ($next === $current) {
                    break;
                }

                $current = $next;
            }
        } catch (LibraryException) {
            return $current;
        } catch (\Throwable $failure) {
            $this->logger->warning('The SVG optimization failed and the file was kept as it stood.', ['failure' => get_debug_type($failure), 'reason' => $failure->getMessage()]);

            return $current;
        }

        return $current;
    }

    private function library(): Library
    {
        return (new Library())
            ->withDefaultRules()
            ->withRules(Rule::RemoveTitles, Rule::RemoveDescriptions)
            ->withoutRules(Rule::MinifyTransforms);
    }

    // Before the library: it turns a rect into a path, and the clip rule
    // only reads a rect. What the clip leaves empty (g, defs) is the
    // library's to remove.
    private function withoutClip(string $svg): string
    {
        $document = SvgDocument::load($svg);

        if (null === $document || !$this->clipRemover->remove($document)) {
            return $svg;
        }

        return SvgDocument::serialize($document);
    }

    private function withoutRootPlacement(string $svg): string
    {
        $document = SvgDocument::load($svg);

        if (null === $document || !$this->placementRemover->remove($document)) {
            return $svg;
        }

        return SvgDocument::serialize($document);
    }

    private function withoutUnreferencedIds(string $svg): string
    {
        $document = SvgDocument::load($svg);

        if (null === $document || !$this->idRemover->remove($document)) {
            return $svg;
        }

        return SvgDocument::serialize($document);
    }
}
