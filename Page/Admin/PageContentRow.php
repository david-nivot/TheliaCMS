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

namespace TheliaCMS\Page\Admin;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\Clock\Clock;
use TheliaCMS\Content\Editing\ContentRow;
use TheliaCMS\Content\Editing\ContentSnapshot;
use TheliaCMS\Model\CmsPageContent;

/**
 * The row of a page in one language, seen by the shared writer.
 */
final readonly class PageContentRow implements ContentRow
{
    public function __construct(
        private CmsPageContent $content,
    ) {
    }

    public function isNew(): bool
    {
        return $this->content->isNew();
    }

    public function draftDocument(): ?string
    {
        return $this->content->getDraftDocument();
    }

    public function draftProvenance(): ?string
    {
        return $this->content->getDraftProvenance();
    }

    public function publishedDocument(): ?string
    {
        return $this->content->getPublishedDocument();
    }

    public function publishedHtml(): ?string
    {
        return $this->content->getPublishedHtml();
    }

    public function isOnline(): bool
    {
        return null !== $this->content->getPublishedAt();
    }

    public function updatedAt(): ?\DateTimeInterface
    {
        return $this->content->getUpdatedAt();
    }

    public function saveDraft(string $document, string $html, ?int $updatedBy, ?string $provenance): void
    {
        $this->content
            ->setDraftDocument($document)
            ->setDraftHtml($html)
            ->setDraftUpdatedBy($updatedBy);

        if (null !== $provenance) {
            $this->content->setDraftProvenance($provenance);
        }

        $this->content->save();
    }

    public function replaceDraftProvenance(?string $provenance): void
    {
        $this->content->setDraftProvenance($provenance)->save();
    }

    public function publish(string $html, ConnectionInterface $connection): ContentSnapshot
    {
        // One instant for both columns: left to itself, the timestampable
        // behaviour reads the clock again on save, and a second turning in
        // between would date the edit after the publication.
        $now = Clock::get()->now();

        $this->content->setPublishedDocument($this->content->getDraftDocument())
            ->setPublishedHtml($html)
            ->setPublishedProvenance($this->content->getDraftProvenance())
            ->setPublishedAt($now)
            ->setUpdatedAt($now)
            ->save($connection);

        return new ContentSnapshot($this->content->getPublishedDocument(), $this->content->getPublishedHtml(), $this->content->getPublishedProvenance());
    }

    public function rewritePublishedHtml(string $html): void
    {
        // `updated_at` follows the draft: this is not an edit.
        $this->content->setPublishedHtml($html)->keepUpdateDateUnchanged()->save();
    }

    public function followedSource(): ?array
    {
        $locale = (string) $this->content->getTranslatedFrom();
        $fingerprint = (string) $this->content->getSourceFingerprint();

        return '' === $locale || '' === $fingerprint ? null : ['locale' => $locale, 'fingerprint' => $fingerprint];
    }

    public function followSource(string $locale, string $fingerprint): void
    {
        // Following a source is not an edit: `updated_at` is what tells that
        // a page changed since its publication.
        $this->content->setTranslatedFrom($locale)->setSourceFingerprint($fingerprint)->keepUpdateDateUnchanged()->save();
    }

    public function takeOffline(): void
    {
        $this->content->setPublishedAt(null)
            ->setPublishedDocument(null)
            ->setPublishedHtml(null)
            ->setPublishedProvenance(null)
            ->save();
    }
}
