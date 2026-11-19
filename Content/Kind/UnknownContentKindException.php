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
 * The code names no kind a module declares: a typo, or the module that
 * declared the kind is not active. Also raised for a hidden content whose
 * stored kind is in that case.
 */
final class UnknownContentKindException extends \InvalidArgumentException
{
    public static function code(string $code): self
    {
        return new self(\sprintf('No content kind "%s" is declared.', $code));
    }

    public static function forPage(int $pageId): self
    {
        return new self(\sprintf('CMS page #%d is a hidden content whose kind no active module declares.', $pageId));
    }
}
