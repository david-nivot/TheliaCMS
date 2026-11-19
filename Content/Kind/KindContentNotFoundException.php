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
 * No content of the kind has this id, or it is in the bin.
 */
final class KindContentNotFoundException extends \RuntimeException
{
    public static function for(string $code, int $id): self
    {
        return new self(\sprintf('No content #%d of the kind "%s".', $id, $code));
    }
}
