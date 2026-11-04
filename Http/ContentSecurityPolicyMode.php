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

namespace TheliaCMS\Http;

/**
 * Whether the pages of the module send a Content-Security-Policy, and whether
 * the browser applies it or only reports what it would have blocked.
 */
enum ContentSecurityPolicyMode: string
{
    case Off = 'off';
    case ReportOnly = 'report-only';
    case Enforce = 'enforce';

    /**
     * Off for a value never saved or not understood: a policy that blocks is
     * never turned on by accident.
     */
    public static function fromStorage(mixed $value): self
    {
        return self::tryFrom(strtolower(trim(\is_scalar($value) ? (string) $value : ''))) ?? self::Off;
    }

    public function header(): ?string
    {
        return match ($this) {
            self::Off => null,
            self::ReportOnly => 'Content-Security-Policy-Report-Only',
            self::Enforce => 'Content-Security-Policy',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::ReportOnly => 'Report only: nothing is blocked, what would be is reported',
            self::Enforce => 'Enforce: the browser blocks what the policy does not allow',
        };
    }
}
