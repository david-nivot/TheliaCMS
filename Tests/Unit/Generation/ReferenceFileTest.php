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

    public function testTheByteOrderMarkIsNotText(): void
    {
        self::assertSame('Notes.', ReferenceFile::fromUpload($this->upload('notes.txt', "\u{FEFF}Notes."))->text);
    }

    /**
     * @return iterable<string, array{string, string, ReferenceFileRefusal}>
     */
    public static function refusals(): iterable
    {
        yield 'another extension' => ['sheet.pdf', 'Some text.', ReferenceFileRefusal::Format];
        yield 'no extension' => ['README', 'Some text.', ReferenceFileRefusal::Format];
        yield 'a NUL byte' => ['sheet.txt', "Some\0text.", ReferenceFileRefusal::Unreadable];
        yield 'not UTF-8' => ['sheet.txt', "Caf\xE9", ReferenceFileRefusal::Unreadable];
        yield 'blank' => ['sheet.md', "  \n\t", ReferenceFileRefusal::Empty];
        yield 'only a byte order mark' => ['sheet.md', "\u{FEFF}", ReferenceFileRefusal::Empty];
        yield 'too many characters' => ['sheet.md', str_repeat('a', ReferenceFile::MAX_CHARACTERS + 1), ReferenceFileRefusal::TooLong];
        yield 'too many bytes of multibyte text' => ['sheet.md', str_repeat('é', ReferenceFile::MAX_BYTES / 2 + 1), ReferenceFileRefusal::TooLong];
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
        self::assertSame(120, mb_strlen(ReferenceFile::cleanName(str_repeat('é', 130).'.md')));
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
