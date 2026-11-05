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

use TheliaCMS\Content\Document;
use TheliaCMS\Content\TranslatableWords;

/**
 * Follows a language written from another one: the content of the translation
 * keeps the language it was written from and the fingerprint of the words of
 * that draft then (`TranslatableWords`). It is out of date once the draft of
 * that language no longer has the same words, and up to date again when it is
 * put back as it was. Never kept as a status: compared on read, with the
 * source as it is now.
 *
 * The header of a page is followed like a section: a hash of its words is
 * recorded beside the sections, and a translation recorded without one (before
 * the header was followed, or a content that has no header) follows none.
 *
 * Neutral to the family of the content: it works on targets, whatever they
 * hold, and the caller says where the source of a language is.
 */
final readonly class TranslationFreshness
{
    public function __construct(
        private TranslatableWords $words,
    ) {
    }

    /**
     * The draft of a source, with its fingerprint: null when the language
     * holds no draft, or one with no section.
     */
    public function read(ContentTarget $source): ?TranslationSource
    {
        $document = Document::fromJson($source->row()->draftDocument());

        return null === $document || $document->isEmpty() ? null : $this->source($document, $source->row()->headerWords());
    }

    /**
     * @param array<string, string|null>|null $header the words of the header of the content, by column; null when it has none to follow
     */
    public function source(Document $document, ?array $header = null): TranslationSource
    {
        return new TranslationSource($document, $this->words->fingerprint($document), null === $header ? null : $this->words->headerFingerprint($header));
    }

    /**
     * Ties a content to the draft it was written from, as it was read. The
     * content must be saved: a language is not followed before it holds
     * something.
     */
    public function record(ContentTarget $target, string $sourceLocale, TranslationSource $source): void
    {
        if ($sourceLocale === $target->key()->locale || $target->row()->isNew()) {
            return;
        }

        $target->row()->followSource($sourceLocale, self::encode($source->fingerprint, $source->header));
    }

    /**
     * A recorded fingerprint with the header of the source replaced by the one
     * given: what the copy of a translation records, being a translation of the
     * copy of its source, whose title is not the one of the original. A
     * fingerprint that follows no header is returned as it is.
     *
     * @param array<string, string|null> $header the words of the header of the copy of the source
     */
    public function withHeader(string $recorded, array $header): string
    {
        $sections = self::decode($recorded);

        return null === $sections || null === self::decodeHeader($recorded) ? $recorded : self::encode($sections, $this->words->headerFingerprint($header));
    }

    /**
     * Ties a content to the draft its source holds now: a translation the
     * editor brought up to date by hand, or one written by hand from the
     * start. False when it is not possible: the same language, a source with
     * nothing in it, a content with nothing in it.
     */
    public function follow(ContentTarget $target, ContentTarget $source): bool
    {
        $sourceLocale = $source->key()->locale;
        $current = $this->read($source);
        $written = Document::fromJson($target->row()->draftDocument());

        if ($sourceLocale === $target->key()->locale || null === $current || null === $written || $written->isEmpty()) {
            return false;
        }

        $this->record($target, $sourceLocale, $current);

        return true;
    }

    /**
     * What a content follows, and whether that changed: null when it follows
     * nothing, or something this version does not read. A source that holds
     * nothing now is not a change worth reporting.
     *
     * @param \Closure(string): ContentTarget $sourceOf the target of the same content in another language
     */
    public function link(ContentTarget $target, \Closure $sourceOf): ?TranslationLink
    {
        $followed = $target->row()->followedSource();
        $recorded = null === $followed ? null : self::decode($followed['fingerprint']);

        if (null === $followed || null === $recorded) {
            return null;
        }

        $current = $this->read($sourceOf($followed['locale']));

        return $this->compare($followed['locale'], $recorded, $current, self::decodeHeader($followed['fingerprint']));
    }

    /**
     * @param array<string, string> $recorded       the hash of each section when the translation was written
     * @param string|null           $recordedHeader the hash of the header then; null for a translation recorded before the header was followed, which follows none: it is never stale for it
     */
    public function compare(string $sourceLocale, array $recorded, ?TranslationSource $current, ?string $recordedHeader = null): TranslationLink
    {
        if (null === $current) {
            return new TranslationLink($sourceLocale);
        }

        $differences = $this->words->differences($recorded, $current->fingerprint);
        $header = null !== $recordedHeader && null !== $current->header && $recordedHeader !== $current->header;

        return new TranslationLink($sourceLocale, $differences['changed'], $differences['removed'], $header);
    }

    /**
     * @param array<string, string> $fingerprint the hash of each section
     * @param string|null           $header      the hash of the header; none for a content that has no header to follow, which records the sections alone
     */
    public static function encode(array $fingerprint, ?string $header = null): string
    {
        return json_encode(null === $header ? ['sections' => $fingerprint] : ['sections' => $fingerprint, 'header' => $header], \JSON_THROW_ON_ERROR);
    }

    /**
     * The hash of the header a fingerprint was recorded with: null when it
     * holds none (a content with no header, or a translation recorded before
     * the header was followed) or when it is not a hash.
     */
    public static function decodeHeader(?string $json): ?string
    {
        if (null === $json || '' === $json) {
            return null;
        }

        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) && \is_string($data['header'] ?? null) && '' !== $data['header'] ? $data['header'] : null;
    }

    /**
     * @return array<string, string>|null null for anything that is not a map of sections to hashes
     */
    public static function decode(?string $json): ?array
    {
        if (null === $json || '' === $json) {
            return null;
        }

        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data) || !\is_array($data['sections'] ?? null)) {
            return null;
        }

        $fingerprint = [];

        foreach ($data['sections'] as $id => $hash) {
            if (!\is_string($hash)) {
                return null;
            }

            $fingerprint[(string) $id] = $hash;
        }

        return $fingerprint;
    }
}
