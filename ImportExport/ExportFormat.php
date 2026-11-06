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

namespace TheliaCMS\ImportExport;

/**
 * The envelope every export file carries.
 *
 * The version is the version of the file format, not of the module: a file
 * written today must still be readable by a module released in two years, and
 * the only way to promise that is to state which shape the reader is looking at
 * and to refuse the ones it does not know.
 */
final class ExportFormat
{
    public const string NAME = 'thelia-cms';

    /**
     * 2 since module 2.0.0: the content of a page is a document
     * (see TheliaCMS\Content\Document) and its rendering, where version 1
     * carried the project of the former editor and its stylesheets. A version
     * 1 file still imports everything but those contents.
     *
     * 3 since module 2.6.0: a menu carries `locale`, '' for the shared menu of
     * its code and a locale for a language with a menu of its own, so a code
     * may appear more than once. A reader of version 2 matched menus on the
     * code alone and, asked to replace, would have written the menu of a
     * language over the shared one. A version 2 file still imports: its
     * menus are the shared ones.
     *
     * 4 since the header zone: a page may carry `zone: true`, the header zone,
     * which has no address and is matched on its kind. A reader of version 3
     * would import it as an ordinary page, visible, with its published
     * version, and put it at an address. A version 3 file still imports: it
     * holds no zone.
     */
    public const int VERSION = 4;

    /** The first version whose contents this module can read. */
    public const int FIRST_VERSION_WITH_DOCUMENTS = 2;

    /**
     * Sections a reader knows. Anything else in the file is ignored rather than
     * rejected, so a file written by a later module still imports what this one
     * understands.
     *
     * @var list<string>
     */
    public const array SECTIONS = ['page_types', 'components', 'pages', 'catalogue_contents', 'menus', 'forms', 'settings', 'media'];

    /**
     * @param list<string> $locales
     *
     * @return array<string, mixed>
     */
    public static function header(string $moduleVersion, array $locales, \DateTimeImmutable $generatedAt): array
    {
        return [
            'format' => self::NAME,
            'version' => self::VERSION,
            'generated_at' => $generatedAt->format(\DATE_ATOM),
            'module_version' => $moduleVersion,
            'locales' => array_values($locales),
        ];
    }

    /**
     * @param array<mixed> $document
     *
     * @throws \InvalidArgumentException when the file is not one this module can read
     */
    public static function assertReadable(array $document): void
    {
        $format = $document['format'] ?? null;

        if (self::NAME !== $format) {
            throw new \InvalidArgumentException(\sprintf('This file is not a Thelia CMS export: it declares the format "%s".', \is_string($format) ? $format : \gettype($format)));
        }

        $version = $document['version'] ?? null;

        if (!\is_int($version)) {
            throw new \InvalidArgumentException('This export file carries no format version.');
        }

        if ($version > self::VERSION) {
            throw new \InvalidArgumentException(\sprintf('This file is in format version %d, and this module reads up to version %d. Update the module first.', $version, self::VERSION));
        }
    }
}
