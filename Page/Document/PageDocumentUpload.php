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

namespace TheliaCMS\Page\Document;

use TheliaCMS\Model\CmsPageDocument;

/**
 * What a sending of files to a page gave: the documents attached, and each
 * file refused with why. One refused file never costs the others.
 */
final readonly class PageDocumentUpload
{
    /**
     * @param list<CmsPageDocument>                                  $stored
     * @param list<array{name: string, reason: PageDocumentRefusal}> $refused
     */
    public function __construct(
        public array $stored,
        public array $refused,
    ) {
    }

    /**
     * @return list<int>
     */
    public function storedIds(): array
    {
        return array_map(static fn (CmsPageDocument $document): int => (int) $document->getId(), $this->stored);
    }
}
