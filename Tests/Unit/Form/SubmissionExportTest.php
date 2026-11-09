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
use TheliaCMS\Form\SubmissionExport;

/**
 * What is handed over when somebody asks for a copy of their answers, or when
 * whoever reads the form wants them in a spreadsheet.
 */
final class SubmissionExportTest extends TestCase
{
    private const array HEADINGS = [
        'id' => 'Reference',
        'created_at' => 'Received on',
        'email' => 'Email address',
        'locale' => 'Language',
    ];

    public function testWritesOneColumnPerQuestion(): void
    {
        $csv = SubmissionExport::toCsv([$this->submission(1, [
            ['name' => 'name', 'label' => 'Your name', 'value' => 'Camille'],
            ['name' => 'message', 'label' => 'Your message', 'value' => 'Hello'],
        ])], self::HEADINGS, 'Yes', 'No');

        $lines = $this->lines($csv);

        self::assertSame('Reference,"Received on","Email address",Language,"Your name","Your message"', $lines[0]);
        self::assertStringContainsString('Camille', $lines[1]);
        self::assertStringContainsString('Hello', $lines[1]);
    }

    /**
     * A question dropped from the form months ago still has answers, and those
     * answers still have to come out of the export.
     */
    public function testKeepsAColumnForAQuestionOnlyOlderAnswersHave(): void
    {
        $csv = SubmissionExport::toCsv([
            $this->submission(1, [['name' => 'name', 'label' => 'Your name', 'value' => 'Camille']]),
            $this->submission(2, [
                ['name' => 'name', 'label' => 'Your name', 'value' => 'Dominique'],
                ['name' => 'fax', 'label' => 'Your fax number', 'value' => '0102030405'],
            ]),
        ], self::HEADINGS, 'Yes', 'No');

        $lines = $this->lines($csv);

        self::assertStringContainsString('Your fax number', $lines[0]);
        // The submission that never had the question gets an empty cell, not a
        // shifted row.
        self::assertStringEndsWith(',Camille,', $lines[1]);
    }

    public function testWritesATickBoxAsAWordRatherThanAsANumber(): void
    {
        $csv = SubmissionExport::toCsv([$this->submission(1, [
            ['name' => 'consent', 'label' => 'I agree', 'value' => true],
            ['name' => 'newsletter', 'label' => 'Newsletter', 'value' => false],
        ])], self::HEADINGS, 'Yes', 'No');

        self::assertStringContainsString(',Yes,No', $this->lines($csv)[1]);
    }

    /**
     * A cell starting with `=` is a formula to a spreadsheet, and a formula in
     * a file somebody was sent runs on their machine when they open it.
     */
    public function testDefusesAnAnswerASpreadsheetWouldRunAsAFormula(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)'] as $hostile) {
            $csv = SubmissionExport::toCsv(
                [$this->submission(1, [['name' => 'name', 'label' => 'Your name', 'value' => $hostile]])],
                self::HEADINGS,
                'Yes',
                'No',
            );

            self::assertStringContainsString("'".$hostile, $csv, $hostile.' should be defused');
        }
    }

    public function testStartsWithTheMarkASpreadsheetNeedsToReadAccents(): void
    {
        $csv = SubmissionExport::toCsv([$this->submission(1, [
            ['name' => 'message', 'label' => 'Votre message', 'value' => 'Déjà reçu'],
        ])], self::HEADINGS, 'Yes', 'No');

        self::assertStringStartsWith("\u{FEFF}", $csv);
        self::assertStringContainsString('Déjà reçu', $csv);
    }

    public function testAnEmptyExportStillCarriesItsHeadings(): void
    {
        $lines = $this->lines(SubmissionExport::toCsv([], self::HEADINGS, 'Yes', 'No'));

        self::assertSame('Reference,"Received on","Email address",Language', $lines[0]);
    }

    public function testJsonKeepsEverythingTheCsvFlattens(): void
    {
        $json = SubmissionExport::toJson([$this->submission(1, [
            ['name' => 'consent', 'label' => 'I agree', 'value' => true, 'granted_at' => '2026-08-07T10:00:00+02:00'],
        ])]);

        $decoded = json_decode($json, true);

        self::assertSame('2026-08-07T10:00:00+02:00', $decoded[0]['answers'][0]['granted_at']);
        self::assertTrue($decoded[0]['answers'][0]['value']);
    }

    public function testTheSeparatorIsTheOneExcelExpectsInTheLanguageOfTheScreen(): void
    {
        self::assertSame(';', SubmissionExport::separatorFor('fr_FR'));
        self::assertSame(';', SubmissionExport::separatorFor('fr_CA'));
        self::assertSame(',', SubmissionExport::separatorFor('en_US'));
        self::assertSame(',', SubmissionExport::separatorFor('es_ES'));

        $lines = $this->lines(SubmissionExport::toCsv([$this->submission(7, [
            ['name' => 'message', 'label' => 'Votre message', 'value' => 'Oui; et 1,5 kg'],
        ])], self::HEADINGS, 'Oui', 'Non', ';'));

        self::assertSame('Reference;"Received on";"Email address";Language;"Votre message"', $lines[0]);
        self::assertSame('7;"2026-08-07 10:00:00";camille@example.org;fr_FR;"Oui; et 1,5 kg"', $lines[1], 'A cell holding the separator is quoted, a comma left alone.');
    }

    public function testTheSubmissionsAreReadTwiceAndNeverHeldTogether(): void
    {
        $readings = 0;
        $read = function () use (&$readings): \Generator {
            ++$readings;

            yield $this->submission(1, [['name' => 'name', 'label' => 'Your name', 'value' => 'Camille']]);
            yield $this->submission(2, [['name' => 'phone', 'label' => 'Phone', 'value' => '0600']]);
        };

        $lines = $this->lines(SubmissionExport::toCsv($read, self::HEADINGS, 'Yes', 'No'));

        self::assertSame(2, $readings, 'Once for the columns, once for the rows.');
        self::assertSame('Reference,"Received on","Email address",Language,"Your name",Phone', $lines[0], 'A question met on a later row still has its column.');
        self::assertSame('2,"2026-08-07 10:00:00",camille@example.org,fr_FR,,0600', $lines[2]);
    }

    public function testJsonWrittenOneSubmissionAtATimeIsTheWholeArrayPrettyPrinted(): void
    {
        $submissions = [
            $this->submission(1, [['name' => 'message', 'label' => 'Message', 'value' => "Line one\nline two / déjà"]]),
            $this->submission(2, [['name' => 'consent', 'label' => 'I agree', 'value' => true]]),
        ];
        $flags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;

        self::assertSame(json_encode($submissions, $flags), SubmissionExport::toJson((static fn (): \Generator => yield from $submissions)()));
        self::assertSame(json_encode([$submissions[0]], $flags), SubmissionExport::toJson([$submissions[0]]));
        self::assertSame('[]', SubmissionExport::toJson([]));
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return array{id: int, locale: string, email: ?string, created_at: string, answers: list<array<string, mixed>>}
     */
    private function submission(int $id, array $answers): array
    {
        return [
            'id' => $id,
            'locale' => 'fr_FR',
            'email' => 'camille@example.org',
            'created_at' => '2026-08-07 10:00:00',
            'answers' => $answers,
        ];
    }

    /**
     * @return list<string>
     */
    private function lines(string $csv): array
    {
        return array_values(array_filter(explode("\n", str_replace(["\u{FEFF}", "\r"], '', $csv))));
    }
}
