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

namespace TheliaCMS\Tests\Integration\Form;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\SharedCache\SharedCacheContributorInterface;
use Thelia\Core\SharedCache\SharedCacheKeyCollector;
use Thelia\Core\SharedCache\SharedCacheMode;
use Thelia\Core\SharedCache\SharedCachePolicy;
use Thelia\Model\ConfigQuery;
use TheliaCMS\Form\Admin\CmsFormWriter;
use TheliaCMS\Form\Admin\FieldData;
use TheliaCMS\Form\Admin\FormSettings;
use TheliaCMS\Form\FieldType;
use TheliaCMS\Http\CacheTags;
use TheliaCMS\Http\PageCacheListener;
use TheliaCMS\Http\PageCachePolicy;
use TheliaCMS\Model\CmsForm;
use TheliaCMS\Partial\Definition\FormPartial;
use TheliaCMS\Partial\PartialRenderer;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;

/**
 * A page that shows a form is never kept by a shared cache, whoever caches.
 *
 * Each display of a form carries a stamp that carries one message: a copy
 * of the page served to a second visitor would hand them a stamp already
 * spent, and their message would be dropped as a replay.
 */
final class FormPageCacheTest extends CmsIntegrationTestCase
{
    private string $moduleTtl = '0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleTtl = (string) TheliaCMS::getConfigValue(PageCachePolicy::TTL_SETTING, '0');
        TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, (string) PageCachePolicy::MAX_TTL);
        $this->shareWithTheCore(SharedCacheMode::DISABLED);
    }

    protected function tearDown(): void
    {
        TheliaCMS::setConfigValue(PageCachePolicy::TTL_SETTING, $this->moduleTtl);
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    public function testShowingAFormFilesThePageUnderIt(): void
    {
        $form = $this->createForm();
        $requests = $this->getService(RequestStack::class);
        $pushed = null === $requests->getMainRequest();

        if ($pushed) {
            $requests->push(Request::create('/contact'));
        }

        $request = $requests->getMainRequest();
        self::assertInstanceOf(Request::class, $request);
        $request->attributes->remove(PageCacheListener::SHOWN_TAGS_ATTRIBUTE);

        try {
            $html = $this->getService(PartialRenderer::class)->substitute(
                \sprintf('<div data-cms-partial="%s" data-props="{&quot;form&quot;:%d}"></div>', FormPartial::NAME, $form->getId()),
                $this->locale(),
            );
        } finally {
            if ($pushed) {
                $requests->pop();
            }
        }

        self::assertStringContainsString('name="form_stamp"', (string) $html, 'The form was not rendered: the test proves nothing.');
        self::assertContains(CacheTags::partial(FormPartial::NAME), $request->attributes->get(PageCacheListener::SHOWN_TAGS_ATTRIBUTE));
    }

    public function testThePagesOfTheModuleAreSharedWhenTheyShowNoForm(): void
    {
        $response = $this->respond(CacheTags::forPage(12), []);

        self::assertSame((string) PageCachePolicy::MAX_TTL, (string) $response->headers->getCacheControlDirective('s-maxage'));
    }

    public function testAPageOfTheModuleThatShowsAFormIsNotShared(): void
    {
        $response = $this->respond(CacheTags::forPage(12), [CacheTags::partial(FormPartial::NAME)]);

        self::assertFalse($response->headers->hasCacheControlDirective('s-maxage'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertStringContainsString(CacheTags::partial(FormPartial::NAME), (string) $response->headers->get('Cache-Tag'), 'The tags are still written.');
    }

    /**
     * A product page shows the content of the module, a form included, through
     * a hook of the theme: the page is the shop's, the form is not.
     */
    public function testAPageOfTheShopThatShowsAFormIsNotSharedEither(): void
    {
        $response = $this->respond(null, [CacheTags::partial(FormPartial::NAME)]);

        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('Cache-Tag'), 'The headers of the shop stay the shop\'s.');
    }

    public function testTheCoreDoesNotShareAPageThatShowsAForm(): void
    {
        $this->requireTheSharedCacheOfTheCore();
        $this->shareWithTheCore(SharedCacheMode::ENABLED);

        $plain = $this->respondThroughTheKernel([]);
        $withAForm = $this->respondThroughTheKernel([CacheTags::partial(FormPartial::NAME)]);

        self::assertTrue($plain->headers->hasCacheControlDirective('s-maxage'), 'The core shared no page at all: the test proves nothing.');
        self::assertFalse($withAForm->headers->hasCacheControlDirective('s-maxage'));
        self::assertFalse($withAForm->headers->hasCacheControlDirective('public'));
        self::assertTrue($withAForm->headers->hasCacheControlDirective('no-store'));
    }

    public function testTheCoreAnnouncesNoLifetimeForAPageThatShowsAForm(): void
    {
        $this->requireTheSharedCacheOfTheCore();
        $this->shareWithTheCore(SharedCacheMode::ANNOUNCE);

        $plain = $this->respondThroughTheKernel([]);
        $withAForm = $this->respondThroughTheKernel([CacheTags::partial(FormPartial::NAME)]);

        self::assertTrue($plain->headers->has('Surrogate-Control'), 'The core announced nothing at all: the test proves nothing.');
        self::assertFalse($withAForm->headers->has('Surrogate-Control'));
    }

    private function createForm(): CmsForm
    {
        $form = new CmsForm();

        $this->getService(CmsFormWriter::class)->save($form, $this->locale(), new FormSettings(
            code: 'contact-test-cache',
            active: true,
            recipients: '',
            storeSubmissions: true,
            retentionDays: 365,
            sendReceipt: false,
            privacyPolicyPageId: null,
            leadEvent: false,
            title: 'Contact',
            description: '',
            submitLabel: '',
            successMessage: '',
            legalNotice: '',
            receiptSubject: '',
            receiptBody: '',
        ));
        // A form with no field to fill in is not shown at all.
        $this->getService(CmsFormWriter::class)->saveField($form, null, $this->locale(), new FieldData(FieldType::Email, 'email', 'Email', true, '', '', '', 0));

        return $form;
    }

    private function shareWithTheCore(SharedCacheMode $mode): void
    {
        if (class_exists(SharedCacheMode::class)) {
            ConfigQuery::write(SharedCacheMode::CONFIG_KEY, $mode->value);
        }
    }

    private function requireTheSharedCacheOfTheCore(): void
    {
        if (!interface_exists(SharedCacheContributorInterface::class)) {
            self::markTestSkipped('The core of this shop predates the shared cache of the public pages.');
        }

        ConfigQuery::write(SharedCachePolicy::TTL_CONFIG_KEY, '300');
        $this->getService(SharedCacheKeyCollector::class)->reset();
    }

    /**
     * @param list<string>|null $tags
     * @param list<string>      $shown
     */
    private function respond(?array $tags, array $shown): Response
    {
        $request = Request::create('/a-page');
        if (null !== $tags) {
            $request->attributes->set(PageCacheListener::TAGS_ATTRIBUTE, $tags);
        }
        $request->attributes->set(PageCacheListener::SHOWN_TAGS_ATTRIBUTE, $shown);
        $response = new Response('<html></html>');

        $this->getService(PageCacheListener::class)->onKernelResponse(
            new ResponseEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
        );

        return $response;
    }

    /**
     * Every listener of `kernel.response`, the one of the core that shares a
     * page included.
     *
     * @param list<string> $shown
     */
    private function respondThroughTheKernel(array $shown): Response
    {
        $request = Request::create('/');
        $request->attributes->set('_view', TheliaCMS::PAGE_VIEW);
        $request->attributes->set(PageCacheListener::TAGS_ATTRIBUTE, CacheTags::forPage(12));
        $request->attributes->set(PageCacheListener::SHOWN_TAGS_ATTRIBUTE, $shown);
        $response = new Response('<html></html>');

        $this->getService(EventDispatcherInterface::class)->dispatch(
            new ResponseEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response),
            KernelEvents::RESPONSE,
        );

        return $response;
    }
}
