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

namespace TheliaCMS\Content\Kind;

/**
 * The kind says its contents may not go to the bin. The message is the
 * translated refusal the kind gave, ready to show.
 */
final class KindContentDeletionRefusedException extends \RuntimeException
{
    public function __construct(string $refusal, public readonly string $kind, public readonly int $contentId)
    {
        parent::__construct($refusal);
    }
}
