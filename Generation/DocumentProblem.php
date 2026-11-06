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

/**
 * Something a generated document says that the site does not hold: an image
 * or a video that is not in the media library, a record that does not exist, an address
 * that leads nowhere, a heading structure that will not publish. The message
 * is what the model is told, in English; where it is, in the props of its
 * section, is what lets the value be emptied when the model does not mend it.
 */
final readonly class DocumentProblem
{
    public const string IMAGE = 'image';
    public const string VIDEO = 'video';
    public const string REFERENCE = 'reference';
    public const string ADDRESS = 'address';
    public const string HEADINGS = 'headings';

    /**
     * @param list<int|string>      $path    from the props of the section to the value: `['columns', 1, 'image']`
     * @param list<int|string>|null $rowPath the row of a list the value belongs to, `['columns', 1]`, null for a prop of the section itself
     */
    public function __construct(
        public string $kind,
        public string $message,
        public ?string $sectionId = null,
        public array $path = [],
        public ?array $rowPath = null,
        public string $location = '',
    ) {
    }
}
