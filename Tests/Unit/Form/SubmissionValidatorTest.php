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
use Symfony\Component\Translation\IdentityTranslator;
use TheliaCMS\Form\Field;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Form\SubmissionValidator;

/**
 * A public form is posted by whatever sends the request, so `required`,
 * `type="email"` and the options of a drop-down are decoration until the server
 * has said the same thing.
 */
final class SubmissionValidatorTest extends TestCase
{
    private SubmissionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SubmissionValidator(new IdentityTranslator());
    }

    public function testAcceptsAFilledInForm(): void
    {
        $submission = $this->validator->validate(
            [$this->field('name'), $this->field('email', FieldType::Email)],
            ['name' => 'Camille', 'email' => 'camille@example.org'],
        );

        self::assertTrue($submission->isValid());
        self::assertSame('Camille', $submission->answers[0]->value);
        self::assertSame('camille@example.org', $submission->email());
    }

    public function testRefusesARequiredFieldLeftEmpty(): void
    {
        $submission = $this->validator->validate([$this->field('name', required: true)], ['name' => '   ']);

        self::assertFalse($submission->isValid());
        self::assertArrayHasKey('name', $submission->errors);
    }

    public function testAnOptionalFieldMayBeLeftEmpty(): void
    {
        $submission = $this->validator->validate([$this->field('company')], []);

        self::assertTrue($submission->isValid());
        self::assertSame('', $submission->answers[0]->value);
    }

    /**
     * Anything the form did not declare came from somewhere else.
     */
    public function testDropsWhatTheFormDoesNotDeclare(): void
    {
        $submission = $this->validator->validate(
            [$this->field('name')],
            ['name' => 'Camille', 'role' => 'administrator'],
        );

        self::assertCount(1, $submission->answers);
        self::assertSame('name', $submission->answers[0]->name);
    }

    public function testDropsAnAnswerSentAsAnArray(): void
    {
        $submission = $this->validator->validate([$this->field('name')], ['name' => ['a', 'b']]);

        self::assertTrue($submission->isValid());
        self::assertSame('', $submission->answers[0]->value);
    }

    public function testRefusesSomethingThatIsNotAnEmailAddress(): void
    {
        $submission = $this->validator->validate([$this->field('email', FieldType::Email)], ['email' => 'camille(at)example.org']);

        self::assertFalse($submission->isValid());
    }

    public function testAcceptsAPhoneNumberHoweverItIsWritten(): void
    {
        foreach (['+33 4 44 05 31 02', '04.44.05.31.02', '(0)444053102', '04 44 05 31 02'] as $written) {
            $submission = $this->validator->validate([$this->field('phone', FieldType::Phone)], ['phone' => $written]);

            self::assertTrue($submission->isValid(), $written.' should be accepted');
        }
    }

    public function testRefusesAPhoneNumberWithLettersOrTooFewDigits(): void
    {
        foreach (['call me', '12345', '+33 4 44 05 31 02 99 88 77 66 55 44'] as $written) {
            $submission = $this->validator->validate([$this->field('phone', FieldType::Phone)], ['phone' => $written]);

            self::assertFalse($submission->isValid(), $written.' should be refused');
        }
    }

    public function testAcceptsADateAndRefusesADayThatDoesNotExist(): void
    {
        self::assertTrue($this->validator->validate([$this->field('day', FieldType::Date)], ['day' => '2026-08-07'])->isValid());
        self::assertFalse($this->validator->validate([$this->field('day', FieldType::Date)], ['day' => '2026-02-31'])->isValid());
        self::assertFalse($this->validator->validate([$this->field('day', FieldType::Date)], ['day' => '07/08/2026'])->isValid());
    }

    public function testOnlyAcceptsAnAnswerThatWasOffered(): void
    {
        $field = new Field('subject', FieldType::Select, 'Subject', choices: ['A quote', 'Something else']);

        self::assertTrue($this->validator->validate([$field], ['subject' => 'A quote'])->isValid());
        self::assertFalse($this->validator->validate([$field], ['subject' => 'Anything I like'])->isValid());
    }

    /**
     * The choices are written per language, so an answer is checked against the
     * list of the language the form was shown in.
     */
    public function testChecksTheAnswerAgainstTheChoicesOfTheLanguageItWasShownIn(): void
    {
        $french = new Field('subject', FieldType::Select, 'Objet', choices: ['Un devis']);

        self::assertFalse($this->validator->validate([$french], ['subject' => 'A quote'])->isValid());
    }

    public function testAnUntickedBoxIsAnAnswerRatherThanAMissingOne(): void
    {
        $submission = $this->validator->validate([$this->field('newsletter', FieldType::Checkbox)], []);

        self::assertTrue($submission->isValid());
        self::assertFalse($submission->answers[0]->value);
    }

    public function testRefusesAConsentThatWasNotGiven(): void
    {
        $submission = $this->validator->validate([$this->field('consent', FieldType::Consent, required: true)], []);

        self::assertFalse($submission->isValid());
        self::assertArrayHasKey('consent', $submission->errors);
    }

    public function testKnowsWhetherEveryAgreementWasGiven(): void
    {
        $consent = $this->field('consent', FieldType::Consent);

        self::assertTrue($this->validator->validate([$consent], ['consent' => '1'])->consentGranted());
        self::assertFalse($this->validator->validate([$consent], [])->consentGranted());
    }

    /**
     * A form asking for no agreement has nothing to withhold.
     */
    public function testAFormWithNoConsentFieldCountsAsGranted(): void
    {
        self::assertTrue($this->validator->validate([$this->field('name')], ['name' => 'Camille'])->consentGranted());
    }

    public function testCutsAnAnswerThatIsTooLongToStore(): void
    {
        $submission = $this->validator->validate([$this->field('name')], ['name' => str_repeat('a', 300)]);

        self::assertFalse($submission->isValid());
    }

    public function testGivesBackWhatWasTypedSoTheFormCanBeFilledBackIn(): void
    {
        $submission = $this->validator->validate(
            [$this->field('name', required: true), $this->field('email', FieldType::Email)],
            ['name' => '', 'email' => ' camille@example.org '],
        );

        self::assertSame('', $submission->entered['name']);
        self::assertSame('camille@example.org', $submission->entered['email']);
    }

    public function testACountryFieldTakesOneOfTheCountriesOffered(): void
    {
        $country = new Field('country', FieldType::Country, 'Country', required: true, choices: ['France', 'Italie']);

        $submission = $this->validator->validate([$country], ['country' => 'Italie']);

        self::assertTrue($submission->isValid());
        self::assertSame('Italie', $submission->answers[0]->value);
    }

    public function testACountryFieldRefusesACountryNotOffered(): void
    {
        $country = new Field('country', FieldType::Country, 'Country', choices: ['France', 'Italie']);

        $submission = $this->validator->validate([$country], ['country' => 'Atlantide']);

        self::assertFalse($submission->isValid());
        self::assertArrayHasKey('country', $submission->errors);
    }

    private function field(string $name, FieldType $type = FieldType::Text, bool $required = false): Field
    {
        return new Field($name, $type, ucfirst($name), required: $required);
    }
}
