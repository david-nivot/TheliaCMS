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

use Thelia\Model\ConfigQuery;

/**
 * Caps what a public form will do for whoever posts it: how many messages one
 * sender may send to one form, how many copies one address may receive, and
 * how many messages one stamp may carry.
 *
 * The core has a rate limit, but it lives in `FirewallForm`, which a form of
 * this module is not: it is tied to `BaseForm`, it buckets by PHP class name,
 * and it does nothing at all outside the production environment. Its
 * `form_firewall_*` settings are still the ones read here, so a site keeps one
 * place to set them. The counts are not in its table: `form_firewall` has no
 * unique key, so a burst on a fresh sender inserted one row per request, and
 * all of them went through.
 */
final readonly class SubmissionRateLimiter
{
    /** Copies one address may receive in a day, whoever posts the form. */
    public const int RECEIPTS_PER_ADDRESS = 3;

    public const int RECEIPT_WINDOW_SECONDS = 86400;

    private const int DEFAULT_ATTEMPTS = 6;

    private const int DEFAULT_MINUTES = 60;

    public function __construct(
        private Throttle $throttle,
    ) {
    }

    /**
     * Counts one message from this sender to this form and says whether it may
     * go through.
     */
    public function allows(string $formCode, ?string $ipAddress): bool
    {
        if (!$this->isActive()) {
            return true;
        }

        return $this->throttle->hit(ThrottleScope::Sender, $formCode.'|'.$ipAddress, $this->attempts(), $this->minutes() * 60);
    }

    /**
     * Counts one copy to this address and says whether it may be sent.
     *
     * The address of the copy is typed by whoever posts the form, so without
     * this a script spread over many senders makes the shop write, in its own
     * name, to an address it chose, as often as it likes. Not tied to the
     * firewall setting: it costs a legitimate visitor nothing.
     */
    public function allowsReceiptTo(string $email): bool
    {
        return $this->throttle->hit(ThrottleScope::Receipt, mb_strtolower(trim($email)), self::RECEIPTS_PER_ADDRESS, self::RECEIPT_WINDOW_SECONDS);
    }

    /**
     * Spends the stamp a message came with: true the first time only.
     *
     * The stamp proves the form was displayed, not that it was displayed for
     * this message: without this, one stamp served any number of posts for as
     * long as it stayed valid.
     */
    public function spendsStamp(string $formCode, string $stamp): bool
    {
        return $this->throttle->hit(ThrottleScope::Stamp, $formCode.'|'.$stamp, 1, AntiSpam::MAXIMUM_SECONDS);
    }

    /**
     * Minutes a sender who went over the limit waits at most.
     */
    public function waitingMinutes(): int
    {
        return $this->minutes();
    }

    private function isActive(): bool
    {
        return (bool) ConfigQuery::read('form_firewall_active', '1');
    }

    private function attempts(): int
    {
        return max(1, (int) ConfigQuery::read('form_firewall_attempts', (string) self::DEFAULT_ATTEMPTS));
    }

    private function minutes(): int
    {
        return max(1, (int) ConfigQuery::read('form_firewall_time_to_wait', (string) self::DEFAULT_MINUTES));
    }
}
