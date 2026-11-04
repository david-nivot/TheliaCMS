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

/**
 * Every problem a document can be refused for is shown to an editor in the
 * language of the back office: its template is in both catalogues, and the
 * French one is not the English one.
 */
final class DocumentProblemsCatalogueTest extends TestCase
{
    private const array SOURCES = [
        'Content/Document.php',
        'Content/DocumentValidator.php',
        'Content/ImageSection.php',
        'Content/PropSchema.php',
        'Content/SectionAppearance.php',
    ];

    public function testEveryProblemOfADocumentIsInBothCatalogues(): void
    {
        $root = \dirname(__DIR__, 3);
        $english = require $root.'/I18n/en_US.php';
        $french = require $root.'/I18n/fr_FR.php';
        $templates = [];

        foreach (self::SOURCES as $source) {
            preg_match_all("/TranslatableText::of\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($root.'/'.$source), $found);
            array_push($templates, ...array_map(static fn (string $template): string => stripslashes($template), $found[1]));
        }

        $templates = array_unique($templates);

        self::assertGreaterThanOrEqual(35, \count($templates), 'The templates are found in the sources.');

        foreach ($templates as $template) {
            self::assertArrayHasKey($template, $english, 'In English: '.$template);
            self::assertArrayHasKey($template, $french, 'In French: '.$template);
            self::assertNotSame($template, $french[$template], 'Translated: '.$template);
            self::assertSame(self::placeholders($template), self::placeholders($french[$template]), 'Same values: '.$template);
        }
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $sentence): array
    {
        preg_match_all('/%(?:\d+\$)?[sd]/', $sentence, $found);

        return $found[0];
    }
}
