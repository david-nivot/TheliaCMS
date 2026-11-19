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
use Psr\Log\AbstractLogger;
use Symfony\Component\Filesystem\Filesystem;
use TheliaCMS\Media\Svg\PreparedSvgStore;

final class PreparedSvgStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/theliacms-store-test-'.bin2hex(random_bytes(4));
    }

    private function age(string $token, int $seconds): void
    {
        foreach (glob($this->root.'/waiting/'.$token.'.*') ?: [] as $path) {
            touch($path, time() - $seconds);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testAFileIsFoundAgainByItsTokenWithItsNameAndItsBytes(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);

        $token = $store->put('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg');
        $file = $store->find($token);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        self::assertNotNull($file);
        self::assertSame('logo.svg', $file->name);
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"/>', $file->content);
    }

    public function testTheFilesUsedLeastRecentlyLeaveToStayUnderTheCeiling(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', 100, 0);
        $first = $store->put(str_repeat('a', 40), 'a.svg');
        $this->age($first, 100);
        $second = $store->put(str_repeat('b', 40), 'b.svg');
        $this->age($second, 50);

        $third = $store->put(str_repeat('c', 40), 'c.svg');

        self::assertNull($store->find($first), 'The oldest file left.');
        self::assertNotNull($store->find($second));
        self::assertNotNull($store->find($third));
    }

    public function testAResultThatDoesNotFitIsNotKeptAndItsFileStays(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', 100, 0);
        $token = $store->put(str_repeat('a', 40), 'a.svg');

        $store->remember($token, 'base', str_repeat('r', 80));

        self::assertNull($store->result($token, 'base'));
        self::assertNotNull($store->find($token), 'The file being prepared is never the one that leaves.');
    }

    public function testAFileOlderThanAnHourIsGoneAndRemovedWhenItIsRead(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'vieux.svg');
        touch($this->root.'/waiting/'.$token.'.svg', time() - PreparedSvgStore::LIFETIME_IN_SECONDS - 5);

        self::assertNull($store->find($token));
        self::assertFileDoesNotExist($this->root.'/waiting/'.$token.'.svg');
        self::assertFileDoesNotExist($this->root.'/waiting/'.$token.'.name');
    }

    public function testAFileJustUnderAnHourIsStillThere(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'recent.svg');
        touch($this->root.'/waiting/'.$token.'.svg', time() - PreparedSvgStore::LIFETIME_IN_SECONDS + 60);

        self::assertNotNull($store->find($token));
    }

    public function testWritingPurgesTheFilesNobodyCameBackFor(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $old = $store->put('<svg/>', 'oublie.svg');
        touch($this->root.'/waiting/'.$old.'.svg', time() - 2 * PreparedSvgStore::LIFETIME_IN_SECONDS);

        $fresh = $store->put('<svg/>', 'neuf.svg');

        self::assertFileDoesNotExist($this->root.'/waiting/'.$old.'.svg');
        self::assertFileDoesNotExist($this->root.'/waiting/'.$old.'.name');
        self::assertNotNull($store->find($fresh));
    }

    public function testAPreparedResultIsKeptForItsSettingAndGoesWithItsFile(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'a.svg');

        $store->remember($token, 'base', '<svg>base</svg>');
        $store->remember($token, 'level-strong', '<svg>strong</svg>');

        self::assertSame('<svg>base</svg>', $store->result($token, 'base'));
        self::assertSame('<svg>strong</svg>', $store->result($token, 'level-strong'));
        self::assertNull($store->result($token, 'level-other'));

        $store->forget($token);

        self::assertNull($store->result($token, 'base'));
        self::assertSame([], glob($this->root.'/waiting/'.$token.'.*'), 'Nothing of the file is left.');
    }

    public function testAResultIsNeitherKeptNorReadOutsideTheStore(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'a.svg');

        $store->remember($token, '../../escape', '<svg/>');
        $store->remember(str_repeat('a', 32), 'base', '<svg/>');

        self::assertSame([], glob($this->root.'/*.result') ?: []);
        self::assertSame([], glob($this->root.'/waiting/'.str_repeat('a', 32).'.*'), 'No result for a file that is not waiting.');
        self::assertNull($store->result($token, '../../escape'));
    }

    public function testAResultOfAnExpiredFileIsNotRead(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'a.svg');
        $store->remember($token, 'base', '<svg>old</svg>');
        touch($this->root.'/waiting/'.$token.'.svg', time() - 2 * PreparedSvgStore::LIFETIME_IN_SECONDS);

        self::assertNull($store->result($token, 'base'));
    }

    public function testWritingPurgesWhatAWriteLeftHalfway(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $store->put('<svg/>', 'a.svg');
        file_put_contents($this->root.'/waiting/'.str_repeat('b', 32).'.name', 'orphan.svg');
        touch($this->root.'/waiting/'.str_repeat('b', 32).'.name', time() - 2 * PreparedSvgStore::LIFETIME_IN_SECONDS);

        $store->put('<svg/>', 'b.svg');

        self::assertFileDoesNotExist($this->root.'/waiting/'.str_repeat('b', 32).'.name');
    }

    public function testAWaitingFileIsTakenOnceAndOnlyOnce(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'double.svg');

        self::assertSame('double.svg', $store->claim($token)?->name);
        self::assertNull($store->claim($token), 'A second answer finds the file taken.');
        self::assertNull($store->find($token));

        $store->forget($token);

        self::assertSame([], glob($this->root.'/waiting/'.$token.'.*'), 'Nothing of the file is left.');
    }

    public function testAForgottenFileIsGone(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'a.svg');

        $store->forget($token);

        self::assertNull($store->find($token));
    }

    public function testATokenThatIsNotOneNeverReachesTheDisk(): void
    {
        // A file where a path traversal would land: it must survive.
        (new Filesystem())->mkdir($this->root.'/waiting');
        file_put_contents($this->root.'/victim.svg', 'keep me');
        file_put_contents($this->root.'/victim.name', 'keep me');
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);

        foreach (['../victim', '', 'ABCDEF0123456789ABCDEF0123456789', str_repeat('a', 31), str_repeat('a', 33), str_repeat('g', 32)] as $token) {
            self::assertFalse(PreparedSvgStore::isToken($token), $token);
            self::assertNull($store->find($token));
            $store->forget($token);
        }

        self::assertFileExists($this->root.'/victim.svg');
        self::assertFileExists($this->root.'/victim.name');
    }

    public function testAnUnknownTokenIsNullWithoutTheDirectoryExisting(): void
    {
        self::assertNull((new PreparedSvgStore($this->root.'/never-created'))->find(str_repeat('a', 32)));
        self::assertDirectoryDoesNotExist($this->root.'/never-created');
    }

    public function testAFileReadIsTheLastToLeaveWhenTheCeilingIsReached(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', 100, 0);
        $first = $store->put(str_repeat('a', 40), 'a.svg');
        $this->age($first, 100);
        $second = $store->put(str_repeat('b', 40), 'b.svg');
        $this->age($second, 50);

        // Written first, read last: it is no longer the one used least recently.
        self::assertNotNull($store->find($first));
        $third = $store->put(str_repeat('c', 40), 'c.svg');

        self::assertNotNull($store->find($first), 'The file that was read stays.');
        self::assertNull($store->find($second), 'The file nobody read leaves.');
        self::assertNotNull($store->find($third));
    }

    public function testAResultReadKeepsItsFileAmongTheLastToLeave(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', 100, 0);
        $first = $store->put(str_repeat('a', 20), 'a.svg');
        $store->remember($first, 'base', str_repeat('r', 10));
        $this->age($first, 100);
        $second = $store->put(str_repeat('b', 20), 'b.svg');
        $this->age($second, 50);

        // A read of the result refreshes the whole entry, not only its svg.
        self::assertNotNull($store->result($first, 'base'));
        $store->put(str_repeat('c', 40), 'c.svg');

        self::assertNotNull($store->find($first));
        self::assertNull($store->find($second));
    }

    public function testReadingAFileRefreshesItsNameToo(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'used.svg');
        $this->age($token, PreparedSvgStore::LIFETIME_IN_SECONDS - 60);

        self::assertNotNull($store->find($token));

        // The purge takes a name an hour old: the read must have refreshed it with its file.
        clearstatcache();
        self::assertGreaterThan(time() - 30, filemtime($this->root.'/waiting/'.$token.'.name'));
        self::assertGreaterThan(time() - 30, filemtime($this->root.'/waiting/'.$token.'.svg'));
    }

    public function testTheDirectoryIsScannedOnceAnIntervalAndAWriteBetweenTwoScansIsAccepted(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', 100, 3600);
        $first = $store->put(str_repeat('a', 40), 'a.svg');
        $this->age($first, 100);
        $second = $store->put(str_repeat('b', 40), 'b.svg');
        $third = $store->put(str_repeat('c', 40), 'c.svg');

        // 3 x 45 bytes over a ceiling of 100: no scan since the first write, nothing left.
        self::assertNotNull($store->find($first));
        self::assertNotNull($store->find($second));
        self::assertNotNull($store->find($third));
        self::assertFileExists($this->root.'/waiting/.checked');

        $store->remember($third, 'base', str_repeat('r', 200));
        self::assertNull($store->result($third, 'base'), 'A result over the ceiling by itself is refused without a scan.');
    }

    public function testAStrayFileAndTheMarkerAreNeitherCountedNorRemoved(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', 100, 0);
        (new Filesystem())->mkdir($this->root.'/waiting');
        file_put_contents($this->root.'/waiting/.gitkeep', str_repeat('k', 500));
        file_put_contents($this->root.'/waiting/notes.txt', str_repeat('n', 500));

        $token = $store->put(str_repeat('a', 40), 'a.svg');
        $store->remember($token, 'base', str_repeat('r', 40));

        self::assertSame(str_repeat('r', 40), $store->result($token, 'base'), 'The strays do not use the room.');
        self::assertFileExists($this->root.'/waiting/.gitkeep');
        self::assertFileExists($this->root.'/waiting/notes.txt');
    }

    public function testAResultTheDiskRefusesIsLoggedAndNotThrown(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0, logger: $logger);
        $token = $store->put('<svg/>', 'a.svg');
        // A directory where the result goes: the write cannot succeed.
        (new Filesystem())->mkdir($this->root.'/waiting/'.$token.'.base.result/inside');

        $store->remember($token, 'base', '<svg>x</svg>');

        self::assertSame(['warning'], $logger->levels);
    }

    public function testAnEmptyWaitingFileAndAnEmptyResultAreMisses(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'a.svg');
        $store->remember($token, 'base', '<svg>x</svg>');
        file_put_contents($this->root.'/waiting/'.$token.'.base.result', '');

        self::assertNull($store->result($token, 'base'));

        file_put_contents($this->root.'/waiting/'.$token.'.svg', '');

        self::assertNull($store->find($token));
    }

    public function testAResultRemovedMeanwhileIsNotCreatedAgainByItsRefresh(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'a.svg');
        $store->remember($token, 'base', '<svg>x</svg>');
        unlink($this->root.'/waiting/'.$token.'.base.result');

        self::assertNull($store->result($token, 'base'));
        self::assertFileDoesNotExist($this->root.'/waiting/'.$token.'.base.result');
    }

    public function testANameRemovedMeanwhileIsNotCreatedAgainByTheRefresh(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting', scanIntervalInSeconds: 0);
        $token = $store->put('<svg/>', 'a.svg');
        $this->age($token, 100);
        // The name is gone, as when a purge removed it meanwhile: it is not created again.
        unlink($this->root.'/waiting/'.$token.'.name');

        $file = $store->find($token);

        self::assertSame('', $file?->name);
        self::assertFileDoesNotExist($this->root.'/waiting/'.$token.'.name');
    }
}
