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
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Thelia\Model\ModuleConfigQuery;
use TheliaCMS\Consent\ConsentSettings;
use TheliaCMS\Http\ContentSecurityPolicyMode;
use TheliaCMS\Http\ContentSecurityPolicySettings;
use TheliaCMS\Page\CmsPageViewListener;
use TheliaCMS\Preview\CmsPagePreviewController;
use TheliaCMS\Preview\PreviewLink;
use TheliaCMS\Tests\Integration\CmsIntegrationTestCase;
use TheliaCMS\TheliaCMS;
use TheliaCMS\Vitrine\NotFoundPageListener;

/**
 * The policy goes on the pages the module renders, through the whole of
 * `kernel.response`, and nowhere else.
 */
final class ContentSecurityPolicyListenerTest extends CmsIntegrationTestCase
{
    private const array SETTINGS = [ContentSecurityPolicySettings::MODE, ContentSecurityPolicySettings::HOSTS, '404_page_id', ConsentSettings::CLIENT_ID];

    /** @var array<string, mixed> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::SETTINGS as $key) {
            $this->previous[$key] = TheliaCMS::getConfigValue($key);
        }

        $this->useAnInstalledFrontTheme();
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            null === $value ? $this->forget($key) : TheliaCMS::setConfigValue($key, $value);
        }

        parent::tearDown();
    }

    public function testAPageOfTheModuleIsSentThePolicyItReports(): void
    {
        $this->policy()->save(ContentSecurityPolicyMode::ReportOnly, "https://fonts.gstatic.com\nhttps://pixel.example.com");
        $page = $this->createPage('Politique', html: '<p>Une page</p>');

        $request = Request::create('/politique');
        $request->attributes->set('_view', TheliaCMS::PAGE_VIEW);
        $request->query->set(TheliaCMS::PAGE_VIEW.'_id', (string) $page->getId());
        $event = new ViewEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, null);
        $this->getService(CmsPageViewListener::class)->onKernelView($event);

        $response = $this->respond($request, $event->getResponse());

        self::assertFalse($response->headers->has('Content-Security-Policy'), 'Report only blocks nothing.');
        self::assertSame(
            "img-src 'self' data: https://fonts.gstatic.com https://pixel.example.com; font-src 'self' data: https://fonts.gstatic.com https://pixel.example.com; frame-ancestors 'self'; report-uri ".$this->router()->generate('cms.csp_report'),
            $response->headers->get('Content-Security-Policy-Report-Only'),
        );
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'The core still sends its own headers.');
    }

    public function testThePageAnAddressNotFoundShowsIsSentIt(): void
    {
        $this->policy()->save(ContentSecurityPolicyMode::Enforce, '');
        $notFound = $this->createPage('Introuvable', html: '<h1>Introuvable</h1>');
        TheliaCMS::setConfigValue('404_page_id', (string) $notFound->getId());

        $request = Request::create('/une-adresse-qui-nexiste-pas');
        $event = new ExceptionEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, new NotFoundHttpException());
        $this->getService(NotFoundPageListener::class)->onKernelException($event);

        $response = $this->respond($request, $event->getResponse());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame("img-src 'self' data:; font-src 'self' data:; frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function testThePreviewOfADraftIsSentIt(): void
    {
        $this->policy()->save(ContentSecurityPolicyMode::Enforce, '');
        $page = $this->createPage('Brouillon', html: '<p>Un brouillon</p>');

        $path = (string) parse_url($this->getService(PreviewLink::class)->urlFor((int) $page->getId(), $this->locale()), \PHP_URL_PATH);
        $parameters = $this->router()->match($path);
        $request = Request::create($path);
        $response = $this->getService(CmsPagePreviewController::class)->__invoke($request, (int) $parameters['id'], (string) $parameters['locale'], (int) $parameters['expires'], (string) $parameters['signature']);

        $response = $this->respond($request, $response);

        self::assertStringStartsWith("img-src 'self' data:", (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function testAPageOfTheShopIsLeftAlone(): void
    {
        $this->policy()->save(ContentSecurityPolicyMode::Enforce, '');

        $response = $this->respond(Request::create('/cart'), new Response('<html></html>'));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testADocumentKeepsItsSandbox(): void
    {
        $this->policy()->save(ContentSecurityPolicyMode::Enforce, '');
        $document = new Response('%PDF-1.4');
        $document->headers->set('Content-Security-Policy', 'sandbox');

        $response = $this->respond(Request::create('/cms/documents/1/x.pdf'), $document);

        self::assertSame('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function testAPolicyAlreadySetIsKept(): void
    {
        $this->policy()->save(ContentSecurityPolicyMode::Enforce, '');
        $request = Request::create('/politique');
        $request->attributes->set('_cms_content_security_policy', true);
        $response = new Response('<html></html>');
        $response->headers->set('Content-Security-Policy', "default-src 'self'");

        self::assertSame("default-src 'self'", $this->respond($request, $response)->headers->get('Content-Security-Policy'));
    }

    public function testNothingIsSentUntilItIsTurnedOn(): void
    {
        $this->forget(ContentSecurityPolicySettings::MODE);
        $request = Request::create('/politique');
        $request->attributes->set('_cms_content_security_policy', true);

        $response = $this->respond($request, new Response('<html></html>'));

        self::assertFalse($response->headers->has('Content-Security-Policy'));
        self::assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function testTheHostsNeverSavedAreThoseTheSiteIsKnownToNeed(): void
    {
        $this->forget(ContentSecurityPolicySettings::HOSTS);
        TheliaCMS::setConfigValue(ConsentSettings::CLIENT_ID, '');

        self::assertSame([ContentSecurityPolicySettings::GOOGLE_FONTS], $this->policy()->hosts());

        TheliaCMS::setConfigValue(ConsentSettings::CLIENT_ID, 'a1b2c3');

        self::assertSame([ContentSecurityPolicySettings::GOOGLE_FONTS, ContentSecurityPolicySettings::AXEPTIO], $this->policy()->hosts());

        $this->policy()->save(ContentSecurityPolicyMode::ReportOnly, '');

        self::assertSame([], $this->policy()->hosts(), 'A list emptied on purpose stays empty.');
    }

    private function respond(Request $request, ?Response $response): Response
    {
        self::assertNotNull($response, 'The module answered.');

        $this->getService(EventDispatcherInterface::class)->dispatch(
            new ResponseEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST, $response),
            KernelEvents::RESPONSE,
        );

        return $response;
    }

    /** As on a site where the setting was never saved. */
    private function forget(string $key): void
    {
        ModuleConfigQuery::create()->deleteConfigValue((int) TheliaCMS::getModuleId(), $key);
    }

    private function policy(): ContentSecurityPolicySettings
    {
        return $this->getService(ContentSecurityPolicySettings::class);
    }

    private function router(): RouterInterface
    {
        return $this->getService(RouterInterface::class);
    }

    private function kernel(): KernelInterface
    {
        return $this->getService(KernelInterface::class);
    }
}
