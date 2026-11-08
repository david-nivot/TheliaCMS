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
 * A step as the buttons of the screen name it: what was done, to which
 * section, by whom and when.
 */
final readonly class StepView
{
    public function __construct(
        public string $action,
        public ?string $section,
        public ?string $author,
        public ?\DateTimeInterface $date,
    ) {
    }
}
