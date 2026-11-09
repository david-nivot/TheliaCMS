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

    private function __construct()
    {
    }

    /**
     * The text of a PDF, normalised. Null when the parser cannot read it (damaged, protected); an empty
     * string when it holds no text, as a scan does.
     */
    public static function ofPdf(string $body): ?string
    {
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
