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

namespace TheliaCMS\Media\Svg\Optimization\Rounding;

use TheliaCMS\Media\Svg\Optimization\Path\ExactDecimal;

/**
 * A value rounded to a number of decimals, written in its shortest exact
 * form.
 */
final readonly class DecimalRounding
{
    // A value kept as it was is written with this many decimals at least.
    public const int KEPT_DECIMALS = 12;

    public function rounded(float $value, int $decimals): string
    {
        $text = number_format(round($value, $decimals), max(0, $decimals), '.', '');

        return ExactDecimal::fromText($text)?->toText() ?? $text;
    }
}
