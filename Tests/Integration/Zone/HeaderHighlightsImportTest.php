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

namespace TheliaCMS\Tests\Integration\Zone;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Content\Document;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Model\CmsPageContentQuery;
use TheliaCMS\Model\CmsPageQuery;
use TheliaCMS\Model\Map\CmsPageContentTableMap;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\Zone\HeaderHighlightsImporter;
use TheliaCMS\Zone\HeaderZone;
use TheliaCMS\Zone\HeaderZoneThemeHook;
use TheliaCMS\Zone\ImportHeaderHighlightsCommand;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * The banner of the HeaderHighlights module copied into the header zone as a
 * draft. The tables of the module exist in the test database and are filled
 * here, inside the transaction of the test; its image files are written where
 * the module keeps them and removed afterwards, like the files the library
 * stores.
 */
final class HeaderHighlightsImportTest extends CmsIntegrationTestCase
{
    private const string LEAD = 'hh-import-test-lead.png';
    private const string LEAD_MOBILE = 'hh-import-test-lead-mobile.png';
    private const string SECOND = 'hh-import-test-second.png';
    private const string THIRD = 'hh-import-test-third.png';

    /** @var list<string> files written outside the transaction */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Another test of the suite points the library elsewhere and the read is cached for the run: the module's files go where this says.
        ConfigQuery::write('images_library_path', 'local/media/images');
    }

    protected function tearDown(): void
    {
        foreach ($this->getService(CmsMediaLibrary::class)->images(limit: \PHP_INT_MAX) as $image) {
            $this->files[] = TheliaLibrary::getImageDirectory().$image->getFileName();
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testADraftIsWrittenInEachLanguageWithTextBlocksInOrderAndTheirProps(): void
    {
        $this->banner();

        $output = $this->import([]);

        $en = $this->draftBlocks('en_US');
        self::assertCount(3, $en);
        self::assertSame(['Winter sale', 'New in', 'Gift cards'], array_column($en, 'title'), 'Ordered by block number, 1 being the lead, whatever the order of the rows.');
        self::assertSame('Up to 30% off', $en[0]['text']);
        self::assertSame('Discover', $en[0]['button_label']);
        self::assertSame('/sale', $en[0]['button_url']);
        self::assertSame('Soldes d\'hiver', $this->draftBlocks('fr_FR')[0]['title'], 'Each language its own texts.');
        self::assertSame('/soldes', $this->draftBlocks('fr_FR')[0]['button_url']);

        self::assertNull($this->draft('es_ES'), 'No text in Spanish: no draft.');
        self::assertNull($this->draft('it_IT'));
        self::assertStringContainsString('es_ES: skipped, HeaderHighlights holds no text', $output);
        self::assertStringContainsString('en_US: 3 block(s) written as a draft', $output);

        self::assertSame(sha1_file($this->hhFile(self::LEAD)), $this->sha1OfLibraryImage($en[0]['image']), 'The image of the block is the file of HeaderHighlights.');
        self::assertSame(sha1_file($this->hhFile(self::SECOND)), $this->sha1OfLibraryImage($en[1]['image']));
        self::assertSame($en[0]['image'], $this->draftBlocks('fr_FR')[0]['image'], 'An image does not depend on the language: one media item for both.');
    }

    public function testTheMobileImageOfTheSameBlockNumberIsMapped(): void
    {
        $this->banner();

        $this->import([]);

        $blocks = $this->draftBlocks('en_US');
        self::assertSame(sha1_file($this->hhFile(self::LEAD_MOBILE)), $this->sha1OfLibraryImage($blocks[0]['image_mobile']));
        self::assertNull($blocks[1]['image_mobile'] ?? null, 'No mobile row for block 2: nothing, the desktop image is drawn.');
        self::assertNull($blocks[2]['image_mobile'] ?? null);
    }

    public function testMediaAreCreatedOnceAndReusedWhenTheCommandRunsAgain(): void
    {
        $this->banner();

        $first = $this->import([]);
        self::assertStringContainsString('Images: 4 copied, 0 already in the library.', $first);
        $ids = array_column($this->draftBlocks('en_US'), 'image');

        $library = $this->getService(CmsMediaLibrary::class);
        self::assertCount(4, $library->images());
        $lead = $library->ownedImage((int) $ids[0]);
        self::assertInstanceOf(LibraryImage::class, $lead);
        self::assertSame('Winter sale', $lead->setLocale('en_US')->getTitle(), 'Titled after the block title of the default language.');

        $second = $this->import(['--replace' => true]);

        self::assertStringContainsString('Images: 0 copied, 4 already in the library.', $second);
        self::assertCount(4, $library->images(), 'No second copy.');
        self::assertSame($ids, array_column($this->draftBlocks('en_US'), 'image'), 'The draft points at the same media.');
    }

    public function testALanguageWithADraftIsSkippedAndTheOthersAreWritten(): void
    {
        $this->banner();
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, 'en_US', Document::richText('<p>Written by hand.</p>'));
        $before = $this->draft('en_US');

        $output = $this->import([]);

        self::assertSame($before, $this->draft('en_US'), 'The draft of the editor is left as it is.');
        self::assertStringContainsString('en_US: skipped, the zone holds a draft', $output);
        self::assertStringContainsString('--replace', $output);
        self::assertNotNull($this->draft('fr_FR'), 'The language without a draft is written.');
    }

    public function testReplaceWritesOverTheDraftAndKeepsItAsAPreviousDraft(): void
    {
        $this->banner();
        $zone = $this->getService(HeaderZone::class)->ensure();
        $this->writer()->saveContent($zone, 'en_US', Document::richText('<p>Written by hand.</p>'));

        $this->import(['--replace' => true]);

        self::assertCount(3, $this->draftBlocks('en_US'), 'The banner replaces the draft.');
        $kept = $this->writer()->previousDrafts($zone, 'en_US');
        self::assertCount(1, $kept, 'The draft it replaced is a revision of the content screen.');
        self::assertSame(1, $kept[0]['sections']);
        self::assertSame([], $this->writer()->previousDrafts($zone, 'fr_FR'), 'Nothing to keep where there was no draft.');
    }

    public function testADryRunWritesNothingAndSaysWhatWouldBeWritten(): void
    {
        $this->banner();
        $contents = CmsPageContentQuery::create()->count();

        $output = $this->import(['--dry-run' => true]);

        self::assertNull($this->getService(HeaderZone::class)->find(), 'No zone made.');
        self::assertSame($contents, CmsPageContentQuery::create()->count(), 'No draft.');
        self::assertSame([], $this->getService(CmsMediaLibrary::class)->images(), 'No media.');
        self::assertStringContainsString('en_US: 3 block(s) would be written as a draft', $output);
        self::assertStringContainsString('fr_FR: 3 block(s) would be written as a draft', $output);
        self::assertStringContainsString('Images: 4 to copy, 0 already in the library.', $output);
        self::assertStringContainsString('the texts of the mobile row differ from the desktop ones', $output, 'Every problem is printed.');
        self::assertStringContainsString('Dry run', $output);
    }

    public function testADryRunReportsADocumentTheZoneWouldRefuse(): void
    {
        $this->banner(longTitle: true);

        $tester = $this->execute(['--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"title" is longer', $tester->getDisplay());
        self::assertNull($this->getService(HeaderZone::class)->find());
    }

    public function testNothingIsPublished(): void
    {
        $this->banner();

        $this->import([]);

        $zone = $this->getService(HeaderZone::class)->find();
        self::assertNotNull($zone);
        self::assertSame([], $this->getService(HeaderZone::class)->publishedLocales($zone), 'Published in no language.');
        $content = $this->content('en_US');
        self::assertNotNull($content);
        self::assertNull($content->getPublishedHtml());
        self::assertNull($content->getPublishedDocument());
        self::assertSame('', $this->getService(HeaderZoneThemeHook::class)->render('layout.header.bottom', []), 'The zone is drawn nowhere.');
    }

    public function testATextTheSectionRefusesFailsTheCommandAndWritesNothingInThatLanguage(): void
    {
        $this->banner(longTitle: true);

        $tester = $this->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('"title" is longer', $tester->getDisplay());
        self::assertNull($this->draft('en_US'));
        self::assertNotNull($this->draft('fr_FR'), 'The other language is written.');
    }

    public function testEmptyTablesAreASuccessWithAMessageAndWriteNothing(): void
    {
        $tester = $this->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to import', $tester->getDisplay());
        self::assertNull($this->getService(HeaderZone::class)->find(), 'No zone made for nothing.');
    }

    public function testTheModuleStoresItsFilesWhereTheImporterLooks(): void
    {
        self::assertStringEndsWith('/media/images/headerHighlights', $this->getService(HeaderHighlightsImporter::class)->uploadDirectory());
    }

    /**
     * Three desktop blocks (inserted out of order, block 1 with a mobile twin
     * whose texts differ), in English and French.
     */
    private function banner(bool $longTitle = false): void
    {
        $this->image(self::LEAD, 3);
        $this->image(self::LEAD_MOBILE, 4);
        $this->image(self::SECOND, 5);
        $this->image(self::THIRD, 6);

        $this->block(2, 'desktop', self::SECOND, [
            'en_US' => ['New in', 'Fresh arrivals', 'See', '/new'],
            'fr_FR' => ['Nouveautés', 'Tout juste arrivé', 'Voir', '/nouveautes'],
        ]);
        $this->block(3, 'desktop', self::THIRD, [
            'en_US' => ['Gift cards', '', '', ''],
            'fr_FR' => ['Cartes cadeaux', '', '', ''],
        ]);
        $this->block(1, 'desktop', self::LEAD, [
            'en_US' => [$longTitle ? str_repeat('Long ', 20) : 'Winter sale', 'Up to 30% off', 'Discover', '/sale'],
            'fr_FR' => ['Soldes d\'hiver', 'Jusqu\'à -30 %', 'Découvrir', '/soldes'],
        ]);
        $this->block(1, 'mobile', self::LEAD_MOBILE, [
            'en_US' => ['Sale', '', '', ''],
        ]);
    }

    /**
     * @param array<string, array{0: string, 1: string, 2: string, 3: string}> $texts title, catchphrase, call to action, url by locale
     */
    private function block(int $number, string $type, string $file, array $texts): void
    {
        $connection = $this->getPropelConnection();
        $connection->prepare('INSERT INTO header_highlights (image_block, display_type, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([(string) $number, $type]);
        $id = (int) $connection->lastInsertId();

        foreach ($texts as $locale => [$title, $catchphrase, $action, $url]) {
            $connection->prepare('INSERT INTO header_highlights_i18n (id, locale, title, catchphrase, call_to_action, url) VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $locale, $title, $catchphrase, $action, $url]);
        }

        $connection->prepare('INSERT INTO header_highlights_image (header_highlights_id, file, visible, position, created_at, updated_at) VALUES (?, ?, 1, 1, NOW(), NOW())')->execute([$id, $file]);
    }

    /** A distinct image per width, so each file has its own content. */
    private function image(string $name, int $width): void
    {
        $path = $this->hhFile($name);

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }

        imagepng(imagecreatetruecolor($width, 2), $path);
        $this->files[] = $path;
    }

    private function hhFile(string $name): string
    {
        return $this->getService(HeaderHighlightsImporter::class)->uploadDirectory().\DIRECTORY_SEPARATOR.$name;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function execute(array $arguments): CommandTester
    {
        $tester = new CommandTester($this->getService(ImportHeaderHighlightsCommand::class));
        $tester->execute($arguments, ['capture_stderr_separately' => false]);

        return $tester;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function import(array $arguments): string
    {
        $tester = $this->execute($arguments);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        return $tester->getDisplay();
    }

    private function content(string $locale): ?\TheliaCMS\Model\CmsPageContent
    {
        $zone = CmsPageQuery::create()->filterByModel(HeaderZone::MODEL)->findOne();
        CmsPageContentTableMap::clearInstancePool();

        return null === $zone ? null : CmsPageContentQuery::create()->filterByPageId($zone->getId())->filterByLocale($locale)->findOne();
    }

    private function draft(string $locale): ?string
    {
        return $this->content($locale)?->getDraftDocument();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function draftBlocks(string $locale): array
    {
        $document = Document::fromJson($this->draft($locale));
        self::assertNotNull($document, \sprintf('A draft in %s.', $locale));
        self::assertCount(1, $document->sections);
        self::assertSame('cms:highlights', $document->sections[0]->component);

        /** @var list<array<string, mixed>> $blocks */
        $blocks = $document->sections[0]->props['blocks'];

        return $blocks;
    }

    private function sha1OfLibraryImage(mixed $id): string
    {
        $image = $this->getService(CmsMediaLibrary::class)->ownedImage((int) $id);
        self::assertInstanceOf(LibraryImage::class, $image, 'The block points at an image of the CMS library.');

        return (string) sha1_file(TheliaLibrary::getImageDirectory().$image->getFileName());
    }
}
