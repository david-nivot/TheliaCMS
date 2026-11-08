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

namespace TheliaCMS\Content\Editing;

/** What Undo or Redo did. */
enum StepOutcome
{
    case Done;

    /** No step to play. */
    case Nothing;

    /** The draft is no longer the one the step left: nothing was written. */
    case DraftChanged;
}
