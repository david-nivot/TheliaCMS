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

use TheliaCMS\Content\Document;
use TheliaCMS\Content\DocumentValidator;
use TheliaCMS\Content\InvalidDocumentException;
use TheliaCMS\Content\Pipeline\PublishedContentSanitizer;
use TheliaCMS\Content\Section;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\Site\SiteComponent;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\MediaReferences;

/**
 * The markup and the documents of a file, checked as the site checks its own.
 */
final readonly class ImportedContent
{
    public function __construct(
        private MediaReferences $media,
        private PublishedContentSanitizer $sanitizer,
        private DocumentValidator $documentValidator,
        private SectionCatalog $catalog,
    ) {
    }

    /**
     * Markup of the file, filtered as a publication filters it: the draft is
     * shown by the preview, the published version by the front, and both
     * would otherwise carry whatever the file was edited to hold.
     *
     * @param array<int, int> $mediaMap
     */
    public function html(mixed $html, array $mediaMap, ImportOptions $options, ImportReport $report, string $where): ?string
    {
        if (!\is_string($html)) {
            return null;
        }

        $remapped = (string) $this->media->remap($html, $mediaMap);
        $filtered = $this->sanitizer->html($remapped, $options->allowCustomCode);

        if ($filtered === $remapped) {
            return $filtered;
        }

        $removedEmbeds = $this->sanitizer->customCodeCount($remapped) - $this->sanitizer->customCodeCount($filtered);

        $report->warn(\sprintf(
            '%s was filtered: it held markup a page may not publish%s.',
            $where,
            $removedEmbeds > 0 ? \sprintf(', among which %d element(s) of custom code, which an import keeps only when it is allowed custom code', $removedEmbeds) : '',
        ));

        return $filtered;
    }

    /**
     * A document of the file, checked as the content screen checks the one it
     * saves. The sections of a component of the site that neither the file
     * nor this site has are kept as they are, and said so: the document is
     * saved again once the component is made here. A document the screen
     * would refuse otherwise is left out, and the content keeps only its
     * filtered markup.
     *
     * @param array<int, int> $mediaMap
     */
    public function document(mixed $json, array $mediaMap, ImportReport $report, string $where): ?string
    {
        if (!\is_string($json)) {
            return null;
        }

        $remapped = $this->media->remap($json, $mediaMap);

        try {
            $document = Document::fromJson($remapped);

            if (null === $document) {
                return null;
            }

            $known = array_values(array_filter($document->sections, $this->isKnownOrNotOfTheSite(...)));

            if (\count($known) < \count($document->sections)) {
                // The sections of the missing component have no schema to be
                // read against here; every other one is checked all the same.
                $this->documentValidator->validate(new Document($known));

                return $remapped;
            }

            return $this->documentValidator->validate($document)->toJson();
        } catch (InvalidDocumentException $exception) {
            $report->warn(\sprintf('%s was imported without its document, which this site does not accept: %s', $where, implode(' ', $exception->problems)));

            return null;
        }
    }

    private function isKnownOrNotOfTheSite(Section $section): bool
    {
        return !str_starts_with($section->component, SiteComponent::PREFIX) || null !== $this->catalog->find($section->component);
    }
}
