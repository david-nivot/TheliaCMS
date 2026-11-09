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

namespace TheliaCMS\Tests\Support;

/**
 * One-page PDFs written in memory, their cross-reference table included: no
 * binary fixture, no file downloaded. The text is Latin, in Helvetica.
 */
final class MinimalPdf
{
    /**
     * @param string $text         the text of the page; empty for a page without any, as a scan
     * @param string $trailerExtra entries added to the trailer, as an `/Encrypt` that protects it
     */
    public static function of(string $text, string $trailerExtra = ''): string
    {
        return self::write(self::page($text), $trailerExtra);
    }

    /**
     * The one-page PDF of `of()`, with streams compressed by Flate added to
     * it, each inflating to the size given: a small file that weighs much
     * once decoded.
     *
     * @param string $dictionaryExtra entries added to the dictionary of each stream, as `/Subtype /Image`
     */
    public static function withInflatingStreams(string $text, int $count, int $inflatedBytes, string $dictionaryExtra = ''): string
    {
        $objects = self::page($text);
        $deflated = (string) gzcompress(str_repeat(' ', $inflatedBytes), 9);

        foreach (range(1, $count) as $_) {
            $objects[] = \sprintf("<< /Length %d /Filter /FlateDecode %s >>\nstream\n%s\nendstream", \strlen($deflated), $dictionaryExtra, $deflated);
        }

        return self::write($objects, '');
    }

    /**
     * @return array<int, string>
     */
    private static function page(string $text): array
    {
        $stream = '' === $text ? '' : \sprintf('BT /F1 12 Tf 72 720 Td (%s) Tj ET', str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text));

        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => \sprintf("<< /Length %d >>\nstream\n%s\nendstream", \strlen($stream), $stream),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
    }

    /**
     * @param array<int, string> $objects numbered from 1, in order
     */
    private static function write(array $objects, string $trailerExtra): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $object) {
            $offsets[$number] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $number, $object);
        }

        $xref = \strlen($pdf);
        $size = \count($objects) + 1;
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", $size);

        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.\sprintf("trailer\n<< /Size %d /Root 1 0 R %s >>\nstartxref\n%d\n%%%%EOF", $size, $trailerExtra, $xref);
    }
}
