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

namespace TheliaCMS\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Content\Document;
use TheliaCMS\Content\Editing\TranslationFreshness;
use TheliaCMS\Content\Editing\TranslationSource;
use TheliaCMS\Content\SectionCatalog;
use TheliaCMS\Content\TranslatableWords;

/**
 * The header of a page is followed like a section: a fingerprint of its words
 * is recorded with the translation, and a translation recorded before the
 * header was followed is not made stale by it.
 */
final class TranslationHeaderFingerprintTest extends TestCase
{
    private const array HEADER = ['title' => 'Nos tarifs', 'chapo' => '<p>Court.</p>', 'description' => null, 'og_title' => '', 'og_description' => null];

    public function testTheHeaderFingerprintMovesWithAWordAndWithNothingElse(): void
    {
        $words = new TranslatableWords(new SectionCatalog([]));
        $before = $words->headerFingerprint(self::HEADER);

        self::assertSame($before, $words->headerFingerprint(array_reverse(self::HEADER, true)), 'The order the columns are given in is no word.');
        self::assertSame($before, $words->headerFingerprint([...self::HEADER, 'title' => '  Nos tarifs ', 'og_title' => null]), 'Blanks and absent are the same: nothing written.');
        self::assertNotSame($before, $words->headerFingerprint([...self::HEADER, 'title' => 'Nos tarifs, revus']));
        self::assertNotSame($before, $words->headerFingerprint([...self::HEADER, 'og_description' => 'Partagez']));
    }

    public function testTheHeaderIsRecordedBesideTheSectionsAndReadBack(): void
    {
        $encoded = TranslationFreshness::encode(['intro' => 'abc'], 'header-hash');

        self::assertSame(['intro' => 'abc'], TranslationFreshness::decode($encoded), 'The sections read as they always did.');
        self::assertSame('header-hash', TranslationFreshness::decodeHeader($encoded));
    }

    public function testAFingerprintRecordedWithoutHeaderStillReadsAndFollowsNoHeader(): void
    {
        $legacy = json_encode(['sections' => ['intro' => 'abc']], \JSON_THROW_ON_ERROR);

        self::assertSame(['intro' => 'abc'], TranslationFreshness::decode($legacy));
        self::assertNull(TranslationFreshness::decodeHeader($legacy));
        self::assertNull(TranslationFreshness::decodeHeader(null));
        self::assertNull(TranslationFreshness::decodeHeader('not json'));
        self::assertNull(TranslationFreshness::decodeHeader(json_encode(['sections' => [], 'header' => ['x']], \JSON_THROW_ON_ERROR)), 'A header that is not a hash is not followed.');
        self::assertSame(json_encode(['sections' => ['intro' => 'abc']], \JSON_THROW_ON_ERROR), TranslationFreshness::encode(['intro' => 'abc']), 'An item records what it always did.');
    }

    public function testAChangedHeaderMakesTheTranslationStaleAndIsNamedAsTheHeader(): void
    {
        $freshness = new TranslationFreshness(new TranslatableWords(new SectionCatalog([])));
        $source = new TranslationSource(new Document([]), [], 'now');

        $unchanged = $freshness->compare('fr_FR', [], new TranslationSource(new Document([]), [], 'now'), 'now');
        self::assertFalse($unchanged->isStale());
        self::assertFalse($unchanged->header);

        $changed = $freshness->compare('fr_FR', [], $source, 'before');
        self::assertTrue($changed->isStale(), 'A header that is not the one translated.');
        self::assertTrue($changed->header);
        self::assertSame([], $changed->changed, 'It is not a section.');
        self::assertSame([], $changed->removed);
    }

    public function testATranslationRecordedBeforeTheHeaderWasFollowedIsNotStaleForIt(): void
    {
        $freshness = new TranslationFreshness(new TranslatableWords(new SectionCatalog([])));

        $link = $freshness->compare('fr_FR', [], new TranslationSource(new Document([]), [], 'whatever the header is now'), null);

        self::assertFalse($link->isStale(), 'No recorded header: not followed for it, whatever it is now.');
        self::assertFalse($link->header);
    }

    public function testASourceWithNoHeaderToFollowIsNeverStaleForIt(): void
    {
        $freshness = new TranslationFreshness(new TranslatableWords(new SectionCatalog([])));

        self::assertFalse($freshness->compare('fr_FR', [], new TranslationSource(new Document([]), [], null), 'recorded')->isStale(), 'An item has no header: nothing to compare.');
    }
}
