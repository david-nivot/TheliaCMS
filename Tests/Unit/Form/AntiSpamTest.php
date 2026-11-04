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

namespace TheliaCMS\Tests\Unit\Form;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Form\AntiSpam;

/**
 * The two checks a public form gets before anything else looks at it. Both have
 * to hold against something that is not a browser, which is the only kind of
 * caller they are meant to catch.
 */
final class AntiSpamTest extends TestCase
{
    private const string NOW = '2026-08-07 10:00:00';

    private AntiSpam $antiSpam;

    protected function setUp(): void
    {
        $this->antiSpam = new AntiSpam('a secret nobody else has');
    }

    public function testTheTrapIsUntouchedWhenNobodyFilledItIn(): void
    {
        self::assertTrue($this->antiSpam->trapIsUntouched([]));
        self::assertTrue($this->antiSpam->trapIsUntouched([AntiSpam::TRAP_FIELD => '']));
        self::assertTrue($this->antiSpam->trapIsUntouched([AntiSpam::TRAP_FIELD => '   ']));
    }

    public function testTheTrapCatchesWhoeverFillsEveryFieldIn(): void
    {
        self::assertFalse($this->antiSpam->trapIsUntouched([AntiSpam::TRAP_FIELD => 'http://example.org']));
    }

    public function testAStampIsAcceptedOnceEnoughTimeHasPassed(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        $stamp = $this->antiSpam->stamp('contact', $issued);

        self::assertTrue($this->antiSpam->stampIsPlausible(
            'contact',
            [AntiSpam::STAMP_FIELD => $stamp],
            $issued->modify('+10 seconds'),
        ));
    }

    /**
     * Nobody reads a form, fills it in and sends it in under three seconds.
     */
    public function testAFormSentTooFastIsNotPlausible(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        $stamp = $this->antiSpam->stamp('contact', $issued);

        self::assertFalse($this->antiSpam->stampIsPlausible(
            'contact',
            [AntiSpam::STAMP_FIELD => $stamp],
            $issued->modify('+1 second'),
        ));
    }

    public function testAStampStopsBeingAcceptedAfterAWhile(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        $stamp = $this->antiSpam->stamp('contact', $issued);

        self::assertFalse($this->antiSpam->stampIsPlausible(
            'contact',
            [AntiSpam::STAMP_FIELD => $stamp],
            $issued->modify('+13 hours'),
        ));
    }

    public function testAStampSignedForAnotherFormIsRefused(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        $stamp = $this->antiSpam->stamp('newsletter', $issued);

        self::assertFalse($this->antiSpam->stampIsPlausible(
            'contact',
            [AntiSpam::STAMP_FIELD => $stamp],
            $issued->modify('+10 seconds'),
        ));
    }

    public function testAStampSignedWithAnotherSecretIsRefused(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        $stamp = (new AntiSpam('some other site'))->stamp('contact', $issued);

        self::assertFalse($this->antiSpam->stampIsPlausible(
            'contact',
            [AntiSpam::STAMP_FIELD => $stamp],
            $issued->modify('+10 seconds'),
        ));
    }

    /**
     * Back-dating the stamp is the obvious way around the delay, so the moment
     * is signed together with the form it was issued for.
     */
    public function testABackDatedStampIsRefused(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        $stamp = $this->antiSpam->stamp('contact', $issued);
        [, $signature] = explode('.', $stamp, 2);
        $backDated = ($issued->getTimestamp() - 600).'.'.$signature;

        self::assertFalse($this->antiSpam->stampIsPlausible(
            'contact',
            [AntiSpam::STAMP_FIELD => $backDated],
            $issued->modify('+10 seconds'),
        ));
    }

    /**
     * Each display has a stamp of its own: one stamp carries one message, and
     * two visitors served in the same second must not share it.
     */
    public function testTwoDisplaysInTheSameSecondGetTwoStamps(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);

        self::assertNotSame($this->antiSpam->stamp('contact', $issued), $this->antiSpam->stamp('contact', $issued));
    }

    public function testAStampWithAnotherNonceIsRefused(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        [$moment, , $signature] = explode('.', $this->antiSpam->stamp('contact', $issued), 3);

        self::assertFalse($this->antiSpam->stampIsPlausible(
            'contact',
            [AntiSpam::STAMP_FIELD => $moment.'.'.str_repeat('0', 16).'.'.$signature],
            $issued->modify('+10 seconds'),
        ));
    }

    /**
     * A page open while the site was updated carries a stamp without a nonce.
     */
    public function testAStampIssuedBeforeTheNonceIsStillAccepted(): void
    {
        $issued = new \DateTimeImmutable(self::NOW);
        $legacy = $issued->getTimestamp().'.'.hash_hmac(
            'sha256',
            'contact|'.$issued->getTimestamp(),
            hash_hmac('sha256', 'thelia-cms.form-stamp', 'a secret nobody else has'),
        );

        self::assertTrue($this->antiSpam->stampIsPlausible('contact', [AntiSpam::STAMP_FIELD => $legacy], $issued->modify('+10 seconds')));
        self::assertFalse($this->antiSpam->stampIsPlausible('newsletter', [AntiSpam::STAMP_FIELD => $legacy], $issued->modify('+10 seconds')));
    }

    public function testAMissingOrMalformedStampIsRefused(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        self::assertFalse($this->antiSpam->stampIsPlausible('contact', [], $now));
        self::assertFalse($this->antiSpam->stampIsPlausible('contact', [AntiSpam::STAMP_FIELD => 'nonsense'], $now));
        self::assertFalse($this->antiSpam->stampIsPlausible('contact', [AntiSpam::STAMP_FIELD => '.'], $now));
        self::assertFalse($this->antiSpam->stampIsPlausible('contact', [AntiSpam::STAMP_FIELD => ['an', 'array']], $now));
        self::assertFalse($this->antiSpam->stampIsPlausible('contact', [AntiSpam::STAMP_FIELD => 'abc.def'], $now));
        self::assertFalse($this->antiSpam->stampIsPlausible('contact', [AntiSpam::STAMP_FIELD => '123.NOT-HEX-NONCE!.def'], $now));
        self::assertFalse($this->antiSpam->stampIsPlausible('contact', [AntiSpam::STAMP_FIELD => '123.a.b.c'], $now));
    }
}
