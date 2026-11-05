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

namespace TheliaCMS\Content\Probe;

use TheliaCMS\Generation\GenerationLog;

final class UsesGeneration
{
    public function imported(): string
    {
        return GenerationLog::STATUS_DONE;
    }

    public function inFull(): object
    {
        return new \TheliaCMS\Generation\GenerationException();
    }

    public function sameWordElsewhere(): string
    {
        return \TheliaCMS\GenerationOther\Thing::class;
    }
}
