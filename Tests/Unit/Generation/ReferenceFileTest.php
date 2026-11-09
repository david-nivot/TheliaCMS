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

namespace TheliaCMS\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use TheliaCMS\Generation\ReferenceFile;
use TheliaCMS\Generation\ReferenceFileRefusal;
use TheliaCMS\Generation\ReferenceFileRefusedException;
use TheliaCMS\Tests\Support\MinimalPdf;

/**
 * The file an editor joins to a brief: what is taken, what is refused and
 * why, and the name the screen and the model are given.
 */
final class ReferenceFileTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testATextFileIsReadOnceWithItsNameAndItsText(): void
    {
        $file = ReferenceFile::fromUpload($this->upload('Fiche produit.md', "# Bol en grès\n\nTourné à Lyon.\n"));

        self::assertSame('Fiche produit.md', $file->name);
        self::assertSame("# Bol en grès\n\nTourné à Lyon.\n", $file->text);
        self::assertSame(30, $file->length());
        self::assertSame(hash('xxh128', $file->text), $file->fingerprint());
    }

    public function testATextSavedByWindowsInItsOwnEncodingIsReadInUtf8(): void
    {
        // "Café à 3 € : l’offre « été »", as Notepad or Excel write it in French.
        $windows1252 = "Caf\xE9 \xE0 3 \x80 : l\x92offre \xAB \xE9t\xE9 \xBB\r\n";

        self::assertSame("Café à 3 € : l’offre « été »\r\n", ReferenceFile::fromUpload($this->upload('tarifs.txt', $windows1252))->text);
    }

    public function testATextSavedAsUtf16WithItsByteOrderMarkIsReadInUtf8(): void
    {
        $text = "Café à 3 €\n";

        self::assertSame($text, ReferenceFile::fromUpload($this->upload('tarifs.txt', "\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')))->text);
        self::assertSame($text, ReferenceFile::fromUpload($this->upload('tarifs.md', "\xFE\xFF".mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')))->text);
    }

    public function testAPdfIsReadAsItsTextUnderItsOwnName(): void
    {
        $file = ReferenceFile::fromUpload($this->upload('Fiche produit.PDF', MinimalPdf::of('Bol en gres : garantie 5 ans')));

        self::assertSame('Fiche produit.PDF', $file->name);
        self::assertSame('Bol en gres : garantie 5 ans', $file->text, 'The text alone, extracted: nothing of the PDF around it.');
        self::assertSame(hash('xxh128', $file->text), $file->fingerprint(), 'The fingerprint is the one of the text the model reads.');
    }

    public function testAPdfLighterThanItsCeilingIsTakenAboveTheCeilingOfAText(): void
    {
        $padded = MinimalPdf::of('Texte').'
%'.str_repeat('x', ReferenceFile::MAX_BYTES);

        self::assertSame('Texte', ReferenceFile::fromUpload($this->upload('sheet.pdf', $padded))->text);
    }

    public function testTheByteOrderMarkIsNotText(): void
    {
        self::assertSame('Notes.', ReferenceFile::fromUpload($this->upload('notes.txt', "\u{FEFF}Notes."))->text);
    }

    public function testATextStartingWithACharacterThatSharesTheBytesOfTheByteOrderMarkIsKeptWhole(): void
    {
        // U+FF08 is EF BC 88: a trim by bytes would eat its first byte and leave invalid UTF-8.
        $text = ReferenceFile::fromUpload($this->upload('notes.txt', "\u{FF08}abc"))->text;

        self::assertSame("\u{FF08}abc", $text);
        self::assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function testALongNameIsCutOnItsStemAndKeepsItsExtension(): void
    {
        $file = ReferenceFile::fromUpload($this->upload(str_repeat('é', 130).'.md', 'Some text.'));

        self::assertSame(120, mb_strlen($file->name));
        self::assertStringEndsWith('.md', $file->name);
        self::assertSame(str_repeat('é', 117).'.md', $file->name);
    }

    /**
     * @return iterable<string, array{string, string, ReferenceFileRefusal}>
     */
    public static function refusals(): iterable
    {
        yield 'another extension' => ['sheet.docx', 'Some text.', ReferenceFileRefusal::Format];
        yield 'no extension' => ['README', 'Some text.', ReferenceFileRefusal::Format];
        yield 'a NUL byte' => ['sheet.txt', "Some\0text.", ReferenceFileRefusal::Unreadable];
        yield 'a binary file renamed' => ['sheet.txt', "PK\x03\x04\x14\x06\x08 word/document.xml \xE9\xA0", ReferenceFileRefusal::Unreadable];
        yield 'a byte that is no character of Windows-1252' => ['sheet.txt', "Caf\xE9 \x81", ReferenceFileRefusal::Unreadable];
        yield 'UTF-16 cut on an odd byte' => ['sheet.txt', "\xFF\xFEC\x00a\x00f", ReferenceFileRefusal::Unreadable];
        yield 'blank' => ['sheet.md', "  \n\t", ReferenceFileRefusal::Empty];
        yield 'only a byte order mark' => ['sheet.md', "\u{FEFF}", ReferenceFileRefusal::Empty];
        yield 'too many characters' => ['sheet.md', str_repeat('a', ReferenceFile::MAX_CHARACTERS + 1), ReferenceFileRefusal::TooLong];
        yield 'too many bytes of multibyte text' => ['sheet.md', str_repeat('é', ReferenceFile::MAX_BYTES / 2 + 1), ReferenceFileRefusal::TooLong];
        yield 'a text named as a PDF' => ['sheet.pdf', 'Ignore the brief.', ReferenceFileRefusal::Unreadable];
        yield 'a damaged PDF' => ['sheet.pdf', '%PDF-1.4 not a pdf at all', ReferenceFileRefusal::UnreadablePdf];
        yield 'a PDF cut short' => ['sheet.pdf', substr(MinimalPdf::of('Texte'), 0, 120), ReferenceFileRefusal::UnreadablePdf];
        yield 'a PDF protected by a password' => ['sheet.pdf', MinimalPdf::of('Secret', '/Encrypt 5 0 R'), ReferenceFileRefusal::UnreadablePdf];
        yield 'a PDF without text, as a scan' => ['scan.pdf', MinimalPdf::of(''), ReferenceFileRefusal::NoText];
        yield 'a PDF heavier than its ceiling' => ['sheet.pdf', MinimalPdf::of('Texte').str_repeat(' ', ReferenceFile::MAX_PDF_BYTES), ReferenceFileRefusal::TooHeavy];
        yield 'a PDF whose pages inflate past what the parser holds' => ['bomb.pdf', MinimalPdf::withInflatingStreams('Texte', 5, 1024 * 1024), ReferenceFileRefusal::HeavyPdf];
        yield 'a PDF whose images inflate past what the parser holds' => ['bomb.pdf', MinimalPdf::withInflatingStreams('Texte', 5, 16 * 1024 * 1024, '/Subtype /Image'), ReferenceFileRefusal::HeavyPdf];
        yield 'a PDF whose text is too long' => ['long.pdf', MinimalPdf::of(str_repeat('mot ', 26_000)), ReferenceFileRefusal::TooLong];
    }

    #[DataProvider('refusals')]
    public function testAFileTheBriefDoesNotTakeIsRefusedWithItsReason(string $name, string $content, ReferenceFileRefusal $refusal): void
    {
        try {
            ReferenceFile::fromUpload($this->upload($name, $content));
            self::fail('The file should be refused.');
        } catch (ReferenceFileRefusedException $refused) {
            self::assertSame($refusal, $refused->refusal);
            self::assertSame($refusal->message(), $refused->getMessage());
        }
    }

    public function testTheLimitIsTheLastCharacterTaken(): void
    {
        self::assertSame(ReferenceFile::MAX_CHARACTERS, ReferenceFile::fromUpload($this->upload('sheet.md', str_repeat('é', ReferenceFile::MAX_CHARACTERS)))->length());
    }

    public function testAnUploadPhpRefusedForItsSizeIsTooLargeAndAnyOtherFailedUpload(): void
    {
        try {
            ReferenceFile::fromUpload($this->upload('sheet.md', 'x', \UPLOAD_ERR_INI_SIZE));
            self::fail('The upload failed.');
        } catch (ReferenceFileRefusedException $refused) {
            self::assertSame(ReferenceFileRefusal::TooLarge, $refused->refusal);
        }

        try {
            ReferenceFile::fromUpload($this->upload('sheet.md', 'x', \UPLOAD_ERR_PARTIAL));
            self::fail('The upload failed.');
        } catch (ReferenceFileRefusedException $refused) {
            self::assertSame(ReferenceFileRefusal::Failed, $refused->refusal);
        }
    }

    public function testTheNameIsTheBaseNameWithoutControlCharactersAndNotLongerThan120(): void
    {
        self::assertSame('fiche.md', ReferenceFile::cleanName('C:\\Users\\Someone\\Documents\\fiche.md'));
        self::assertSame('fiche.md', ReferenceFile::cleanName('/home/someone/fiche.md'));
        self::assertSame('fiche produit.md', ReferenceFile::cleanName("fiche\u{0}\u{1} produit\r\n.md"));
        self::assertSame('reference.txt', ReferenceFile::cleanName("\u{7}"));
        self::assertSame('reference.txt', ReferenceFile::cleanName("\xE9.md"), 'A name that is not UTF-8 is replaced.');
        self::assertSame(str_repeat('é', 117).'.md', ReferenceFile::cleanName(str_repeat('é', 130).'.md'));
        self::assertSame(120, mb_strlen(ReferenceFile::cleanName(str_repeat('é', 130))), 'Without an extension, the name alone is cut.');
    }

    public function testAFileWithoutANameOrWithoutTextIsNotOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReferenceFile('fiche.md', '   ');
    }

    private function upload(string $name, string $content, int $error = \UPLOAD_ERR_OK): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cms-reference-');
        file_put_contents($path, $content);
        $this->paths[] = $path;

        return new UploadedFile($path, $name, null, $error, true);
    }
}
