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

namespace TheliaCMS\Zone;

/**
 * What an import of HeaderHighlights into the header zone did, or would do
 * on a dry run: per language, the images, and every problem met.
 */
final readonly class HeaderHighlightsImport
{
    /** The draft of the language was written. */
    public const string WRITTEN = 'written';

    /** The language holds a draft in the zone already: left as it is. */
    public const string KEPT = 'kept';

    /** The document was refused: see the failures. */
    public const string FAILED = 'failed';

    /** HeaderHighlights holds no text in this language. */
    public const string NO_TEXT = 'no text';

    /**
     * @param array<string, array{status: string, blocks: int}> $languages by locale
     * @param list<string>                                      $problems  what could not be carried over, or is worth a look
     * @param list<string>                                      $failures  languages that could not be written, with the reason
     */
    public function __construct(
        public bool $found,
        public array $languages = [],
        public int $imagesCreated = 0,
        public int $imagesReused = 0,
        public array $problems = [],
        public array $failures = [],
    ) {
    }
}
