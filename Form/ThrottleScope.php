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

namespace TheliaCMS\Form;

/**
 * What a counter of the public forms counts. Part of the key of the row, so
 * the same hash never counts for two things.
 */
enum ThrottleScope: string
{
    /** Messages one sender posts to one form. */
    case Sender = 'sender';

    /** Copies of a message sent to one address, whoever posted them. */
    case Receipt = 'receipt';

    /** Messages one stamp carried: one, at most. */
    case Stamp = 'stamp';
}
