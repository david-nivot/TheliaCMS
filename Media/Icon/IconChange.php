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

namespace TheliaCMS\Media\Icon;

/**
 * What became of a change to the name of an icon: the sentence to tell the
 * editor, in the language of the screen, and whether the change was made.
 */
final readonly class IconChange
{
    public function __construct(
        public string $message,
        public bool $made,
    ) {
    }
}
