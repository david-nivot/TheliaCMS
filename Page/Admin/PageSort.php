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

namespace TheliaCMS\Page\Admin;

/**
 * The orders the page listing can be sorted in. No sort by state: the status
 * boxes already keep one state, and five states in a row teach nothing.
 */
enum PageSort: string
{
    case Title = 'title';
    case Modified = 'modified';
    case Created = 'created';

    /**
     * The direction a sort starts in: a title reads from A, a date from the
     * latest, which is the one an editor comes looking for.
     */
    public function startsDescending(): bool
    {
        return self::Title !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Title => 'Title',
            self::Modified => 'Last modified',
            self::Created => 'Creation date',
        };
    }

    /**
     * What the chip says, the direction included.
     */
    public function chipLabel(bool $descending): string
    {
        return match ($this) {
            self::Title => $descending ? 'Sorted by title, Z to A' : 'Sorted by title, A to Z',
            self::Modified => $descending ? 'Sorted by last modified, latest first' : 'Sorted by last modified, oldest first',
            self::Created => $descending ? 'Sorted by creation date, latest first' : 'Sorted by creation date, oldest first',
        };
    }
}
