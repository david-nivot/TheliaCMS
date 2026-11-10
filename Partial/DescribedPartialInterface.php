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

namespace TheliaCMS\Partial;

/**
 * Optional companion of {@see PartialDefinitionInterface}: a partial that
 * implements it tells the editor what it does, in its own words.
 *
 * Without it the editor shows a generic sentence built from the label, which
 * says that the block is live content but not what it is for.
 */
interface DescribedPartialInterface
{
    /**
     * What the block shows to a visitor and what the author sets, in one or
     * two sentences, translated. Shown in the section picker and on the
     * components screen.
     */
    public function description(): string;
}
