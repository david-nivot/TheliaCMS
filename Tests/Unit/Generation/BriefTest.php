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

namespace TheliaCMS\Tests\Unit\Generation;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Generation\Brief;
use TheliaCMS\Generation\ReferenceFile;

/**
 * The web pages a brief cites, which the model may read before it writes,
 * and the file it comes with when the editor joined one.
 */
final class BriefTest extends TestCase
{
    public function testTheAddressesABriefCitesAreFoundWithoutThePunctuationOfTheSentence(): void
    {
        $brief = new Brief('Reprends https://example.com/a-propos. Le ton de (http://example.org/blog?page=2), et https://example.com/a-propos encore !');

        self::assertSame(['https://example.com/a-propos', 'http://example.org/blog?page=2'], $brief->addresses());
    }

    public function testABriefWithoutAnAddressCitesNone(): void
    {
        self::assertSame([], (new Brief('Une page sur nos services, voir www.example.com plus tard.'))->addresses());
    }

    public function testTheAddressOfAPictureIsNotAPageToRead(): void
    {
        self::assertSame([], (new Brief('Ajoute la photo https://example.com/img/tarte.JPG?w=800 sous le titre.'))->addresses());
    }

    public function testABriefCitingAPageAndAPictureCitesThePageAlone(): void
    {
        $brief = new Brief('Reprends https://example.com/recettes/tarte et ajoute https://cdn.example.com/tarte.webp.');

        self::assertSame(['https://example.com/recettes/tarte'], $brief->addresses());
    }

    public function testABriefComesWithoutAFileUnlessOneIsJoined(): void
    {
        self::assertNull((new Brief('Nos services.'))->reference);

        $file = new ReferenceFile('fiche.md', '# Nos services');

        self::assertSame($file, (new Brief('Nos services.', null, null, $file))->reference);
    }

    public function testTheAddressesOfThePicturesAreTheOnesImportedAndNeverThePages(): void
    {
        $brief = new Brief('Import this image: https://example.com/a/banner.JPG, and read https://example.com/about. Again https://example.com/a/banner.JPG.');

        self::assertSame(['https://example.com/a/banner.JPG'], $brief->pictureAddresses());
        self::assertSame(['https://example.com/about'], $brief->addresses());
    }
}
