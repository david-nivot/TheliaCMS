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

namespace TheliaCMS\Tests\Unit\Page\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheliaCMS\Page\Document\PageDocumentBlock;
use TheliaCMS\Page\Document\PageDocumentLink;
use TheliaCMS\Page\Document\PageDocumentSize;
use TheliaCMS\TheliaCMS;

/**
 * What a visitor reads of a file (its size, whether it is saved or opened)
 * is computed once, in PHP, so that every theme prints the same thing.
 */
final class PageDocumentBlockTest extends TestCase
{
    private const array UNITS = ['byte' => 'B', 'kilobyte' => 'KB', 'megabyte' => 'MB'];

    /**
     * @return iterable<string, array{int, ?string}>
     */
    public static function sizes(): iterable
    {
        yield 'unknown' => [0, null];
        yield 'negative' => [-5, null];
        yield 'one byte' => [1, '1 B'];
        yield 'last byte' => [1023, '1023 B'];
        yield 'one kilobyte' => [1024, '1 KB'];
        yield 'a kilobyte and a half' => [1536, '1.5 KB'];
        yield 'rounded to one decimal' => [1100, '1.1 KB'];
        yield 'last kilobyte rounds up' => [1_048_575, '1024 KB'];
        yield 'one megabyte' => [1_048_576, '1 MB'];
        yield 'megabytes' => [2_516_582, '2.4 MB'];
    }

    #[DataProvider('sizes')]
    public function testSizeIsReadable(int $bytes, ?string $expected): void
    {
        self::assertSame($expected, PageDocumentSize::readable($bytes, self::UNITS));
    }

    public function testUnitsAreTheOnesGiven(): void
    {
        self::assertSame('1.5 Ko', PageDocumentSize::readable(1536, ['byte' => 'o', 'kilobyte' => 'Ko', 'megabyte' => 'Mo']));
    }

    public function testContextCarriesWhatTheTemplateDraws(): void
    {
        $context = $this->block()->context([
            new PageDocumentLink(7, 'Notice', 'Mode d\'emploi', '/cms/documents/7/notice-ab12cd34.pdf', 'pdf', 1536, 'Notice produit.pdf'),
            new PageDocumentLink(8, 'Tarifs', null, '/cms/documents/8/tarifs-ab12cd34.zip', 'zip', 0, 'Tarifs.zip'),
        ], 'fr_FR');

        self::assertSame([
            [
                'id' => 7,
                'title' => 'Notice',
                'description' => 'Mode d\'emploi',
                'url' => '/cms/documents/7/notice-ab12cd34.pdf',
                'extension' => 'pdf',
                'type' => 'PDF',
                'size' => '1.5 KB@fr_FR',
                'bytes' => 1536,
                'download' => false,
                'originalName' => 'Notice produit.pdf',
            ],
            [
                'id' => 8,
                'title' => 'Tarifs',
                'description' => null,
                'url' => '/cms/documents/8/tarifs-ab12cd34.zip',
                'extension' => 'zip',
                'type' => 'ZIP',
                'size' => null,
                'bytes' => 0,
                'download' => true,
                'originalName' => 'Tarifs.zip',
            ],
        ], $context['documents']);
        self::assertSame('Documents to download@fr_FR', $context['heading']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function extensions(): iterable
    {
        yield 'pdf opens' => ['pdf', false];
        yield 'txt opens' => ['txt', false];
        yield 'upper case pdf opens' => ['PDF', false];
        yield 'docx is saved' => ['docx', true];
        yield 'zip is saved' => ['zip', true];
    }

    #[DataProvider('extensions')]
    public function testOnlyAPdfOrATextOpensInTheTab(string $extension, bool $download): void
    {
        $context = $this->block()->context([new PageDocumentLink(1, 'A', null, '/a', $extension, 10, 'a')], 'en_US');

        self::assertSame($download, $context['documents'][0]['download']);
    }

    private function block(): PageDocumentBlock
    {
        // Echoes the message and the locale it was asked in: the texts are
        // translated in the language of the page, not of the request.
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                if (TheliaCMS::DOMAIN_NAME !== $domain) {
                    throw new \LogicException('Wrong domain: '.$domain);
                }

                return $id.'@'.$locale;
            }

            public function getLocale(): string
            {
                return 'en_US';
            }
        };

        return new PageDocumentBlock($translator);
    }
}
