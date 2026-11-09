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

use PHPUnit\Framework\TestCase;
use TheliaCMS\Generation\ReadableText;
use TheliaCMS\Tests\Support\MinimalPdf;

/**
 * A PDF is weighed before the parser reads it: the parser holds every stream
 * it inflates, and a page stream costs it many times its size, so a small
 * file can stop PHP on its memory limit, where no catch reaches.
 */
final class ReadableTextTest extends TestCase
{
    private const int MEGABYTE = 1024 * 1024;

    public function testAnOrdinaryPdfIsReadAsItsText(): void
    {
        $pdf = MinimalPdf::of('Tourne a Lyon');

        self::assertFalse(ReadableText::isTooHeavyToParse($pdf));
        self::assertSame('Tourne a Lyon', ReadableText::ofPdf($pdf));
    }

    public function testPageStreamsInflatingPastTheirBudgetAreNotHandedToTheParser(): void
    {
        $pdf = MinimalPdf::withInflatingStreams('Texte', 5, self::MEGABYTE);

        self::assertLessThan(50_000, \strlen($pdf), 'A small file.');
        self::assertTrue(ReadableText::isTooHeavyToParse($pdf));
        self::assertNull(ReadableText::ofPdf($pdf), 'Unreadable, rather than PHP out of memory.');
    }

    public function testImagesAndFontsWeighOnlyAgainstTheBudgetOfAllStreams(): void
    {
        self::assertFalse(ReadableText::isTooHeavyToParse(MinimalPdf::withInflatingStreams('Texte', 3, 16 * self::MEGABYTE, '/Subtype /Image')), 'Images of 48 MB are not read for text.');
        self::assertFalse(ReadableText::isTooHeavyToParse(MinimalPdf::withInflatingStreams('Texte', 3, 2 * self::MEGABYTE, '/Length1 2097152')), 'Nor fonts.');
        self::assertTrue(ReadableText::isTooHeavyToParse(MinimalPdf::withInflatingStreams('Texte', 5, 16 * self::MEGABYTE, '/Subtype /Image')), 'All streams together have a ceiling too.');
    }

    public function testAStreamThatDoesNotInflateWeighsItsOwnSize(): void
    {
        $pdf = MinimalPdf::of('Texte').str_repeat("\n9 0 obj\n<< /Filter /FlateDecode >>\nstream\nnot deflate\nendstream\nendobj", 3);

        self::assertFalse(ReadableText::isTooHeavyToParse($pdf));
    }
}
