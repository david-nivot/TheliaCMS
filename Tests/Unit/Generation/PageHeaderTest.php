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
use TheliaCMS\Generation\HeaderOutcome;
use TheliaCMS\Generation\PageHeader;

/**
 * The header of a page, as a translation reads it from its source and as it
 * comes back in the answer; and what a translation says it did with it.
 */
final class PageHeaderTest extends TestCase
{
    public function testAHeaderOfBlanksHoldsNothing(): void
    {
        self::assertTrue((new PageHeader())->isEmpty());
        self::assertTrue((new PageHeader('  ', "\n", '', null, ' '))->isEmpty());
        self::assertFalse((new PageHeader(ogDescription: 'Un résumé.'))->isEmpty());
    }

    public function testTheWordsAreTheNonEmptyColumnsTrimmedInTheOrderOfTheColumns(): void
    {
        $header = new PageHeader(' Nos tarifs ', null, '<p>Le détail.</p>', '', 'Partagez');

        self::assertSame(['title' => 'Nos tarifs', 'description' => '<p>Le détail.</p>', 'og_description' => 'Partagez'], $header->words());
        self::assertSame(['title', 'chapo', 'description', 'og_title', 'og_description'], PageHeader::COLUMNS);
    }

    public function testTheOutcomeOfATranslationTravelsInTheAddressOfTheScreenAndCannotBeForged(): void
    {
        $outcome = new HeaderOutcome(['title', 'chapo'], ['description'], HeaderOutcome::ADDRESS_RESERVED);

        $query = $outcome->toQuery();
        self::assertEquals($outcome, HeaderOutcome::fromQuery($query));
        self::assertSame($query, HeaderOutcome::fromQuery($query)?->toQuery());

        self::assertNull(HeaderOutcome::fromQuery(''));
        self::assertNull(HeaderOutcome::fromQuery('w-nothing,k-<script>,a-who-knows'), 'Only the columns of the header and the known states are read.');
        self::assertEquals(new HeaderOutcome(['title'], [], null), HeaderOutcome::fromQuery('w-title,w-<script>,k-,a-x'), 'What is not known is dropped, what is is kept.');
        self::assertTrue((new HeaderOutcome([], [], null))->isEmpty());
        self::assertFalse((new HeaderOutcome([], ['title'], null))->isEmpty(), 'What was kept is worth telling too.');
    }
}
