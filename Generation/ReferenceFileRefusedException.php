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

namespace TheliaCMS\Generation;

/**
 * A file joined to a brief was not taken; the refusal says why, in words
 * the screen can say.
 */
final class ReferenceFileRefusedException extends \InvalidArgumentException
{
    public function __construct(public readonly ReferenceFileRefusal $refusal)
    {
        parent::__construct($refusal->message());
    }
}
