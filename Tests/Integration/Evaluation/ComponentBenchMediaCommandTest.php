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

namespace TheliaCMS\Tests\Integration\Evaluation;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use TheliaCMS\Evaluation\ComponentBench\ComponentBenchMediaCommand;
use TheliaCMS\Media\CmsMediaLibrary;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaLibrary\Model\LibraryImage;
use TheliaLibrary\TheliaLibrary;

/**
 * `thelia_cms:component-bench:media`: the files of the folder of the bench
 * reach the media library through the service of TheliaLibrary, once each,
 * and a dry run writes nothing.
 */
final class ComponentBenchMediaCommandTest extends CmsIntegrationTestCase
{
    private string $directory;

    /** @var list<string> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!\function_exists('imagepng')) {
            self::markTestSkipped('GD is needed to draw the test pictures.');
        }

        $this->directory = THELIA_ROOT.'var/tests/component-bench-media/'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0o775, true);

        foreach (['workshop-landscape.png' => [160, 90], 'logo-north.png' => [40, 20]] as $file => [$width, $height]) {
            imagepng(imagecreatetruecolor($width, $height), $this->directory.'/'.$file);
        }

        file_put_contents($this->directory.'/manifest.json', json_encode(['media' => [
            ['file' => 'workshop-landscape.png', 'kind' => 'image', 'title' => 'Atelier', 'alt' => 'Établi d\'atelier avec des outils', 'width' => 160, 'height' => 90],
            ['file' => 'logo-north.png', 'kind' => 'image', 'title' => 'Logo Nord', 'alt' => 'Logo de la marque Nord', 'width' => 40, 'height' => 20],
        ]], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        // Files the library stored outside the transaction.
        foreach ($this->getService(CmsMediaLibrary::class)->images() as $image) {
            $this->stored[] = TheliaLibrary::getImageDirectory().$image->getFileName();
        }

        foreach ($this->stored as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        (new Filesystem())->remove($this->directory);

        parent::tearDown();
    }

    public function testTheFilesOfTheManifestAreImportedWithTheirTitleAndAltAndTheirIdsAreSaid(): void
    {
        [$status, $output] = $this->play(['--dir' => $this->directory]);

        $images = $this->getService(CmsMediaLibrary::class)->images();
        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(2, $images, 'Both are in the library the CMS offers.');

        $byTitle = [];

        foreach ($images as $image) {
            $image->setLocale($this->getService(CmsMediaLibrary::class)->locale());
            $byTitle[(string) $image->getTitle()] = $image;
        }

        ksort($byTitle);
        self::assertSame(['Atelier', 'Logo Nord'], array_keys($byTitle));
        self::assertSame('Établi d\'atelier avec des outils', $byTitle['Atelier']->getAlt());
        self::assertSame('Logo de la marque Nord', $byTitle['Logo Nord']->getAlt());
        self::assertSame([160, 90], [$byTitle['Atelier']->getWidth(), $byTitle['Atelier']->getHeight()], 'Measured by the library.');
        self::assertFalse($byTitle['Atelier']->isVideo());
        self::assertFileExists(TheliaLibrary::getImageDirectory().$byTitle['Atelier']->getFileName(), 'Stored where the library serves it from.');
        self::assertFileExists($this->directory.'/workshop-landscape.png', 'The folder of the bench keeps its files.');
        self::assertStringContainsString(\sprintf('workshop-landscape.png: imported (id %d)', $byTitle['Atelier']->getId()), $output);
        self::assertStringContainsString(\sprintf('logo-north.png: imported (id %d)', $byTitle['Logo Nord']->getId()), $output);
        self::assertStringContainsString('2 imported, 0 already in the library.', $output);
    }

    public function testRunTwiceItImportsNothingTheSecondTime(): void
    {
        $this->play(['--dir' => $this->directory]);
        $first = $this->ids();

        [$status, $output] = $this->play(['--dir' => $this->directory]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($first, $this->ids(), 'The same rows, no copy.');
        self::assertStringContainsString('0 imported, 2 already in the library.', $output);
        self::assertStringContainsString(\sprintf('logo-north.png: already in the library (id %d)', $first[0]), $output);
    }

    public function testAMediaOfTheSameTitleIsNotImportedAgain(): void
    {
        $this->play(['--dir' => $this->directory]);
        // A third file, the manifest being extended: only it is new.
        imagepng(imagecreatetruecolor(30, 30), $this->directory.'/logo-east.png');
        $manifest = json_decode((string) file_get_contents($this->directory.'/manifest.json'), true, 8, \JSON_THROW_ON_ERROR);
        $manifest['media'][] = ['file' => 'logo-east.png', 'kind' => 'image', 'title' => 'Logo Est', 'alt' => 'Logo de la marque Est'];
        file_put_contents($this->directory.'/manifest.json', json_encode($manifest, \JSON_THROW_ON_ERROR));

        [, $output] = $this->play(['--dir' => $this->directory]);

        self::assertCount(3, $this->ids());
        self::assertStringContainsString('1 imported, 2 already in the library.', $output);
    }

    public function testADryRunSaysWhatItWouldDoAndWritesNothing(): void
    {
        $before = glob(TheliaLibrary::getImageDirectory().'*') ?: [];

        [$status, $output] = $this->play(['--dir' => $this->directory, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame([], $this->ids(), 'Nothing in the library.');
        self::assertSame($before, glob(TheliaLibrary::getImageDirectory().'*') ?: [], 'No file stored.');
        self::assertStringContainsString('workshop-landscape.png: would be imported', $output);
        self::assertStringContainsString('2 to import, 0 already in the library.', $output);
    }

    public function testADryRunAfterAnImportFindsEverythingAlreadyThere(): void
    {
        $this->play(['--dir' => $this->directory]);

        [, $output] = $this->play(['--dir' => $this->directory, '--dry-run' => true]);

        self::assertStringContainsString('0 to import, 2 already in the library.', $output);
    }

    public function testAFileThatTheManifestNamesAndTheFolderLacksStopsBeforeAnyImport(): void
    {
        unlink($this->directory.'/logo-north.png');

        [$status, $output] = $this->play(['--dir' => $this->directory]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('logo-north.png is not in the folder', $output);
        self::assertSame([], $this->ids(), 'The first file was not imported either.');
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        return array_map(static fn (LibraryImage $image): int => (int) $image->getId(), $this->getService(CmsMediaLibrary::class)->images());
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, string}
     */
    private function play(array $options): array
    {
        $tester = new CommandTester($this->getService(ComponentBenchMediaCommand::class));
        $tester->execute($options, ['capture_stderr_separately' => false]);

        return [$tester->getStatusCode(), $tester->getDisplay()];
    }
}
