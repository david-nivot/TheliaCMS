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
use TheliaCMS\Catalogue\CatalogueItemKeys;
use TheliaCMS\Catalogue\CatalogueItemType;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\ProvenanceMark;
use TheliaCMS\ImportExport\ImportOptions;
use TheliaCMS\ImportExport\ImportReport;
use TheliaCMS\ImportExport\SiteDocument;
use TheliaCMS\Model\CmsItemContent;
use TheliaCMS\Model\CmsItemContentQuery;

/**
 * The documents of the catalogue items, each put on the item its key names here.
 */
final readonly class CatalogueContentsStep
{
    public function __construct(
        private ImportedContent $content,
        private CatalogueItemKeys $itemKeys = new CatalogueItemKeys(),
    ) {
    }

    /**
     * The documents of the catalogue items, each put on the item its key names
     * here. An item this site does not have is said, not created: the module
     * writes no product, category, content or folder.
     *
     * @param array<int, int> $mediaMap
     */
    public function import(SiteDocument $document, ImportOptions $options, ImportReport $report, array $mediaMap, ConnectionInterface $connection): void
    {
        foreach ($document->catalogueContents() as $entry) {
            $type = CatalogueItemType::tryFrom((string) ($entry['type'] ?? ''));
            $key = \is_array($entry['key'] ?? null) ? $entry['key'] : [];
            $id = null === $type ? null : $this->itemKeys->find($type, $key);

            if (null === $type || null === $id) {
                $report->warn(\sprintf(
                    'The CMS content of the %s %s was left out: this site has no such item.',
                    (string) ($entry['type'] ?? '?'),
                    self::describeKey($key),
                ));
                $report->skipped('catalogue contents');

                continue;
            }

            $existing = CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->count($connection);

            if ($existing > 0 && !$options->replace) {
                $report->skipped('catalogue contents');

                continue;
            }

            foreach (\is_array($entry['contents'] ?? null) ? $entry['contents'] : [] as $locale => $content) {
                if (!\is_array($content)) {
                    continue;
                }

                $locale = (string) $locale;
                $where = \sprintf('the CMS content of the %s %s in %s', $type->value, self::describeKey($key), $locale);
                $model = CmsItemContentQuery::create()->filterByItemType($type->value)->filterByItemId($id)->filterByLocale($locale)->findOne($connection)
                    ?? (new CmsItemContent())->setItemType($type->value)->setItemId($id)->setLocale($locale);

                $model
                    ->setDraftDocument($this->content->document($content['draft_document'] ?? null, $mediaMap, $report, 'The draft of '.$where))
                    ->setDraftHtml($this->content->html($content['draft_html'] ?? null, $mediaMap, $options, $report, 'The draft of '.$where))
                    ->setDraftProvenance(ProvenanceMark::normalize($content['draft_provenance'] ?? null))
                    ->setPublishedDocument($this->content->document($content['published_document'] ?? null, $mediaMap, $report, 'The published version of '.$where))
                    ->setPublishedHtml($this->content->html($content['published_html'] ?? null, $mediaMap, $options, $report, 'The published version of '.$where))
                    ->setPublishedProvenance(ProvenanceMark::normalize($content['published_provenance'] ?? null))
                    ->setPublishedAt(ImportedDate::parse($content['published_at'] ?? null));

                // As for a page: kept, or every imported document would read
                // "modified since publication".
                $model->setUpdatedAt(ImportedDate::parse($content['updated_at'] ?? null) ?? $model->getPublishedAt() ?? new \DateTimeImmutable());
                $model->save($connection);
            }

            $existing > 0 ? $report->replaced('catalogue contents') : $report->created('catalogue contents');
        }
    }

    /**
     * @param array<string, mixed> $key
     */
    private static function describeKey(array $key): string
    {
        if (\is_string($key['reference'] ?? null) && '' !== $key['reference']) {
            return \sprintf('with the reference "%s"', $key['reference']);
        }

        $urls = \is_array($key['urls'] ?? null) ? array_filter($key['urls'], 'is_string') : [];

        return [] === $urls ? 'with no reference nor address' : \sprintf('at "%s"', reset($urls));
    }
}
