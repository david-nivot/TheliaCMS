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

/**
 * The words of the dialog that crops an image are in the template, and each
 * one is in both catalogues, in French as well as English: a missing one would
 * show as English in a French back office, or as its key.
 */
final class ImageCropMessagesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function words(): array
    {
        $template = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/backOffice/default-twig/content/_image_crop.html.twig');
        preg_match_all("/: '((?:[^'\\\\]|\\\\.)*)'\\|trans/", $template, $found);

        return array_map(static fn (string $word): string => stripslashes($word), $found[1]);
    }

    public function testTheDialogHasItsWords(): void
    {
        self::assertGreaterThanOrEqual(20, \count(self::words()));
        self::assertContains('Choose an image to crop it.', self::words(), 'The reason the button is off with no image.');
        self::assertContains('The size of this image could not be read.', self::words(), 'The reason the button is off for an image of unknown size.');
    }

    public function testEveryWordIsTranslatedInBothCatalogues(): void
    {
        $root = \dirname(__DIR__, 3);
        $english = require $root.'/I18n/en_US.php';
        $french = require $root.'/I18n/fr_FR.php';

        foreach (self::words() as $word) {
            self::assertArrayHasKey($word, $english, 'English: '.$word);
            self::assertArrayHasKey($word, $french, 'French: '.$word);
            self::assertNotSame('', trim((string) $french[$word]));
        }

        self::assertSame('Recadrer l’image…', $french['Crop the image…']);
    }
}
