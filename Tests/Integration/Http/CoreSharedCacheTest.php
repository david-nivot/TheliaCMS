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

namespace TheliaCMS\Tests\Integration\Http;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\SharedCache\SharedCacheContributorInterface;
use Thelia\Core\SharedCache\SharedCacheInvalidationListener;
use Thelia\Core\SharedCache\SharedCacheKeyCollector;
use Thelia\Core\SharedCache\SharedCacheMode;
use Thelia\Core\SharedCache\SharedCachePolicy;
use Thelia\Core\SharedCache\SharedCachePurgerInterface;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Http\CachePurger;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Http\PageCacheListener;
use TheliaCMS\Page\ScheduledChanges;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * Once the shop turns the shared cache of the core on, the pages of the module are
 * shared by the core under the tags of the module, and every purge of the module
 * reaches the proxy of the core.
 */
final class CoreSharedCacheTest extends CmsIntegrationTestCase
{
    /** @var SharedCachePurgerInterface&object{calls: list<list<string>>} */
    private SharedCachePurgerInterface $purger;

    protected function setUp(): void
    {
        parent::setUp();

        if (!interface_exists(SharedCacheContributorInterface::class)) {
            self::markTestSkipped('The core of this shop predates the shared cache of the public pages.');
        }

        $this->purger = new class implements SharedCachePurgerInterface {
            /** @var list<list<string>> */
            public array $calls = [];

            public function purge(array $keys): void
            {
                $this->calls[] = $keys;
            }
        };
        static::getContainer()->set(SharedCachePurgerInterface::class, $this->purger);
        $this->collector()->reset();
        $this->getService(ScheduledChanges::class)->forget();
    }

    protected function tearDown(): void
    {
        $this->getService(ScheduledChanges::class)->forget();
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testThePagesOfTheModuleAreAViewTheCoreShares(): void
    {
        $request = Request::create('/');
        $request->attributes->set('_view', TheliaCMS::PAGE_VIEW);

        self::assertTrue($this->getService(SharedCachePolicy::class)->isTaggable($request, new Response()));
    }

    public function testAPageOfTheModuleIsFiledUnderItsTagsAndTheCoreWritesItsHeaders(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::ENABLED->value);
        $response = $this->respond(CacheTags::forPage(12));

        self::assertSame([CacheTags::SITE, CacheTags::MENUS, CacheTags::page(12)], $this->collector()->keys());
        self::assertFalse($response->headers->has('Cache-Tag'));
        self::assertNotSame('private, no-store', $response->headers->get('Cache-Control'));
    }

    public function testAnyOtherPageIsFiledUnderTheMenusAndTheSettingsOfTheModule(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::ANNOUNCE->value);

        $this->respond(null);

