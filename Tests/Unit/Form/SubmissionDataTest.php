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
use TheliaCMS\Form\Answer;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Form\SubmissionData;
use TheliaCMS\Form\VisitorFingerprint;

/**
 * What is stored of a submission, and what is not.
 */
final class SubmissionDataTest extends TestCase
{
    public function testKeepsTheQuestionAlongWithTheAnswer(): void
    {
        $json = SubmissionData::encode(
            [new Answer('name', FieldType::Text, 'Your name', 'Camille')],
            new \DateTimeImmutable('2026-08-07 10:00:00'),
        );

        self::assertSame(
            [['name' => 'name', 'type' => 'text', 'label' => 'Your name', 'value' => 'Camille']],
            SubmissionData::decode($json),
        );
    }

    /**
     * An agreement nobody can show the wording and the date of is not an
     * agreement anyone can rely on.
     */
    public function testStampsAConsentWithTheMomentItWasGiven(): void
    {
        $json = SubmissionData::encode(
            [new Answer('consent', FieldType::Consent, 'I agree to be contacted.', true)],
            new \DateTimeImmutable('2026-08-07 10:00:00+02:00'),
        );

        $stored = SubmissionData::decode($json)[0];

        self::assertSame('I agree to be contacted.', $stored['label']);
        self::assertTrue($stored['value']);
        self::assertSame('2026-08-07T10:00:00+02:00', $stored['granted_at']);
    }

    public function testOnlyAConsentCarriesADate(): void
    {
        $json = SubmissionData::encode(
            [new Answer('newsletter', FieldType::Checkbox, 'Send me the newsletter', true)],
            new \DateTimeImmutable('2026-08-07 10:00:00'),
        );

        self::assertArrayNotHasKey('granted_at', SubmissionData::decode($json)[0]);
    }

    public function testKeepsAccentsReadableInStorage(): void
    {
        $json = SubmissionData::encode(
            [new Answer('message', FieldType::Textarea, 'Votre message', 'Déjà reçu')],
            new \DateTimeImmutable('2026-08-07 10:00:00'),
        );

        self::assertStringContainsString('Déjà reçu', $json);
    }

    public function testReadsNothingBackFromSomethingThatIsNotAStoredSubmission(): void
    {
        self::assertSame([], SubmissionData::decode(null));
        self::assertSame([], SubmissionData::decode('not json'));
        self::assertSame([], SubmissionData::decode('{"answers": 3}'));
    }

    public function testTellsTheSameVisitorApartWithoutStoringTheirAddress(): void
    {
        $fingerprints = new VisitorFingerprint('a secret nobody else has');

        self::assertSame($fingerprints->of('203.0.113.7'), $fingerprints->of('203.0.113.7'));
        self::assertNotSame($fingerprints->of('203.0.113.7'), $fingerprints->of('203.0.113.8'));
        self::assertStringNotContainsString('203.0.113', $fingerprints->of('203.0.113.7'));
    }
}
