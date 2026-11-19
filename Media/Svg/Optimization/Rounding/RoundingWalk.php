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

namespace TheliaCMS\Media\Svg\Optimization\Rounding;

use TheliaCMS\Media\Svg\Optimization\Path\PathSegment;

/**
 * Where the rounding of a path stands: the segments written so far, and
 * what the next segment needs to know of those before it. The state before
 * a segment can be saved, and the rounding taken up again from there.
 *
 * @phpstan-type Point array{float, float}
 * @phpstan-type Mirrored array{after: string, drawn: Point, written: Point}
 */
final class RoundingWalk
{
    /** @var list<PathSegment> */
    public array $written = [];

    /** @var Mirrored|null the control point a smooth curve mirrors, as drawn and as written */
    public ?array $mirrored = null;

    /** @var Point where the next segment starts, as drawn */
    public array $origin = [0.0, 0.0];

    /** @var Point where the next segment starts, as written */
    public array $current = [0.0, 0.0];

    /** @var Point where the subpath starts, as written */
    public array $subpathStart = [0.0, 0.0];

    // The moveto that starts the subpath.
    public int $subpathPosition = 0;

    // The segment that ends where the next one starts.
    public int $before = 0;

    /** @var array<int, array{int, Mirrored|null, Point, Point, Point, int, int}> */
    private array $saved = [];

    /**
     * Saves the state before a segment wherever an arc may need to resume
     * from it: before a moveto, and before the segment an arc follows. An
     * arc resumes no further back than the moveto of its subpath, so a
     * moveto lets go of the states saved before it.
     */
    public function saveBefore(int $position, string $command, string $next): void
    {
        if ('M' === $command) {
            $this->saved = [];
        }

        if ('M' === $command || 'A' === $next) {
            $this->saved[$position] = [\count($this->written), $this->mirrored, $this->origin, $this->current, $this->subpathStart, $this->subpathPosition, $this->before];
        }
    }

    /**
     * Takes up again the state saved before a segment; false when none was.
     */
    public function resumeAt(int $position): bool
    {
        if (!isset($this->saved[$position])) {
            return false;
        }

        [$count, $this->mirrored, $this->origin, $this->current, $this->subpathStart, $this->subpathPosition, $this->before] = $this->saved[$position];

        while (\count($this->written) > $count) {
            array_pop($this->written);
        }

        return true;
    }
}
