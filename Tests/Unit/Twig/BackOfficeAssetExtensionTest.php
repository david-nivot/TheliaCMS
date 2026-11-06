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

namespace TheliaCMS\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use TheliaCMS\Twig\BackOfficeAssetExtension;

/**
 * The copy of an asset the site serves follows the file of the installed module.
 */
final class BackOfficeAssetExtensionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/cms-asset-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testACopyOfTheVersionBeforeIsReplaced(): void
    {
        $source = $this->file('source.js', 'console.log("2.14.0");', '2026-11-06 10:00:00');
        $served = $this->file('served.js', 'console.log("2.13.0");', '2026-10-30 10:00:00');

        self::assertTrue(BackOfficeAssetExtension::refresh($source, $served));
        self::assertFileEquals($source, $served);
        clearstatcache();
        self::assertSame(filemtime($source), filemtime($served), 'The copy takes the date of its source, which the next page compares.');
        self::assertSame([basename($served), basename($source)], $this->files(), 'Nothing is left aside.');
    }

    public function testACopyOfTheSameSizeIsReplacedWhenItsDateDiffers(): void
    {
        $source = $this->file('source.css', 'a{color:red}', '2026-11-06 10:00:00');
        $served = $this->file('served.css', 'a{color:red}', '2026-11-06 11:00:00');

        self::assertTrue(BackOfficeAssetExtension::refresh($source, $served), 'An older source, from an archive, counts as well.');
    }

    public function testACopyUpToDateIsLeftAsItIs(): void
    {
        $source = $this->file('source.js', 'console.log("2.14.0");', '2026-11-06 10:00:00');
        $served = $this->file('served.js', 'console.log("2.14.0");', '2026-11-06 10:00:00');

        self::assertFalse(BackOfficeAssetExtension::refresh($source, $served));
    }

    public function testNothingIsWrittenWithoutASourceOrACopy(): void
    {
        $served = $this->file('served.js', 'console.log("2.13.0");', '2026-10-30 10:00:00');

        self::assertFalse(BackOfficeAssetExtension::refresh($this->directory.'/missing.js', $served));
        self::assertFalse(BackOfficeAssetExtension::refresh($served, $this->directory.'/missing.js'), 'The first copy is the core\'s.');
        self::assertSame([basename($served)], $this->files());
    }

    private function file(string $name, string $content, string $date): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, $content);
        touch($path, (int) strtotime($date));

        return $path;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
    }
}
