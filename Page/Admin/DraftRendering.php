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
 * What rendering a draft again from its document came to.
 */
enum DraftRendering
{
    /** The rendering changed and was written. */
    case Rendered;

    /** The rendering is the one stored: nothing was written. */
    case Unchanged;

    /** The new rendering would lose custom code the stored one holds: nothing was written. */
    case KeptForCustomCode;

    /** The language holds no draft document. */
    case NoDraft;

    /** The draft was saved while it was being rendered: the save wrote its own rendering, nothing was written. */
    case ChangedMeanwhile;
}
