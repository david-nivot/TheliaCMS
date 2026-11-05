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

namespace TheliaCMS\Tests\Unit\Form;

use PHPUnit\Framework\TestCase;
use TheliaCMS\Form\CountryChoices;
use TheliaCMS\Form\FieldType;

/**
 * A list of countries is read by its first letter: an accented name sorted
 * after Z is a country the visitor does not find.
 */
final class CountryChoicesTest extends TestCase
{
    public function testSortsTheNamesTheWayTheLanguageReadsThem(): void
    {
        if (!class_exists(\Collator::class)) {
            self::markTestSkipped('The intl extension sorts by language; without it the order is the bytes of the names.');
        }

        self::assertSame(
            ['Allemagne', 'Égypte', 'Espagne', 'France', 'Zambie'],
            CountryChoices::sorted(['Zambie', 'Égypte', 'France', 'Allemagne', 'Espagne'], 'fr_FR'),
        );
    }

    public function testNamesACountryLeftUntranslatedFromItsIsoCode(): void
    {
        if (!class_exists(\Locale::class)) {
            self::markTestSkipped('The intl extension names the countries.');
        }

        self::assertSame('Germania', CountryChoices::regionName('de', 'it_IT'));
        self::assertSame('', CountryChoices::regionName('ZZ', 'it_IT'));
        self::assertSame('', CountryChoices::regionName('XY', 'it_IT'));
        self::assertSame('', CountryChoices::regionName('', 'it_IT'));
    }

    public function testACountryFieldIsADropDownWithoutAWrittenList(): void
    {
        self::assertTrue(FieldType::Country->isDropDown());
        self::assertTrue(FieldType::Select->isDropDown());
        self::assertFalse(FieldType::Radio->isDropDown());
        self::assertFalse(FieldType::Country->hasChoices());
    }
}
