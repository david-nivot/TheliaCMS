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

/**
 * What rendering the published version again from its document came to.
 */
enum PublishedRendering
{
    /** The rendering changed: it was written, or would be on a dry run. */
    case Rendered;

    /** The rendering is the one stored: nothing is written. */
    case Unchanged;

    /** The new rendering would lose custom code the stored one holds: nothing is written. */
    case KeptForCustomCode;

    /** Nothing is online in this language. */
    case NotOnline;
}
