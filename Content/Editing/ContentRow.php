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

namespace TheliaCMS\Content\Editing;

use Propel\Runtime\Connection\ConnectionInterface;

/**
 * The row that holds one content in one language, as the writer reads and
 * changes it: a draft, and what is online. A row that was never saved is
 * handed out the same way, so the writer never names the model behind it.
 */
interface ContentRow
{
    public function isNew(): bool;

    public function draftDocument(): ?string;

    public function draftProvenance(): ?string;

    public function publishedDocument(): ?string;

    public function publishedHtml(): ?string;

    public function isOnline(): bool;

    /** The last time the row was saved, edits and publications alike; null for a row never saved. */
    public function updatedAt(): ?\DateTimeInterface;

    /** Stores the draft; a null provenance leaves the mark of the draft as it is. */
    public function saveDraft(string $document, string $html, ?int $updatedBy, ?string $provenance): void;

    /** Replaces the mark of the draft, with none when null. */
    public function replaceDraftProvenance(?string $provenance): void;

    /** Puts the draft online as it is, with the HTML given. */
    public function publish(string $html, ConnectionInterface $connection): ContentSnapshot;

    /** Replaces the published HTML only: the date of the last update is not an edit. */
    public function rewritePublishedHtml(string $html): void;

    /**
     * The language this content was written from and the fingerprint of its
     * words then, as stored; null for a content that follows nothing.
     *
     * @return array{locale: string, fingerprint: string}|null
     */
    public function followedSource(): ?array;

    /** Ties the content to the draft of another language, which is not an edit: the date of the last update stays. */
    public function followSource(string $locale, string $fingerprint): void;

    /** Takes it offline, the published snapshot going with the date. */
    public function takeOffline(): void;
}
