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

        return null === $document || $document->isEmpty() ? null : $this->source($document);
    }

    public function source(Document $document): TranslationSource
    {
        return new TranslationSource($document, $this->words->fingerprint($document));
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

        $target->row()->followSource($sourceLocale, self::encode($source->fingerprint));
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

        return $this->compare($followed['locale'], $recorded, $current);
    }

    /**
     * @param array<string, string> $recorded
     */
    public function compare(string $sourceLocale, array $recorded, ?TranslationSource $current): TranslationLink
    {
        if (null === $current) {
            return new TranslationLink($sourceLocale);
        }

        $differences = $this->words->differences($recorded, $current->fingerprint);

        return new TranslationLink($sourceLocale, $differences['changed'], $differences['removed']);
    }

    /**
     * @param array<string, string> $fingerprint
     */
    public static function encode(array $fingerprint): string
    {
        return json_encode(['sections' => $fingerprint], \JSON_THROW_ON_ERROR);
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
