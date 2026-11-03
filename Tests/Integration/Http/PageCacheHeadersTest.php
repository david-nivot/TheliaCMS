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

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Core\SharedCache\SharedCacheMode;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Http\PageCacheListener;
use TheliaCMS\Http\PageCachePolicy;
use TheliaCMS\Page\ScheduledChanges;
use TheliaCMS\Partial\Definition\PageListPartial;
use TheliaCMS\Settings\CmsSettings;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Twig\CmsExtension;

/**
 * The headers the module writes on its pages while the shop does not share
 * them itself: what a page is filed under, and what a cache keys it on.
 */
final class PageCacheHeadersTest extends CmsIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(SharedCacheMode::class)) {
            ConfigQuery::write(SharedCacheMode::CONFIG_KEY, SharedCacheMode::DISABLED->value);
        }

        $this->getService(ScheduledChanges::class)->forget();
    }

    protected function tearDown(): void
    {
        $this->getService(ScheduledChanges::class)->forget();
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testAPageOfTheModuleIsFiledUnderTheListsItShows(): void
    {
        $response = $this->respond(CacheTags::forPage(12), [CacheTags::partial(PageListPartial::NAME)]);

        self::assertSame('cms,cms-menu,cms-page-12,cms-partial-cms-page-list', $response->headers->get('Cache-Tag'));
    }

    /**
     * A page of the shop may show a list of pages through a template: it is
     * not the module's to decide whether that page may be shared.
     */
    public function testAPageOfTheShopThatShowsAListKeepsTheHeadersOfTheShop(): void
    {
        $response = $this->respond(null, [CacheTags::partial(PageListPartial::NAME)]);

        self::assertFalse($response->headers->has('Cache-Tag'));
        self::assertFalse($response->headers->has('Surrogate-Key'));
    }

    /**
     * The language of a page is in its address, its domain or its `lang`
     * parameter, never in the `Accept-Language` of the browser: varying on it
     * only keeps one copy of the same page per browser language.
     */
    public function testASharedPageDoesNotVaryOnTheLanguageOfTheBrowser(): void
    {
        $ttl = TheliaCMS::getConfigValue(PageCachePolicy::TTL_SETTING, '0');
        TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, '600');

        try {
            $response = $this->respond(CacheTags::forPage(12));
        } finally {
            TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, (string) $ttl);
        }

        self::assertSame('600', (string) $response->headers->getCacheControlDirective('s-maxage'), 'The page was not shared: the test proves nothing.');
        self::assertNotContains('Accept-Language', $response->getVary());
    }

    /**
     * A proxy keeps a page until its `s-maxage` runs out, whatever happens to
     * the page meanwhile: a page leaving in two minutes is kept two minutes.
     */
    public function testAPageIsSharedNoLongerThanUntilItGoesOffline(): void
    {
        $page = $this->createPage('Page qui part bientôt');
        $page->setUnpublishAt($this->secondsFromNow(120))->save();

        $sharedMaxAge = $this->sharedMaxAgeWithATtlOf(600, CacheTags::forPage((int) $page->getId()));

        self::assertNotNull($sharedMaxAge, 'The page was not shared: the test proves nothing.');
        self::assertLessThanOrEqual(120, $sharedMaxAge);
        self::assertGreaterThan(100, $sharedMaxAge);
    }

    /**
     * The menus, the lists and the breadcrumb of a page show only the pages
     * online: any page of the site that arrives or leaves changes it.
     */
    public function testAPageIsSharedNoLongerThanUntilAnotherPageArrives(): void
    {
        $this->createPage('Page qui arrive bientôt')->setPublishAt($this->secondsFromNow(120))->save();

        $sharedMaxAge = $this->sharedMaxAgeWithATtlOf(600, CacheTags::forPage(12));

        self::assertNotNull($sharedMaxAge, 'The page was not shared: the test proves nothing.');
        self::assertLessThanOrEqual(120, $sharedMaxAge);
    }

    /**
     * An address on the allow list sees the real site without a session: a
     * proxy would keep that page and serve it to everyone, past the closed
     * site. A page of the shop too.
     *
     * @param list<string>|null $tags
     */
    #[DataProvider('pagesServedDuringMaintenance')]
    public function testNothingIsSharedWhileTheSiteIsUnderMaintenance(?array $tags): void
    {
        $ttl = TheliaCMS::getConfigValue(PageCachePolicy::TTL_SETTING, '0');
        $maintenance = TheliaCMS::getConfigValue(CmsSettings::MAINTENANCE_ACTIVE, '0');
        TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, '600');
        TheliaCMS::setConfigValue(CmsSettings::MAINTENANCE_ACTIVE, '1');

        try {
            $response = $this->respond($tags);
        } finally {
            TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, (string) $ttl);
            TheliaCMS::setConfigValue(CmsSettings::MAINTENANCE_ACTIVE, (string) $maintenance);
        }

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('s-maxage'));
    }

    /**
     * @return iterable<string, array{list<string>|null}>
     */
    public static function pagesServedDuringMaintenance(): iterable
    {
        yield 'a page of the module' => [CacheTags::forPage(12)];
        yield 'a page of the shop' => [null];
    }

    public function testAListReadFromATemplateFilesThePageUnderIt(): void
    {
        // The kernel of the suite may already hold a main request: the tags
        // go to that one, as they would to the page being served.
        $requests = $this->getService(RequestStack::class);
        $pushed = null === $requests->getMainRequest();

        if ($pushed) {
            $requests->push(Request::create('/recettes'));
        }

        $request = $requests->getMainRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->attributes->remove(PageCacheListener::SHOWN_TAGS_ATTRIBUTE);

        try {
            $this->getService(CmsExtension::class)->pagesOfType('default');
        } finally {
            if ($pushed) {
                $requests->pop();
            }
        }

        self::assertSame([CacheTags::partial(PageListPartial::NAME)], $request->attributes->get(PageCacheListener::SHOWN_TAGS_ATTRIBUTE));
    }

    /**
     * @param list<string> $tags
     */
    private function sharedMaxAgeWithATtlOf(int $seconds, array $tags): ?int
    {
        $ttl = TheliaCMS::getConfigValue(PageCachePolicy::TTL_SETTING, '0');
        TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, (string) $seconds);

        try {
            $response = $this->respond($tags);
        } finally {
            TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, (string) $ttl);
        }

        $sharedMaxAge = $response->headers->getCacheControlDirective('s-maxage');

        return null === $sharedMaxAge ? null : (int) $sharedMaxAge;
    }

    /**
     * To the second: the column keeps no fraction, and MySQL rounds one where
     * MariaDB cuts it.
     */
    private function secondsFromNow(int $seconds): \DateTimeImmutable
    {
        return new \DateTimeImmutable(date('Y-m-d H:i:s', time() + $seconds));
    }

    /**
     * @param list<string>|null $tags
     * @param list<string>      $shown
     */
    private function respond(?array $tags, array $shown = []): Response
    {
        $request = Request::create('/a-page');
        if (null !== $tags) {
            $request->attributes->set(PageCacheListener::TAGS_ATTRIBUTE, $tags);
        }
        if ([] !== $shown) {
            $request->attributes->set(PageCacheListener::SHOWN_TAGS_ATTRIBUTE, $shown);
        }
        $response = new Response('<html></html>');

        $this->getService(PageCacheListener::class)->onKernelResponse(
            new ResponseEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
        );

        return $response;
    }
}
