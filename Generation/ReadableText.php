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

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * The text a model reads of a document of a third party: a PDF cited by the
 * brief or joined to it. The gateway of the core carries text only, so the
 * text of a PDF is extracted here, its images left out.
 */
final class ReadableText
{
    /** What the parser may use to inflate the streams of a PDF: a small file can hold a very large one. */
    private const int PDF_DECODE_MEMORY_LIMIT = 16_777_216;

    /**
     * What the parser may be handed of a PDF, decoded, before it is: the
     * streams it reads for text (pages, forms, objects), and all the streams
     * of the file, images and fonts included. The parser holds every stream
     * it inflates and takes, for a stream of pages, up to 37 times its size
     * in memory (measured on the host, 2026-11-09: a page stream of 16 MB,
     * 595 MB and 6.9 s; of 4 MB, 148 MB): a small PDF of many such streams
     * stops PHP on its memory limit, a fatal error no catch reaches. A PDF
     * of 100,000 characters, the most a joined file holds, has far less.
     */
    private const int PDF_TEXT_STREAMS_BUDGET = 4 * 1024 * 1024;

    private const int PDF_STREAMS_BUDGET = 64 * 1024 * 1024;

    private function __construct()
    {
    }

    /**
     * The text of a PDF, normalised. Null when the parser cannot read it (damaged, protected); an empty
     * string when it holds no text, as a scan does.
     */
    public static function ofPdf(string $body): ?string
    {
        if (self::isTooHeavyToParse($body)) {
            return null;
        }

        $config = new Config();
        $config->setDecodeMemoryLimit(self::PDF_DECODE_MEMORY_LIMIT);
        $config->setRetainImageContent(false);

        try {
            return self::normalize(str_replace("\0", '', self::utf8((new Parser([], $config))->parseContent($body)->getText())));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the streams of a PDF, decoded, are more than the parser may be
     * handed (PDF_TEXT_STREAMS_BUDGET, PDF_STREAMS_BUDGET). Measured without
     * the parser, a stream at a time, never inflating more than what is left
     * of the budget.
     */
    public static function isTooHeavyToParse(string $body): bool
    {
        $text = 0;
        $all = 0;
        $offset = 0;

        while (false !== ($start = strpos($body, 'stream', $offset))) {
            $offset = $start + 6;

            // "endstream" also holds the word; a stream starts after a dictionary.
            if ($start >= 3 && 'end' === substr($body, $start - 3, 3)) {
                continue;
            }

            $dataStart = $start + 6;
            $dataStart += "\r\n" === substr($body, $dataStart, 2) ? 2 : ("\n" === ($body[$dataStart] ?? '') ? 1 : 0);
            $end = strpos($body, 'endstream', $dataStart);

            if (false === $end) {
                break;
            }

            $offset = $end + 9;
            $dictionaryStart = strrpos(substr($body, max(0, $start - 4096), min($start, 4096)), 'obj');
            $dictionary = false === $dictionaryStart ? '' : substr($body, max(0, $start - 4096) + $dictionaryStart, $start - (max(0, $start - 4096) + $dictionaryStart));
            $raw = substr($body, $dataStart, $end - $dataStart);
            $left = self::PDF_STREAMS_BUDGET - $all;
            $size = str_contains($dictionary, '/FlateDecode') ? self::inflatedSize($raw, $left) : \strlen($raw);
            $all += $size;

            if (1 !== preg_match('#/Subtype\s*/(Image|Type1C|CIDFontType0C|OpenType)\b|/Length[123]\b#', $dictionary)) {
                $text += $size;
            }

            if ($all > self::PDF_STREAMS_BUDGET || $text > self::PDF_TEXT_STREAMS_BUDGET) {
                return true;
            }
        }

        return false;
    }

    /**
     * The size of a Flate stream once inflated, counted a kilobyte of input
     * at a time so that no more than a megabyte is held at once; past the
     * limit, the count stops. A stream that does not inflate counts as its
     * own size, as the parser keeps it.
     */
    private static function inflatedSize(string $raw, int $limit): int
    {
        $inflater = @inflate_init(\ZLIB_ENCODING_DEFLATE);

        if (false === $inflater) {
            return \strlen($raw);
        }

        $size = 0;

        foreach (str_split($raw, 1024) as $chunk) {
            $out = @inflate_add($inflater, $chunk, \ZLIB_SYNC_FLUSH);

            if (false === $out) {
                return max($size, \strlen($raw));
            }

            $size += \strlen($out);

            if ($size > $limit) {
                return $size;
            }

            if (\ZLIB_STREAM_END === inflate_get_status($inflater)) {
                break;
            }
        }

        return $size;
    }

    /**
     * Spaces folded, one line break between lines, no blank line: what the
     * model reads, not how the document was laid out.
     */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? '';
        $text = preg_replace('/ ?\n ?/u', "\n", $text) ?? '';

        return trim(preg_replace('/\n{2,}/', "\n", $text) ?? '');
    }

    /** A body that is not UTF-8 is read as Latin-1, the usual other one. */
    public static function utf8(string $body): string
    {
        return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
    }
}