        self::assertSame([CacheTags::SITE, CacheTags::MENUS], $this->collector()->keys());
    }

    public function testTheCoreSharesNoPageDrawnForTheOnePersonReadingIt(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::ENABLED->value);

        self::assertTrue($this->respondThroughTheKernel(CacheTags::forPage(12))->headers->hasCacheControlDirective('public'), 'The page alone is shared: the test proves nothing otherwise.');

        $response = $this->respondThroughTheKernel(CacheTags::forPage(12), personal: true);

        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testTheModuleKeepsItsOwnHeadersWhileTheShopDoesNotShare(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::DISABLED->value);

        $response = $this->respond(CacheTags::forPage(12));

        self::assertSame([], $this->collector()->keys());
        self::assertSame(implode(',', CacheTags::forPage(12)), $response->headers->get('Cache-Tag'));
    }

    /**
     * The core shares a page for its own TTL and knows nothing of the dates of
     * the module: a page leaving in a minute would stay in the proxy for the
     * whole TTL.
     */
    public function testTheCoreSharesAPageNoLongerThanUntilTheNextChange(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::ENABLED->value);
        ConfigQuery::write(SharedCachePolicy::TTL_CONFIG_KEY, '300');
        $page = $this->createPage('Page qui part dans une minute');
        $page->setUnpublishAt($this->secondsFromNow(60))->save();

        $response = $this->respondThroughTheKernel(CacheTags::forPage((int) $page->getId()));

        $sharedMaxAge = $response->headers->getCacheControlDirective('s-maxage');
        self::assertNotNull($sharedMaxAge, 'The core did not share the page: the test proves nothing.');
        self::assertLessThanOrEqual(60, (int) $sharedMaxAge);
        self::assertGreaterThan(40, (int) $sharedMaxAge);
    }

    public function testTheTtlAnnouncedToTheProxyStopsAtTheNextChangeToo(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::ANNOUNCE->value);
        ConfigQuery::write(SharedCachePolicy::TTL_CONFIG_KEY, '300');
        $this->createPage('Page qui arrive dans une minute')->setPublishAt($this->secondsFromNow(60))->save();

        $response = $this->respondThroughTheKernel(CacheTags::forPage(12));

        $announced = $response->headers->get('Surrogate-Control');
        self::assertNotNull($announced, 'The core announced nothing: the test proves nothing.');
        self::assertMatchesRegularExpression('/^max-age=(\d+)$/', $announced);
        self::assertLessThanOrEqual(60, (int) substr($announced, \strlen('max-age=')));
    }

    /**
     * Only lowered: a TTL the shop chose shorter than the time left stays.
     */
    public function testAShorterTtlOfTheCoreIsKept(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::ENABLED->value);
        ConfigQuery::write(SharedCachePolicy::TTL_CONFIG_KEY, '30');
        $this->createPage('Page qui part dans une heure')->setUnpublishAt($this->secondsFromNow(3600))->save();

        $response = $this->respondThroughTheKernel(CacheTags::forPage(12));

        self::assertSame('30', (string) $response->headers->getCacheControlDirective('s-maxage'));
    }

    public function testTheCoreSharesNothingWhileTheSiteIsUnderMaintenance(): void
    {
        ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::ENABLED->value);
        $maintenance = TheliaCMS::getConfigValue(CmsSettings::MAINTENANCE_ACTIVE, '0');
        TheliaCMS::setConfigValue(CmsSettings::MAINTENANCE_ACTIVE, '1');

        try {
            $response = $this->respondThroughTheKernel(CacheTags::forPage(12));
        } finally {
            TheliaCMS::setConfigValue(CmsSettings::MAINTENANCE_ACTIVE, (string) $maintenance);
        }

        self::assertFalse($response->headers->hasCacheControlDirective('s-maxage'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testAPurgeOfTheModuleReachesTheProxyOfTheCore(): void
    {
        $this->getService(CachePurger::class)->purge([CacheTags::page(12)]);
        $this->getService(SharedCacheInvalidationListener::class)->flush();

        self::assertSame([[CacheTags::page(12)]], $this->purger->calls);
    }

    /**
     * @param list<string>|null $tags
     */
    private function respond(?array $tags): Response
    {
        $request = Request::create('/');
        if (null !== $tags) {
            $request->attributes->set(PageCacheListener::TAGS_ATTRIBUTE, $tags);
        }
        $response = new Response('<html></html>');

        $this->getService(PageCacheListener::class)->onKernelResponse(
            new ResponseEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
        );

        return $response;
    }

    /**
     * Every listener of the response, the core's and the module's, in the
     * order of their priorities: which one writes last is what is tested.
     *
     * @param list<string> $tags
     */
    private function respondThroughTheKernel(array $tags, bool $personal = false): Response
    {
        $request = Request::create('/');
        $request->attributes->set('_view', TheliaCMS::PAGE_VIEW);
        $request->attributes->set(PageCacheListener::TAGS_ATTRIBUTE, $tags);
        if ($personal) {
            $request->attributes->set(PageCacheListener::PERSONAL_ATTRIBUTE, true);
        }
        $response = new Response('<html></html>');

        $this->getService(EventDispatcherInterface::class)->dispatch(
            new ResponseEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
            KernelEvents::RESPONSE,
        );

        return $response;
    }

    /**
     * To the second: the column keeps no fraction, and MySQL rounds one where
     * MariaDB cuts it.
     */
    private function secondsFromNow(int $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable(date('Y-m-d H:i:s', time() + $seconds));
    }

    private function collector(): SharedCacheKeyCollector
    {
        return $this->getService(SharedCacheKeyCollector::class);
    }
}
