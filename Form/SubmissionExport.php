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

/**
 * Writes submissions out, so answering someone who asks for a copy of what the
 * site holds about them is a download rather than an afternoon.
 *
 * Two formats on purpose: the spreadsheet for whoever has to read the answers,
 * and JSON for whoever has to hand them over — a CSV flattens a submission into
 * the columns of a form as it is today, and loses the questions that were
 * removed since.
 */
final readonly class SubmissionExport
{
    /** What Excel expects where a comma is the decimal mark, French among them. */
    public const string SEMICOLON = ';';
    public const string COMMA = ',';

    /**
     * The separator for whoever reads the export: Excel splits a CSV on the
     * list separator of its regional settings, a semicolon in French, and
     * otherwise opens every row as one cell.
     */
    public static function separatorFor(string $locale): string
    {
        return 'fr' === strtolower(substr($locale, 0, 2)) ? self::SEMICOLON : self::COMMA;
    }

    /**
     * @param iterable<array{id: int, locale: string, email: ?string, created_at: string, answers: list<array<string, mixed>>}> $submissions
     */
    public static function toJson(iterable $submissions): string
    {
        return self::written(static function ($handle) use ($submissions): void {
            self::writeJson($handle, $submissions);
        });
    }

    /**
     * The same JSON as a whole array pretty printed, written one submission at
     * a time.
     *
     * @param resource                                                                                                          $handle
     * @param iterable<array{id: int, locale: string, email: ?string, created_at: string, answers: list<array<string, mixed>>}> $submissions
     */
    public static function writeJson($handle, iterable $submissions): void
    {
        $first = true;

        foreach ($submissions as $submission) {
            $json = json_encode($submission, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            fwrite($handle, ($first ? "[\n" : ",\n").'    '.str_replace("\n", "\n    ", $json));
            $first = false;
        }

        fwrite($handle, $first ? '[]' : "\n]");
    }

    /**
     * @param iterable<array{id: int, locale: string, email: ?string, created_at: string, answers: list<array<string, mixed>>}>|\Closure(): iterable<array{id: int, locale: string, email: ?string, created_at: string, answers: list<array<string, mixed>>}> $submissions
     * @param array<string, string>                                                                                                                                                                                                                           $headings    the first four column titles, translated
     */
    public static function toCsv(iterable|\Closure $submissions, array $headings, string $yes, string $no, string $separator = self::COMMA): string
    {
        $read = $submissions instanceof \Closure ? $submissions : static fn (): iterable => $submissions;

        return self::written(static function ($handle) use ($read, $headings, $yes, $no, $separator): void {
            self::writeCsv($handle, $read, $headings, $yes, $no, $separator);
        });
    }

    /**
     * The columns are the questions actually answered, in the order they were
     * first met — a question dropped from the form months ago still has its
     * column, because the answers to it are still there. Knowing them takes a
     * first reading of the submissions, the rows a second one: the submissions
     * come from a function called twice, so neither reading holds them all.
     *
     * @param resource                                                                                                                      $handle
     * @param \Closure(): iterable<array{id: int, locale: string, email: ?string, created_at: string, answers: list<array<string, mixed>>}> $submissions
     * @param array<string, string>                                                                                                         $headings    the first four column titles, translated
     */
    public static function writeCsv($handle, \Closure $submissions, array $headings, string $yes, string $no, string $separator = self::COMMA): void
    {
        $questions = [];

        foreach ($submissions() as $submission) {
            foreach ($submission['answers'] as $answer) {
                $questions[(string) $answer['name']] ??= (string) ($answer['label'] ?? $answer['name']);
            }
        }

        // A spreadsheet opening a UTF-8 file without this shows Déjà as DÃ©jÃ .
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, self::defused(array_merge(array_values($headings), array_values($questions))), $separator, '"', '\\');

        foreach ($submissions() as $submission) {
            $byName = [];

            foreach ($submission['answers'] as $answer) {
                $value = $answer['value'] ?? '';
                $byName[(string) $answer['name']] = \is_bool($value) ? ($value ? $yes : $no) : (string) $value;
            }

            $row = [
                (string) $submission['id'],
                $submission['created_at'],
                (string) ($submission['email'] ?? ''),
                $submission['locale'],
            ];

            foreach (array_keys($questions) as $name) {
                $row[] = $byName[$name] ?? '';
            }

            fputcsv($handle, self::defused($row), $separator, '"', '\\');
        }
    }

    /**
     * @param \Closure(resource): void $write
     */
    private static function written(\Closure $write): string
    {
        $handle = fopen('php://temp', 'r+');

        if (false === $handle) {
            return '';
        }

        $write($handle);
        rewind($handle);
        $written = (string) stream_get_contents($handle);
        fclose($handle);

        return $written;
    }

    /**
     * A cell starting with `=`, `+`, `-` or `@` is a formula to a spreadsheet,
     * and a formula in a file somebody was sent is how a contact form turns
     * into remote code execution on the machine of whoever opens it.
     *
     * @param list<string> $row
     *
     * @return list<string>
     */
    private static function defused(array $row): array
    {
        return array_map(
            static fn (string $cell): string => 1 === preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell,
            $row,
        );
    }
}
