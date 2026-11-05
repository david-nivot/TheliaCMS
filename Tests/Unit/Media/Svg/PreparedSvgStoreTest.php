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
use TheliaCMS\Media\Svg\PreparedSvgStore;

final class PreparedSvgStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/theliacms-store-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testAFileIsFoundAgainByItsTokenWithItsNameAndItsBytes(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');

        $token = $store->put('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg');
        $file = $store->find($token);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        self::assertNotNull($file);
        self::assertSame('logo.svg', $file->name);
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"/>', $file->content);
    }

    public function testAFileOlderThanAnHourIsGoneAndRemovedWhenItIsRead(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
        $token = $store->put('<svg/>', 'vieux.svg');
        touch($this->root.'/waiting/'.$token.'.svg', time() - PreparedSvgStore::LIFETIME_IN_SECONDS - 5);

        self::assertNull($store->find($token));
        self::assertFileDoesNotExist($this->root.'/waiting/'.$token.'.svg');
        self::assertFileDoesNotExist($this->root.'/waiting/'.$token.'.name');
    }

    public function testAFileJustUnderAnHourIsStillThere(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
        $token = $store->put('<svg/>', 'recent.svg');
        touch($this->root.'/waiting/'.$token.'.svg', time() - PreparedSvgStore::LIFETIME_IN_SECONDS + 60);

        self::assertNotNull($store->find($token));
    }

    public function testWritingPurgesTheFilesNobodyCameBackFor(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
        $old = $store->put('<svg/>', 'oublie.svg');
        touch($this->root.'/waiting/'.$old.'.svg', time() - 2 * PreparedSvgStore::LIFETIME_IN_SECONDS);

        $fresh = $store->put('<svg/>', 'neuf.svg');

        self::assertFileDoesNotExist($this->root.'/waiting/'.$old.'.svg');
        self::assertFileDoesNotExist($this->root.'/waiting/'.$old.'.name');
        self::assertNotNull($store->find($fresh));
    }

    public function testAPreparedResultIsKeptForItsSettingAndGoesWithItsFile(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
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
        $store = new PreparedSvgStore($this->root.'/waiting');
        $token = $store->put('<svg/>', 'a.svg');

        $store->remember($token, '../../escape', '<svg/>');
        $store->remember(str_repeat('a', 32), 'base', '<svg/>');

        self::assertSame([], glob($this->root.'/*.result') ?: []);
        self::assertSame([], glob($this->root.'/waiting/'.str_repeat('a', 32).'.*'), 'No result for a file that is not waiting.');
        self::assertNull($store->result($token, '../../escape'));
    }

    public function testAResultOfAnExpiredFileIsNotRead(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
        $token = $store->put('<svg/>', 'a.svg');
        $store->remember($token, 'base', '<svg>old</svg>');
        touch($this->root.'/waiting/'.$token.'.svg', time() - 2 * PreparedSvgStore::LIFETIME_IN_SECONDS);

        self::assertNull($store->result($token, 'base'));
    }

    public function testWritingPurgesWhatAWriteLeftHalfway(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
        $store->put('<svg/>', 'a.svg');
        file_put_contents($this->root.'/waiting/'.str_repeat('b', 32).'.name', 'orphan.svg');
        touch($this->root.'/waiting/'.str_repeat('b', 32).'.name', time() - 2 * PreparedSvgStore::LIFETIME_IN_SECONDS);

        $store->put('<svg/>', 'b.svg');

        self::assertFileDoesNotExist($this->root.'/waiting/'.str_repeat('b', 32).'.name');
    }

    public function testAWaitingFileIsTakenOnceAndOnlyOnce(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
        $token = $store->put('<svg/>', 'double.svg');

        self::assertSame('double.svg', $store->claim($token)?->name);
        self::assertNull($store->claim($token), 'A second answer finds the file taken.');
        self::assertNull($store->find($token));

        $store->forget($token);

        self::assertSame([], glob($this->root.'/waiting/'.$token.'.*'), 'Nothing of the file is left.');
    }

    public function testAForgottenFileIsGone(): void
    {
        $store = new PreparedSvgStore($this->root.'/waiting');
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
        $store = new PreparedSvgStore($this->root.'/waiting');

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
}
