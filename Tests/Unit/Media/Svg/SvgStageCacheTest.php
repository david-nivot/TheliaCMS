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
use TheliaCMS\Media\Svg\SvgStageCache;

final class SvgStageCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/theliacms-stages-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testTheStagesUsedLeastRecentlyLeaveToStayUnderTheCeiling(): void
    {
        $cache = new SvgStageCache($this->root, 100, 0);
        [$first, $second] = [SvgStageCache::key('first'), SvgStageCache::key('second')];
        $cache->put($first, 'base', str_repeat('a', 30));
        $cache->put($first, 'level-lossy-fine', str_repeat('a', 30));
        foreach (glob($this->root.'/'.$first.'.*') ?: [] as $path) {
            touch($path, time() - 100);
        }

        $cache->put($second, 'base', str_repeat('b', 60));

        self::assertNull($cache->get($first, 'base'), 'Every stage of the oldest file left.');
        self::assertNull($cache->get($first, 'level-lossy-fine'));
        self::assertSame(str_repeat('b', 60), $cache->get($second, 'base'));
    }

    public function testAStageHeavierThanTheCeilingIsNotKept(): void
    {
        $cache = new SvgStageCache($this->root, 100, 0);
        $key = SvgStageCache::key('heavy');

        $cache->put($key, 'base', str_repeat('h', 150));

        self::assertNull($cache->get($key, 'base'));
    }

    public function testAStageThatWasReadIsTheLastToLeave(): void
    {
        $cache = new SvgStageCache($this->root, 100, 0);
        [$first, $second, $third] = [SvgStageCache::key('first'), SvgStageCache::key('second'), SvgStageCache::key('third')];
        $cache->put($first, 'base', str_repeat('a', 40));
        $this->age($first, 100);
        $cache->put($second, 'base', str_repeat('b', 40));
        $this->age($second, 50);

        // Written first, read last: no longer the one used least recently.
        self::assertNotNull($cache->get($first, 'base'));
        $cache->put($third, 'base', str_repeat('c', 40));

        self::assertNotNull($cache->get($first, 'base'), 'The stage that was read stays.');
        self::assertNull($cache->get($second, 'base'), 'The stage nobody read leaves.');
        self::assertNotNull($cache->get($third, 'base'));
    }

    public function testAReadStartsTheHourAgain(): void
    {
        $cache = new SvgStageCache($this->root, scanIntervalInSeconds: 0);
        $key = SvgStageCache::key('used');
        $cache->put($key, 'base', 'x');
        $this->age($key, 3500);

        self::assertSame('x', $cache->get($key, 'base'));

        clearstatcache();
        self::assertGreaterThan(time() - 30, filemtime($this->root.'/'.$key.'.base'));
    }

    public function testTheKeyChangesWithTheVersionOfTheStages(): void
    {
        self::assertSame(hash('sha256', SvgStageCache::VERSION.':same bytes'), SvgStageCache::key('same bytes'));
        self::assertNotSame(hash('sha256', 'same bytes'), SvgStageCache::key('same bytes'), 'The bytes alone are not the key any more.');
    }

    public function testTheDirectoryIsScannedOnceAnIntervalOnly(): void
    {
        $cache = new SvgStageCache($this->root, 100, 3600);
        [$first, $second, $third] = [SvgStageCache::key('first'), SvgStageCache::key('second'), SvgStageCache::key('third')];
        $cache->put($first, 'base', str_repeat('a', 60));
        $this->age($first, 100);
        $cache->put($second, 'base', str_repeat('b', 60));
        $cache->put($third, 'base', str_repeat('c', 60));

        // 180 bytes over a ceiling of 100: no scan since the first write, so nothing left yet.
        self::assertNotNull($cache->get($first, 'base'));
        self::assertNotNull($cache->get($second, 'base'));
        self::assertNotNull($cache->get($third, 'base'));
        self::assertFileExists($this->root.'/.checked');

        $cache->put(SvgStageCache::key('heavy'), 'base', str_repeat('h', 150));
        self::assertNull($cache->get(SvgStageCache::key('heavy'), 'base'), 'A stage over the ceiling by itself is refused without a scan.');
    }

    public function testTheScanComesAgainOnceTheIntervalIsOver(): void
    {
        $cache = new SvgStageCache($this->root, 100, 3600);
        [$first, $second] = [SvgStageCache::key('first'), SvgStageCache::key('second')];
        $cache->put($first, 'base', str_repeat('a', 60));
        $this->age($first, 100);
        touch($this->root.'/.checked', time() - 3601);

        $cache->put($second, 'base', str_repeat('b', 60));

        self::assertNull($cache->get($first, 'base'));
        self::assertNotNull($cache->get($second, 'base'));
    }

    public function testADiskThatRefusesAStageIsLoggedAndNotThrown(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };
        $cache = new SvgStageCache($this->root, scanIntervalInSeconds: 0, logger: $logger);
        $key = SvgStageCache::key('blocked');
        // A directory where the stage goes: the write cannot succeed.
        (new Filesystem())->mkdir($this->root.'/'.$key.'.base/inside');

        $cache->put($key, 'base', 'x');

        self::assertSame(['warning'], $logger->levels);
        self::assertNull($cache->get($key, 'base'));
    }

    public function testAnEmptyStageIsAMissNotAResult(): void
    {
        $cache = new SvgStageCache($this->root, scanIntervalInSeconds: 0);
        $key = SvgStageCache::key('emptied');
        $cache->put($key, 'base', 'x');
        file_put_contents($this->root.'/'.$key.'.base', '');

        self::assertNull($cache->get($key, 'base'));
    }

    public function testAReadOfAStageRemovedMeanwhileDoesNotCreateItAgain(): void
    {
        $cache = new SvgStageCache($this->root, scanIntervalInSeconds: 0);
        $key = SvgStageCache::key('evicted');
        $cache->put($key, 'base', 'x');
        unlink($this->root.'/'.$key.'.base');

        self::assertNull($cache->get($key, 'base'));
        self::assertFileDoesNotExist($this->root.'/'.$key.'.base');
    }

    private function age(string $key, int $seconds): void
    {
        foreach (glob($this->root.'/'.$key.'.*') ?: [] as $path) {
            touch($path, time() - $seconds);
        }
    }
}
