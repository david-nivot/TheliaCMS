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

namespace TheliaCMS\Generation;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A text file the editor joins to the brief of a page: a product sheet, a
 * page of the old site saved as text, a note from the client. The generator
 * reads it as material the brief gives, as it reads a web page the brief
 * cites: data of a third party, never instructions.
 *
 * Built from an upload, read once and never written to disk. Only its name
 * (the base name, control characters stripped, 120 characters at most) and
 * its text are kept.
 */
final readonly class ReferenceFile
{
    /** Five times the brief. */
    public const int MAX_CHARACTERS = 100_000;

    /**
     * The ceiling in bytes told to the browser and checked before the file is
     * read: MAX_CHARACTERS characters of UTF-8, four bytes each at most.
     */
    public const int MAX_BYTES = self::MAX_CHARACTERS * 4;

    /** @var list<string> */
    public const array EXTENSIONS = ['md', 'txt'];

    private const int NAME_LENGTH = 120;

    private const string FALLBACK_NAME = 'reference.txt';

    public function __construct(
        public string $name,
        public string $text,
    ) {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A reference file has a name.');
        }

        if ('' === trim($text)) {
            throw new \InvalidArgumentException('A reference file says something.');
        }
    }

    /**
     * @throws ReferenceFileRefusedException when the file is not one the brief takes, with the reason the screen says
     */
    public static function fromUpload(UploadedFile $file): self
    {
        if (!$file->isValid()) {
            throw new ReferenceFileRefusedException(\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? ReferenceFileRefusal::TooLarge : ReferenceFileRefusal::Failed);
        }

        $name = self::cleanName($file->getClientOriginalName());

        if (!\in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            throw new ReferenceFileRefusedException(ReferenceFileRefusal::Format);
        }

        // Measured before it is read: a file far above the ceiling is not
        // loaded to be refused.
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ReferenceFileRefusedException(ReferenceFileRefusal::TooLong);
        }

        $text = @file_get_contents($file->getPathname());

        if (!\is_string($text)) {
            throw new ReferenceFileRefusedException(ReferenceFileRefusal::Failed);
        }

        if (str_contains($text, "\0") || !mb_check_encoding($text, 'UTF-8')) {
            throw new ReferenceFileRefusedException(ReferenceFileRefusal::Unreadable);
        }

        // A byte order mark is not text.
        $text = ltrim($text, "\u{FEFF}");

        if ('' === trim($text)) {
            throw new ReferenceFileRefusedException(ReferenceFileRefusal::Empty);
        }

        if (mb_strlen($text) > self::MAX_CHARACTERS) {
            throw new ReferenceFileRefusedException(ReferenceFileRefusal::TooLong);
        }

        return new self($name, $text);
    }

    /**
     * The name shown to the editor and given to the model as the reference
     * of the file: the base name alone, whatever path the browser sent, with
     * no control character, at most 120 characters.
     */
    public static function cleanName(string $original): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $name = trim((string) preg_replace('/\p{C}+/u', '', $name));

        if ('' === $name || !mb_check_encoding($name, 'UTF-8')) {
            return self::FALLBACK_NAME;
        }

        return mb_strlen($name) > self::NAME_LENGTH ? mb_substr($name, 0, self::NAME_LENGTH) : $name;
    }

    /** In characters. */
    public function length(): int
    {
        return mb_strlen($this->text);
    }

    /**
     * What the ledger keeps of the text: enough to tell two files apart,
     * nothing of what they say.
     */
    public function fingerprint(): string
    {
        return hash('xxh128', $this->text);
    }
}
