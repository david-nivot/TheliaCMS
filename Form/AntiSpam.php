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

namespace TheliaCMS\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The two checks a public form gets before anything else looks at it: a field
 * no human ever fills in, and the time it took to fill the rest.
 *
 * Neither asks anything of the visitor. A captcha does — it sends personal data
 * to a third party, it is a barrier for anyone using assistive technology, and
 * it stops far less than the pair below on the volume a showcase site sees.
 *
 * The moment the form was served is signed, so it cannot simply be back-dated
 * in the posted body, and it expires: a page kept open for a day is not a form
 * worth accepting, and a stolen token is worth nothing tomorrow.
 *
 * Each display gets a stamp of its own, the moment plus a random nonce, so that
 * a stamp can be spent by the message it carried (SubmissionRateLimiter) without
 * two visitors served in the same second sharing one. That is also why a page
 * showing a form is never kept by a shared cache (PageCacheListener).
 */
final readonly class AntiSpam
{
    /** Name of the field only a robot fills in. */
    public const string TRAP_FIELD = 'website';

    /** Name of the field carrying the signed moment the form was served. */
    public const string STAMP_FIELD = 'form_stamp';

    /** Nobody reads a form, fills it in and sends it in under three seconds. */
    public const int MINIMUM_SECONDS = 3;

    /** After this, the form was served long enough ago to be served again. */
    public const int MAXIMUM_SECONDS = 12 * 3600;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $applicationSecret,
    ) {
    }

    /**
     * Value to put in the hidden stamp field of a form being rendered.
     */
    public function stamp(string $formCode, ?\DateTimeImmutable $now = null): string
    {
        $issuedAt = ($now ?? new \DateTimeImmutable())->getTimestamp();
        $nonce = bin2hex(random_bytes(8));

        return $issuedAt.'.'.$nonce.'.'.$this->sign($formCode, $issuedAt, $nonce);
    }

    /**
     * Whether the trap field came back empty.
     *
     * @param array<string, mixed> $input
     */
    public function trapIsUntouched(array $input): bool
    {
        $trap = $input[self::TRAP_FIELD] ?? '';

        return !\is_array($trap) && '' === trim((string) $trap);
    }

    /**
     * Whether the stamp is one this site issued, for this form, long enough ago
     * to have been filled in by hand and recently enough to still be current.
     *
     * A stamp of the form `moment.signature`, without a nonce, is the one pages
     * carried before 2.10.0: still accepted while it is current, so a visitor
     * who had the page open during the update does not lose their message.
     *
     * @param array<string, mixed> $input
     */
    public function stampIsPlausible(string $formCode, array $input, ?\DateTimeImmutable $now = null): bool
    {
        $stamp = self::stampOf($input);

        if (null === $stamp) {
            return false;
        }

        $parts = explode('.', $stamp, 3);
        [$issuedAt, $nonce, $signature] = 3 === \count($parts) ? $parts : [$parts[0], '', $parts[1]];

        if (1 !== preg_match('/^\d{1,12}$/', $issuedAt) || 1 !== preg_match('/^([0-9a-f]{16})?$/', $nonce)) {
            return false;
        }

        if (!hash_equals($this->sign($formCode, (int) $issuedAt, $nonce), $signature)) {
            return false;
        }

        $age = ($now ?? new \DateTimeImmutable())->getTimestamp() - (int) $issuedAt;

        return $age >= self::MINIMUM_SECONDS && $age <= self::MAXIMUM_SECONDS;
    }

    /**
     * The stamp the input carries, or null when it carries none that could be one.
     *
     * @param array<string, mixed> $input
     */
    public static function stampOf(array $input): ?string
    {
        $stamp = $input[self::STAMP_FIELD] ?? '';

        if (\is_array($stamp) || !str_contains((string) $stamp, '.')) {
            return null;
        }

        return (string) $stamp;
    }

    private function sign(string $formCode, int $issuedAt, string $nonce): string
    {
        return hash_hmac(
            'sha256',
            '' === $nonce ? $formCode.'|'.$issuedAt : $formCode.'|'.$issuedAt.'|'.$nonce,
            hash_hmac('sha256', 'thelia-cms.form-stamp', $this->applicationSecret),
        );
    }
}
