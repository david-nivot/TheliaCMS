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

namespace TheliaCMS\Page;

use TheliaCMS\Locale\TranslatableText;

/**
 * The slug typed for a page would shadow a path the site reserves (`admin`,
 * `api`...).
 */
final class ReservedSlugException extends \InvalidArgumentException
{
    public function __construct(
        public readonly TranslatableText $text,
    ) {
        parent::__construct((string) $text);
    }
}
