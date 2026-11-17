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

namespace TheliaCMS\Tests\Unit\Media\Svg;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use TheliaCMS\Media\Svg\CacheCeiling;

final class CacheCeilingTest extends TestCase
{
    private const string ENTRY_PATTERN = '/^[a-f0-9]{4}\z/';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/theliacms-ceiling-test-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testTheEntriesUsedLeastRecentlyLeaveFirstAndAReadFileIsNotOneOfThem(): void
    {
        $this->write('aaaa.one', 40, 300);
        $this->write('bbbb.one', 40, 200);
        $this->write('cccc.one', 40, 100);
        // The oldest one is read: its modification time is refreshed, as the caches do.
        touch($this->root.'/aaaa.one');

        $forgotten = [];
        $room = $this->ceiling()->makeRoom($this->root, 130, 40, 'dddd', static function (string $entry) use (&$forgotten): void {
            $forgotten[] = $entry;
        });

        self::assertTrue($room);
        self::assertSame(['bbbb'], $forgotten, 'The entry nobody read leaves, and only it.');
    }

    public function testAllTheFilesOfAnEntryCountTogetherAndTheMostRecentOneRanksIt(): void
    {
        $this->write('aaaa.one', 30, 500);
        $this->write('aaaa.two', 30, 10);
        $this->write('bbbb.one', 30, 100);

        $forgotten = [];
        $this->ceiling()->makeRoom($this->root, 100, 30, 'dddd', static function (string $entry) use (&$forgotten): void {
            $forgotten[] = $entry;
        });

        self::assertSame(['bbbb'], $forgotten, 'The entry aaaa was used ten seconds ago through one of its files.');
    }

    public function testAFileWhoseNameStartsWithADotIsNeitherCountedNorRemoved(): void
    {
        $this->write('.gitkeep', 500, 1000);
        $this->write('.checked', 500, 1000);
        $this->write('aaaa.one', 10, 100);

        $forgotten = [];
        $room = $this->ceiling()->makeRoom($this->root, 100, 20, 'dddd', static function (string $entry) use (&$forgotten): void {
            $forgotten[] = $entry;
        });

        self::assertTrue($room);
        self::assertSame([], $forgotten);
    }

    public function testAFileOfAnotherFormIsNeitherCountedNorRemoved(): void
    {
        $this->write('notes.txt', 500, 1000);
        $this->write('zz', 500, 1000);
        $this->write('aaaa.one', 10, 100);

        $forgotten = [];
        $room = $this->ceiling()->makeRoom($this->root, 100, 20, 'dddd', static function (string $entry) use (&$forgotten): void {
            $forgotten[] = $entry;
        });

        self::assertTrue($room, 'What cannot be removed does not make the room false.');
        self::assertSame([], $forgotten);
    }

    public function testTheEntryBeingWrittenNeverLeavesEvenWhenItAloneIsOverTheCeiling(): void
    {
        $this->write('aaaa.one', 200, 100);
        $this->write('bbbb.one', 10, 50);

        $forgotten = [];
        $room = $this->ceiling()->makeRoom($this->root, 100, 20, 'aaaa', static function (string $entry) use (&$forgotten): void {
            $forgotten[] = $entry;
        });

        self::assertFalse($room);
        self::assertSame(['bbbb'], $forgotten, 'The others left, the one being written stayed.');
    }

    public function testACeilingOfZeroKeepsNothingButTheEntryBeingWritten(): void
    {
        $this->write('aaaa.one', 10, 100);
        $this->write('bbbb.one', 10, 50);

        $forgotten = [];
        $room = $this->ceiling()->makeRoom($this->root, 0, 1, 'bbbb', static function (string $entry) use (&$forgotten): void {
            $forgotten[] = $entry;
        });

        self::assertFalse($room);
        self::assertSame(['aaaa'], $forgotten);
    }

    public function testTheScanIsDueOncePerInterval(): void
    {
        $ceiling = new CacheCeiling(self::ENTRY_PATTERN, 60);

        self::assertTrue($ceiling->isScanDue($this->root), 'No marker yet: due.');
        self::assertFalse($ceiling->isScanDue($this->root));

        touch($this->root.'/.checked', time() - 61);

        self::assertTrue($ceiling->isScanDue($this->root));
        self::assertTrue((new CacheCeiling(self::ENTRY_PATTERN, 0))->isScanDue($this->root), 'An interval of 0 scans every time.');
    }

    public function testADirectoryThatVanishedHoldsNothingAndThrowsNothing(): void
    {
        $forgotten = [];

        $room = $this->ceiling()->makeRoom($this->root.'/gone', 100, 20, 'dddd', static function (string $entry) use (&$forgotten): void {
            $forgotten[] = $entry;
        });

        self::assertTrue($room);
        self::assertSame([], $forgotten);
        self::assertFalse($this->ceiling()->makeRoom($this->root.'/gone', 100, 200, 'dddd', static fn (string $entry) => null));
    }

    public function testRefreshingAFileThatIsGoneDoesNotCreateIt(): void
    {
        CacheCeiling::refresh($this->root.'/aaaa.one');

        self::assertFileDoesNotExist($this->root.'/aaaa.one');

        $this->write('bbbb.one', 5, 500);
        CacheCeiling::refresh($this->root.'/bbbb.one');
        clearstatcache();

        self::assertGreaterThan(time() - 30, filemtime($this->root.'/bbbb.one'));
    }

    private function ceiling(): CacheCeiling
    {
        return new CacheCeiling(self::ENTRY_PATTERN, 0);
    }

    private function write(string $name, int $bytes, int $secondsAgo): void
    {
        file_put_contents($this->root.'/'.$name, str_repeat('x', $bytes));
        touch($this->root.'/'.$name, time() - $secondsAgo);
    }
}
