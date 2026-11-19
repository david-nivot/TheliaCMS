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
 * A kind that cannot be declared: a code that is not one, or a code another
 * kind already holds. Raised when the registry is built, the first time
 * something asks for a kind, so a module that declares one wrongly fails on
 * the first screen or command that reads the kinds.
 */
final class InvalidContentKindException extends \LogicException
{
    public static function code(string $code, string $kind): self
    {
        return new self(\sprintf('The content kind "%s" (%s) has an invalid code: lowercase letters, digits and "_", 1 to 32 characters.', $code, $kind));
    }

    public static function duplicate(string $code, string $first, string $second): self
    {
        return new self(\sprintf('The content kind "%s" is declared twice: by %s and by %s.', $code, $first, $second));
    }
}
