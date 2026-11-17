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
        $cache = new SvgStageCache($this->root, 100);
        [$first, $second] = [SvgStageCache::key('first'), SvgStageCache::key('second')];
        $cache->put($first, 'base', str_repeat('a', 30));
        $cache->put($first, 'level-round-fine', str_repeat('a', 30));
        foreach (glob($this->root.'/'.$first.'.*') ?: [] as $path) {
            touch($path, time() - 100);
        }

        $cache->put($second, 'base', str_repeat('b', 60));

        self::assertNull($cache->get($first, 'base'), 'Every stage of the oldest file left.');
        self::assertNull($cache->get($first, 'level-round-fine'));
        self::assertSame(str_repeat('b', 60), $cache->get($second, 'base'));
    }

    public function testAStageHeavierThanTheCeilingIsNotKept(): void
    {
        $cache = new SvgStageCache($this->root, 100);
        $key = SvgStageCache::key('heavy');

        $cache->put($key, 'base', str_repeat('h', 150));

        self::assertNull($cache->get($key, 'base'));
    }
}
