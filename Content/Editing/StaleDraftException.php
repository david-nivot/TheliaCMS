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
 * A write of a draft made on a version that moved since it was read: someone
 * else saved, generated, restored or imported in between. Nothing was written;
 * it says who wrote last, and when, for the screen to tell the editor.
 */
final class StaleDraftException extends \RuntimeException
{
    public function __construct(
        public readonly int $readVersion,
        public readonly int $currentVersion,
        public readonly ?int $updatedBy,
        public readonly ?\DateTimeImmutable $updatedAt,
    ) {
        parent::__construct(\sprintf('The draft is at version %d, the write was made on version %d.', $currentVersion, $readVersion));
    }
}
