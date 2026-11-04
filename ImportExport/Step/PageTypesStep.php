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

namespace TheliaCMS\ImportExport\Step;

use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Model\Lang;
use TheliaCMS\Generation\Brief;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\Page\Field\FieldDefinitions;
use TheliaCMS\Page\Field\InvalidFieldsException;
use TheliaCMS\Page\PageTypeCode;
use TheliaCMS\Page\PageTypeRepository;
use TheliaCMS\Page\PageTypeWriter;

/**
 * The page types a site file describes.
 */
final readonly class PageTypesStep
{
    public function __construct(
        private PageTypeRepository $pageTypes,
        private PageTypeWriter $pageTypeWriter,
    ) {
    }

    /**
     * The types the file describes as templates. A type the site lacks, or
     * has as a bare code, takes the description of the file; a type the site
     * has described already keeps its own, and the report says so when the
     * two differ: the fields of a type are what its pages hold values under.
     */
    public function import(SiteDocument $document, ImportReport $report, ConnectionInterface $connection): void
    {
        foreach ($document->pageTypes() as $row) {
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';

            if (!PageTypeCode::isValid($code)) {
                $report->warn('A page type that is not a valid code was left out.');

                continue;
            }

            try {
                $fields = FieldDefinitions::fromArray(\is_array($row['fields'] ?? null) ? $row['fields'] : []);
            } catch (InvalidFieldsException $exception) {
                $report->warn(\sprintf('The fields of the page type "%s" were left out: %s', $code, $exception->getMessage()));
                $fields = FieldDefinitions::none();
            }

            $current = $this->pageTypes->details($code, $connection);

            if ($current->isTemplate()) {
                if ($current->fields()->toArray() !== $fields->toArray()) {
                    $report->warn(\sprintf('Page type "%s" already has fields on this site: they were kept, and the ones of the file left out.', $code));
                }

                continue;
            }

            $briefText = \is_string($row['brief'] ?? null) && '' !== trim($row['brief']) ? trim($row['brief']) : null;
            $brief = null === $briefText ? null : new Brief($briefText, self::stringOrNull($row['brief_tone'] ?? null), self::stringOrNull($row['brief_audience'] ?? null));
            $translations = \is_array($row['translations'] ?? null) ? $row['translations'] : [];

            if ([] === $translations) {
                $translations = [Lang::getDefaultLanguage()->getLocale() => []];
            }

            foreach ($translations as $locale => $translation) {
                $this->pageTypeWriter->describe(
                    $code,
                    (string) $locale,
                    self::stringOrNull($translation['title'] ?? null),
                    self::stringOrNull($translation['description'] ?? null),
                    $brief,
                    $fields,
                    $connection,
                    self::stringOrNull($row['instructions'] ?? null),
                );
            }
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
