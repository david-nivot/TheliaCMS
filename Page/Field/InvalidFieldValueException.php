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

namespace TheliaCMS\Page\Field;

use TheliaCMS\Locale\TranslatableText;

/**
 * A value its field does not take, with the reason an editor reads in the
 * language of the back office.
 */
final class InvalidFieldValueException extends \InvalidArgumentException
{
    public function __construct(
        public readonly TranslatableText $text,
    ) {
        parent::__construct((string) $text);
    }

    public static function because(string $template, string|int|float ...$values): self
    {
        return new self(TranslatableText::of($template, ...$values));
    }
}
