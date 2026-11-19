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

namespace TheliaCMS\Media\Svg\Optimization\Path;

/**
 * A number of a path held exactly, `units` divided by ten to the power
 * `scale`: adding and subtracting never lose a digit, so a point moved from
 * one notation to the other keeps the value it had. Null wherever the
 * integers would overflow, or a number carries more digits than a float
 * keeps.
 */
final readonly class ExactDecimal
{
    private const string PARTS = '/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/';

    private const int MAXIMUM_DIGITS = 15;

    private const int MAXIMUM_EXPONENT = 30;

    private function __construct(
        private int $units,
        private int $scale,
    ) {
    }

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public static function fromText(string $text): ?self
    {
        if (1 !== preg_match(self::PARTS, $text, $parts) || ('' === $parts[2] && '' === ($parts[3] ?? ''))) {
            return null;
        }

        $fraction = $parts[3] ?? '';
        $digits = ltrim($parts[2].$fraction, '0');
        $exponent = (int) ($parts[4] ?? '0');

        if (\strlen($digits) > self::MAXIMUM_DIGITS || abs($exponent) > self::MAXIMUM_EXPONENT) {
            return null;
        }

        $units = (int) $digits;
        $scale = \strlen($fraction) - $exponent;

        if ($scale < 0) {
            $units = self::shifted($units, -$scale);
            $scale = 0;
        }

        return null === $units ? null : self::normalized('-' === $parts[1] ? -$units : $units, $scale);
    }

    public function plus(self $other): ?self
    {
        $scale = max($this->scale, $other->scale);
        $left = self::shifted($this->units, $scale - $this->scale);
        $right = self::shifted($other->units, $scale - $other->scale);

        if (null === $left || null === $right) {
            return null;
        }

        if (($right > 0 && $left > \PHP_INT_MAX - $right) || ($right < 0 && $left < -\PHP_INT_MAX - $right)) {
            return null;
        }

        return self::normalized($left + $right, $scale);
    }

    public function minus(self $other): ?self
    {
        return $this->plus(new self(-$other->units, $other->scale));
    }

    public function equals(self $other): bool
    {
        return $this->units === $other->units && $this->scale === $other->scale;
    }

    /**
     * The shortest text of the value: no sign for a positive number, no
     * zero before the decimal point nor after the last digit, and an
     * exponent where it saves characters.
     */
    public function toText(): string
    {
        if (0 === $this->units) {
            return '0';
        }

        $sign = $this->units < 0 ? '-' : '';
        $digits = (string) abs($this->units);
        $plain = $this->plain($digits);
        $significant = rtrim($digits, '0');
        $exponent = \strlen($digits) - \strlen($significant) - $this->scale;
        $scientific = 0 === $exponent ? $plain : $significant.'e'.$exponent;

        return $sign.(\strlen($scientific) < \strlen($plain) ? $scientific : $plain);
    }

    private function plain(string $digits): string
    {
        if (0 === $this->scale) {
            return $digits;
        }

        if (\strlen($digits) <= $this->scale) {
            return '.'.str_pad($digits, $this->scale, '0', \STR_PAD_LEFT);
        }

        return substr($digits, 0, -$this->scale).'.'.substr($digits, -$this->scale);
    }

    private static function normalized(int $units, int $scale): self
    {
        while ($scale > 0 && 0 === $units % 10) {
            $units = intdiv($units, 10);
            --$scale;
        }

        return new self($units, 0 === $units ? 0 : $scale);
    }

    private static function shifted(?int $units, int $places): ?int
    {
        for ($place = 0; null !== $units && $place < $places; ++$place) {
            $units = abs($units) > intdiv(\PHP_INT_MAX, 10) ? null : $units * 10;
        }

        return $units;
    }
}
